<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceNote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Services\GcsUploaderService;

class InvoiceImportService
{
    public function importFromXml(string $xmlContent, string $originalFileName): ?Invoice
    {
        $xml = simplexml_load_string($xmlContent);

        // Limpiar y normalizar contenido del comprobante (etiqueta <comprobante>)
        $comprobanteXml = (string) $xml->comprobante;
        $comprobanteXml = trim($comprobanteXml);

        // Eliminar BOM si existe (Byte Order Mark)
        $comprobanteXml = preg_replace('/^\xEF\xBB\xBF/', '', $comprobanteXml);

        // Convertir a UTF-8 si no lo está
        if (!mb_check_encoding($comprobanteXml, 'UTF-8')) {
            $comprobanteXml = mb_convert_encoding($comprobanteXml, 'UTF-8', 'auto');
        }

        if (empty($comprobanteXml)) {
            throw new \Exception("El archivo no contiene contenido válido en la etiqueta <comprobante>.");
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (!$dom->loadXML($comprobanteXml)) {
            $error = libxml_get_last_error();
            libxml_clear_errors();
            throw new \Exception("XML malformado: " . ($error ? trim($error->message) : 'Error desconocido al cargar el XML.'));
        }

        $factura = simplexml_import_dom($dom);


        $infoTrib = $factura->infoTributaria;
        $infoFact = $factura->infoFactura;

        $claveAcceso = (string) $infoTrib->claveAcceso;

        // Verificar duplicado por clave de acceso
        if (Invoice::where('clave_acceso', $claveAcceso)->exists()) {
            Log::info("Factura duplicada ignorada: $claveAcceso");
            return null;
        }

        $fechaEmision = Carbon::createFromFormat('d/m/Y', (string) $infoFact->fechaEmision);
        $mes = (int) $fechaEmision->format('m');

        // Determinar empresa por RUC
        $rucEmisor = (string) $infoTrib->ruc;
        $empresa = match ($infoTrib->identificacionComprador) {
            '0992301066001' => 'PREBAM',
            '1792162696001' => 'SERSUPPORT',
            default => 'DESCONOCIDA',
        };

        // Guardar archivo en Google Cloud Storage (GCS)
        $path = "$empresa/{$fechaEmision->year}/{$mes}/xml/$originalFileName";

        $gcs = new GcsUploaderService();
        $url = $gcs->upload($path, $xmlContent); // Devuelve URL

        $invoice = Invoice::create([
            'clave_acceso' => $claveAcceso,
            'ruc_emisor' => $rucEmisor,
            'razon_social_emisor' => (string) $infoTrib->razonSocial,
            'nombre_comercial_emisor' => (string) $infoTrib->nombreComercial,
            'razon_social_comprador' => (string) $infoFact->razonSocialComprador,
            'identificacion_comprador' => (string) $infoFact->identificacionComprador,
            'direccion_comprador' => (string) $infoFact->direccionComprador,

            'estab' => (string) $infoTrib->estab,
            'pto_emi' => (string) $infoTrib->ptoEmi,
            'secuencial' => (string) $infoTrib->secuencial,
            'invoice_serial' => (string) $infoTrib->estab . '-' . $infoTrib->ptoEmi . '-' . $infoTrib->secuencial,
            'ambiente' => (string) $xml->ambiente,
            'fecha_emision' => $fechaEmision,
            'fecha_autorizacion' => Carbon::parse((string) $xml->fechaAutorizacion),

            'total_sin_impuestos' => (float) $infoFact->totalSinImpuestos,
            'importe_total' => (float) $infoFact->importeTotal,
            'iva' => $this->getIvaValue($infoFact),
            'propina' => (float) $infoFact->propina ?? 0,
            'moneda' => (string) $infoFact->moneda ?? 'USD',
            'forma_pago' => (string) $infoFact->pagos->pago->formaPago ?? null,
            'placa' => (string) $infoFact->placa ?? null,

            'empresa' => $empresa,
            'mes' => $mes,
            'xml_path' => $url,
        ]);

        // Guardar detalles (campoAdicional)
        if (!empty($factura->infoAdicional->campoAdicional)) {
            foreach ($factura->infoAdicional->campoAdicional as $campo) {
                InvoiceNote::create([
                    'invoice_id' => $invoice->id,
                    'name' => (string) $campo['nombre'],
                    'description' => (string) $campo,
                ]);
            }
        }

        return $invoice;
    }

    private function getIvaValue($infoFactura): ?float
    {
        foreach ($infoFactura->totalConImpuestos->totalImpuesto ?? [] as $imp) {
            if ((string) $imp->codigo == '2') { // IVA
                return (float) $imp->valor;
            }
        }
        return null;
    }
}
