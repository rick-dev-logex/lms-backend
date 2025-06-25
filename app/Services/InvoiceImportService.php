<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoiceNote;
use App\Models\SriRequest;
use DOMDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

class InvoiceImportService
{
    public function __construct(
        private SriAuthorizationService $sriAuth,
    ) {}


    protected function consultaSRI(string $claveAcceso): object
    {
        $wsdl = config('sri.wsdl');
        $opts = [
            'cache_wsdl'         => WSDL_CACHE_NONE,
            'exceptions'         => true,
            'connection_timeout' => 10,
            'stream_context'     => stream_context_create([
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ]),
        ];

        return retry(3, function () use ($wsdl, $opts, $claveAcceso) {
            $client = new \SoapClient($wsdl, $opts);
            return $client->verificarComprobante($claveAcceso, /* credenciales, etc. */);
        }, 100 /* milisegundos entre intentos */);
    }


    public function importFromTxt(string $txtLine, string $originalFileName, string $source, string $relativePath, string $line): ?Invoice
    {
        $parts       = str_getcsv(trim($txtLine), "\t");
        $claveAcceso = $parts[4] ?? null;
        $fechaAuth = $parts[5] ?? null;

        if (!$claveAcceso) {
            throw new \Exception("Clave de acceso no encontrada en la línea: “{$txtLine}”");
        }

        try {
            $xmlComprobante = $this->sriAuth->getComprobanteXml($claveAcceso);
            $xmlFileName    = pathinfo($originalFileName, PATHINFO_FILENAME) . '.xml';
            $invoice        = $this->importFromXml($xmlComprobante, $xmlFileName, $source, $fechaAuth, $relativePath, $txtLine);

            return $invoice;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    public function importFromXml(string $xmlContent, string $originalFileName, string $source, ?string $fechaAuthStr = null, ?string $relativePath = null, ?string $txtLine = null): ?Invoice
    {
        // 1) Forzar errores internos de libxml
        libxml_use_internal_errors(true);

        // 2) Limpiar BOM y declaración XML
        $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', $xmlContent));
        $raw = preg_replace('/<\?xml.*?\?>\s*/', '', $raw);

        // 3) Extraer solamente el bloque <factura>…</factura>
        if (! preg_match('/<factura\b[^>]*>[\s\S]*?<\/factura>/i', $raw, $m)) {
            throw new Exception("No se encontró la etiqueta <factura> completa en el XML.");
        }
        $facturaXml = $m[0];

        // 4) Asegurar UTF-8
        if (! mb_check_encoding($facturaXml, 'UTF-8')) {
            $facturaXml = mb_convert_encoding($facturaXml, 'UTF-8', 'auto');
        }

        // 5) Parsear con DOMDocument → SimpleXML
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (! $dom->loadXML($facturaXml)) {
            $err = libxml_get_last_error();
            libxml_clear_errors();
            throw new Exception("XML malformado al cargar factura: " . ($err ? trim($err->message) : 'desconocido'));
        }
        $factura = simplexml_import_dom($dom);

        // 5. Datos básicos
        $infoTrib = $factura->infoTributaria;
        $infoFact = $factura->infoFactura;
        $claveAcceso = (string) $infoTrib->claveAcceso;
        $rucEmisor   = (string) $infoTrib->ruc;
        // $autorizacion = (string) ($xmlObj->autorizacion ?? '');

        // Fecha de emisión y empresa
        $fechaEmision = Carbon::createFromFormat('d/m/Y', (string) $infoFact->fechaEmision);
        $mes           = (int) $fechaEmision->format('m');
        $empresa       = (string) $infoFact->razonSocialComprador;

        // 6. Datos de proveedor en sistema externo
        $connection       = $infoFact->identificacionComprador === '0992301066001'
            ? 'latinium_prebam'
            : 'latinium_sersupport';

        $cliente          = DB::connection($connection)
            ->table('Cliente')
            ->select('idCliente', 'Nombre')
            ->where('Ruc', 'like', '%' . $rucEmisor . '%')
            ->first();
        $idCliente        = $cliente->idCliente ?? null;
        $nombreProveedor  = $cliente?->Nombre ?? null;

        if (empty($nombreProveedor)) {
            $otraConn = $connection === 'latinium_prebam' ? 'latinium_sersupport' : 'latinium_prebam';

            Log::info('Proveedor no encontrado en ' . $connection . '. Tratando en ' . $otraConn . '.');

            $otro     = DB::connection($otraConn)
                ->table('Cliente')
                ->select('idCliente', 'Nombre')
                ->whereRaw('LTRIM(RTRIM(Ruc)) = ?', [$rucEmisor])
                ->first();
            $idCliente       = $otro->idCliente ?? $idCliente;
            $nombreProveedor = $otro?->Nombre ?? null;

            if ($nombreProveedor) {
                Log::info('Proveedor encontrado en ' . $otraConn);
            } else {
                Log::info('No se pudo encontrar el proveedor en ninguna de las bases.');
            }
        }

        $isContabilizado  = $idCliente
            ? DB::connection($connection)
            ->table('Compra')
            ->where('idCliente', $idCliente)
            ->where('AutFactura', $claveAcceso)
            ->exists()
            : false;
        $estadoContable = $isContabilizado ? 'CONTABILIZADO' : 'PENDIENTE';

        // 8. Crear la factura en nuestra BD
        $invoice = Invoice::create([
            'clave_acceso'                => $claveAcceso,
            'ruc_emisor'                  => $rucEmisor,
            'razon_social_emisor'         => (string) $infoTrib->razonSocial,
            'nombre_comercial_emisor'     => (string) ($infoTrib->nombreComercial ?? null),
            'identificacion_comprador'    => (string) $infoFact->identificacionComprador,
            'tipo_identificacion_comprador' => (string) ($infoFact->tipoIdentificacionComprador ?? null),
            'razon_social_comprador'      => (string) $infoFact->razonSocialComprador,
            'direccion_comprador'         => (string) ($infoFact->direccionComprador ?? null),
            'estab'                       => (string) $infoTrib->estab,
            'pto_emi'                     => (string) $infoTrib->ptoEmi,
            'secuencial'                  => (string) $infoTrib->secuencial,
            'invoice_serial'              => "{$infoTrib->estab}-{$infoTrib->ptoEmi}-{$infoTrib->secuencial}",
            'ambiente'                    => (string) $factura->infoTributaria->ambiente,
            'fecha_emision'               => $fechaEmision,
            'fecha_autorizacion'          => $fechaAuthStr,
            'cod_doc'                     => (string) ($infoTrib->codDoc ?? null),
            'tipo_emision'                => (string) $infoTrib->tipoEmision,
            'dir_matriz'                  => (string) $infoTrib->dirMatriz,
            'agente_retencion'            => (string) ($infoTrib->agenteRetencion ?? null),
            'dir_establecimiento'         => (string) $infoFact->dirEstablecimiento,
            'contribuyente_especial'      => (string) ($infoTrib->contribuyenteRimpe ?? null),
            'obligado_contabilidad'       => (string) $infoFact->obligadoContabilidad,
            'total_sin_impuestos'         => (float) $infoFact->totalSinImpuestos,
            'total_descuento'             => (float) ($infoFact->totalDescuento ?? 0),
            'codigo'                      => isset($infoFact->totalConImpuestos->totalImpuesto[0])
                ? (int) $infoFact->totalConImpuestos->totalImpuesto[0]->codigo
                : 0,
            'codigo_porcentaje'           => isset($infoFact->totalConImpuestos->totalImpuesto[0])
                ? (int) $infoFact->totalConImpuestos->totalImpuesto[0]->codigoPorcentaje
                : null,
            'descuento_adicional'         => 0.00,
            'base_imponible_factura'      => (float) $infoFact->totalSinImpuestos,
            'valor_factura'               => (float) $infoFact->importeTotal,
            'importe_total'               => (float) $infoFact->importeTotal,
            'iva'                         => $this->getIvaValue($infoFact),
            'propina'                     => (float) ($infoFact->propina ?? 0),
            'moneda'                      => (string) ($infoFact->moneda ?? null),
            'forma_pago'                  => (string) ($infoFact->pagos->pago->formaPago ?? null),
            'placa'                       => (string) ($infoFact->placa ?? null),
            'total'                       => (float) $infoFact->importeTotal,
            'plazo'                       => 0,
            'mes'                         => $mes,
            'project'                     => null,
            'centro_costo'                => null,
            'notas'                       => null,
            'observacion'                 => null,
            'contabilizado'               => $estadoContable,
            'tipo'                        => null,
            'proveedor_latinium'          => $nombreProveedor,
            'nota_latinium'               => null,
            'estado'                      => 'ingresada',
            'estado_latinium'             => 'pendiente',
            'numero_asiento'              => null,
            'numero_transferencia'        => null,
            'correo_pago'                 => null,
            'purchase_order_id'           => null,
            'empresa'                     => $empresa,
            'xml_path'                    => null,
            'pdf_path'                    => null,
        ]);

        // 8.1 Actualizar el estado contable de inmediato
        $latConn = $invoice->identificacion_comprador === '0992301066001' ? 'latinium_prebam' : 'latinium_sersupport';
        $existe = DB::connection($latConn)
            ->table('Compra')
            ->where('AutFactura', $invoice->clave_acceso)
            ->exists();

        // 8.2) Actualizar la factura **sin** disparar observers
        $invoice->updateQuietly([
            'contabilizado'      => $existe ? 'CONTABILIZADO' : 'PENDIENTE',
            'estado_latinium'    => $existe ? 'contabilizado' : 'pendiente',
        ]);

        // 9. Detalles de la factura
        foreach ($factura->detalles->detalle ?? [] as $d) {
            $data = [
                'invoice_id'                => $invoice->id,
                'codigo_principal'          => (string) $d->codigoPrincipal,
                'codigo_auxiliar'           => (string) ($d->codigoAuxiliar ?? null),
                'descripcion'               => (string) $d->descripcion,
                'cantidad'                  => (int) $d->cantidad,
                'precio_unitario'           => (float) $d->precioUnitario,
                'descuento'                 => (float) $d->descuento,
                'precio_total_sin_impuesto' => (float) $d->precioTotalSinImpuesto,
            ];
            if (isset($d->impuestos->impuesto)) {
                foreach ($d->impuestos->impuesto as $imp) {
                    $data['cod_impuesto']             = (string) $imp->codigo;
                    $data['cod_porcentaje']           = (string) $imp->codigoPorcentaje;
                    $data['tarifa']                   = (float) $imp->tarifa;
                    $data['base_imponible_impuestos'] = (float) $imp->baseImponible;
                    $data['valor_impuestos']          = (float) $imp->valor;
                }
            }
            InvoiceDetail::create($data);
        }

        // 10. Notas adicionales
        foreach ($factura->infoAdicional->campoAdicional ?? [] as $campo) {
            InvoiceNote::create([
                'invoice_id'  => $invoice->id,
                'name'        => (string) $campo['nombre'],
                'description' => (string) $campo,
            ]);
        }

        return $invoice;
    }

    private function getIvaValue($infoFactura): ?float
    {
        foreach ($infoFactura->totalConImpuestos->totalImpuesto ?? [] as $imp) {
            if ((string)$imp->codigo === '2') {
                return (float)$imp->valor;
            }
        }
        return null;
    }
}
