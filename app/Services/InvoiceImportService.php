<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoiceNote;
use App\Services\GcsUploaderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class InvoiceImportService
{
    public function importFromXml(string $xmlContent, string $originalFileName, string $source): ?Invoice
    {
        // —–––––— 1. Parsear y validar XML original —–––––—
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (! $xml) {
            throw new Exception("XML malformado o vacío.");
        }

        // —–––––— 1.2. Extraer CDATA de <comprobante> y limpiar cualquier junk antes del primer '<' —–––––—
        $raw = trim((string) $xml->comprobante);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $raw = preg_replace('/<\?xml.*?\?>\s*/is', '', $raw);

        // —–––––— 1.3. Parsear sólo el <factura>…</factura> limpio —–––––—
        if (! preg_match('/<factura\b.*<\/factura>/is', $raw, $m)) {
            throw new Exception("No se encontró la etiqueta <factura> completa dentro de <comprobante>.");
        }
        $comprobante = $m[0];
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (! $dom->loadXML($comprobante)) {
            $err = libxml_get_last_error();
            libxml_clear_errors();
            throw new Exception("XML malformado: " . ($err ? trim($err->message) : 'desconocido'));
        }
        $factura = simplexml_import_dom($dom);

        // ahora parseamos solamente el <factura>…</factura>
        if (! mb_check_encoding($comprobante, 'UTF-8')) {
            $comprobante = mb_convert_encoding($comprobante, 'UTF-8', 'auto');
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (! $dom->loadXML($comprobante)) {
            $err = libxml_get_last_error();
            libxml_clear_errors();
            throw new Exception("XML malformado: " . ($err ? trim($err->message) : 'desconocido'));
        }
        $factura = simplexml_import_dom($dom);

        // —–––––— 2. Datos básicos —–––––—
        $infoTrib     = $factura->infoTributaria;
        $infoFact     = $factura->infoFactura;
        $claveAcceso  = (string) $infoTrib->claveAcceso;
        $rucEmisor    = (string) $infoTrib->ruc;
        $autorizacion = (string) ($xml->autorizacion ?? '');

        if (Invoice::where('clave_acceso', $claveAcceso)->exists()) {
            Log::info("Factura duplicada ignorada: $claveAcceso");
            return null;
        }

        $fechaEmision = Carbon::createFromFormat('d/m/Y', (string)$infoFact->fechaEmision);
        $mes          = (int) $fechaEmision->format('m');

        $empresa    = $infoFact->razonSocialComprador;
        $connection = $infoFact->identificacionComprador === '0992301066001'
            ? 'latinium_prebam'
            : 'latinium_sersupport';

        // —–––––— 3. Subir XML a GCS —–––––—
        $path = "$empresa/{$fechaEmision->year}/{$mes}/xml/$originalFileName";
        $url  = (new GcsUploaderService())->upload($path, $xmlContent);

        // —–––––— 4. Info de proveedor externo —–––––—
        $cliente = DB::connection($connection)
            ->table('Cliente')
            ->select('idCliente', 'Nombre')
            ->where('Ruc', $rucEmisor)
            ->first();

        $idCliente       = $cliente->idCliente ?? null;
        $nombreProveedor = $cliente->Nombre    ?? null;

        $isContabilizado = $idCliente
            ? DB::connection($connection)
            ->table('Compra')
            ->where('idCliente', $idCliente)
            ->where('AutFactura', $claveAcceso)
            ->exists()
            : false;

        $estadoContable = $isContabilizado ? 'CONTABILIZADO' : 'PENDIENTE';

        // —–––––— 5. Crear Invoice en nuestra BD —–––––—
        $invoice = Invoice::create([
            // Identificación única
            'clave_acceso'                => $claveAcceso,

            // Emisor y Comprador
            'ruc_emisor'                  => $rucEmisor,
            'razon_social_emisor'         => (string)$infoTrib->razonSocial,
            'nombre_comercial_emisor'     => (string)($infoTrib->nombreComercial ?? null),
            'identificacion_comprador'    => (string)$infoFact->identificacionComprador,
            'tipo_identificacion_comprador' => (string)($infoFact->tipoIdentificacionComprador ?? null),
            'razon_social_comprador'      => (string)$infoFact->razonSocialComprador,
            'direccion_comprador'         => (string)($infoFact->direccionComprador ?? null),

            // Datos de factura
            'estab'                       => (string)$infoTrib->estab,
            'pto_emi'                     => (string)$infoTrib->ptoEmi,
            'secuencial'                  => (string)$infoTrib->secuencial,
            'invoice_serial'              => "{$infoTrib->estab}-{$infoTrib->ptoEmi}-{$infoTrib->secuencial}",
            'ambiente'                    => (string)$xml->ambiente,
            'fecha_emision'               => $fechaEmision,
            'fecha_autorizacion'          => Carbon::parse((string)$xml->fechaAutorizacion),
            'cod_doc'                     => (string)($infoTrib->codDoc ?? null),
            'tipo_emision'                => (string)$infoTrib->tipoEmision,
            'dir_matriz'                  => (string)$infoTrib->dirMatriz,
            'agente_retencion'            => (string)($infoTrib->agenteRetencion ?? null),
            'dir_establecimiento'         => (string)$infoFact->dirEstablecimiento,
            'contribuyente_especial'      => (string)($infoTrib->contribuyenteRimpe ?? null),
            'obligado_contabilidad'       => (string)$infoFact->obligadoContabilidad,
            'total_sin_impuestos'         => (float)$infoFact->totalSinImpuestos,
            'total_descuento'             => (float)($infoFact->totalDescuento ?? 0),
            'codigo'                      => isset($infoFact->totalConImpuestos->totalImpuesto[0])
                ? (int)$infoFact->totalConImpuestos->totalImpuesto[0]->codigo
                : 0,
            'codigo_porcentaje'           => isset($infoFact->totalConImpuestos->totalImpuesto[0])
                ? (int)$infoFact->totalConImpuestos->totalImpuesto[0]->codigoPorcentaje
                : null,
            'descuento_adicional'         => 0.00,
            'base_imponible_factura'      => (float)$infoFact->totalSinImpuestos,
            'valor_factura'               => (float)$infoFact->importeTotal,
            'importe_total'               => (float)$infoFact->importeTotal,
            'iva'                         => $this->getIvaValue($infoFact),
            'propina'                     => (float)($infoFact->propina ?? 0),
            'moneda'                      => (string)($infoFact->moneda ?? null),
            'forma_pago'                  => (string)($infoFact->pagos->pago->formaPago ?? null),
            'placa'                       => (string)($infoFact->placa ?? null),
            'total'                       => (float)$infoFact->importeTotal,
            'plazo'                       => 0,

            // Campos de flujo / edición
            'mes'                         => $mes,
            'project'                     => null,
            'centro_costo'                => null,
            'notas'                       => null,
            'observacion'                 => null,
            'contabilizado'               => $estadoContable,
            'tipo'                        => null,
            'proveedor_latinium'          => $nombreProveedor,
            'nota_latinium'               => null,

            // Estado y referencias contables
            'estado'                      => 'ingresada',
            'numero_asiento'              => null,
            'numero_transferencia'        => null,
            'correo_pago'                 => null,

            // Integración y almacenamiento
            'purchase_order_id'           => null,
            'empresa'                     => $empresa,
            'xml_path'                    => $url,
            'pdf_path'                    => null,
        ]);


        // —–––––— 6. Crear líneas de detalle —–––––—
        foreach ($factura->detalles->detalle ?? [] as $d) {
            $data = [
                'invoice_id'                 => $invoice->id,
                'codigo_principal'           => (string)$d->codigoPrincipal,
                'codigo_auxiliar'            => (string)($d->codigoAuxiliar ?? null),
                'descripcion'                => (string)$d->descripcion,
                'cantidad'                   => (int)$d->cantidad,
                'precio_unitario'            => (float)$d->precioUnitario,
                'descuento'                  => (float)$d->descuento,
                'precio_total_sin_impuesto'  => (float)$d->precioTotalSinImpuesto,
            ];

            // Si tiene impuestos, cogemos el primero (o puedes iterar para múltiples)
            if (isset($d->impuestos->impuesto)) {
                foreach ($d->impuestos->impuesto as $imp) {
                    $data['cod_impuesto']             = (string)$imp->codigo;
                    $data['cod_porcentaje']           = (string)$imp->codigoPorcentaje;
                    $data['tarifa']                   = (float)$imp->tarifa;
                    $data['base_imponible_impuestos'] = (float)$imp->baseImponible;
                    $data['valor_impuestos']          = (float)$imp->valor;
                }
            }

            InvoiceDetail::create($data);
        }

        // —–––––— 7. Notas adicionales —–––––—
        foreach ($factura->infoAdicional->campoAdicional ?? [] as $campo) {
            InvoiceNote::create([
                'invoice_id'  => $invoice->id,
                'name'        => (string)$campo['nombre'],
                'description' => (string)$campo,
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
