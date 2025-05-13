<?php

namespace App\Services\Sri;

use DOMDocument;

class SriXmlGeneratorService
{
    /**
     * Genera archivos XML para datos de SRI
     *
     * @param array $rows Datos para generar XMLs
     * @return array Arreglo con información de XMLs generados
     */
    public function generate(array $rows): array
    {
        $generated = [];

        foreach ($rows as $row) {
            $claveAcceso = $row['CLAVE_ACCESO'] ?? null;

            if (!$claveAcceso) {
                continue;
            }

            $nombreXml = "{$claveAcceso}.xml";
            $contenidoXml = $this->buildXml($row);

            $generated[] = [
                'clave_acceso' => $claveAcceso,
                'nombre_xml' => $nombreXml,
                'contenido' => $contenidoXml,
            ];
        }

        return $generated;
    }

    /**
     * Construye el contenido XML basado en los datos proporcionados
     *
     * @param array $row Datos para construir el XML
     * @return string Contenido XML generado
     */
    protected function buildXml(array $row): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $factura = $doc->createElement('factura');
        $factura->setAttribute('id', 'comprobante');
        $factura->setAttribute('version', '1.1.0');

        // infoTributaria
        $infoTributaria = $doc->createElement('infoTributaria');
        $infoTributaria->appendChild($doc->createElement('ambiente', '2'));
        $infoTributaria->appendChild($doc->createElement('tipoEmision', '1'));
        $infoTributaria->appendChild($doc->createElement('razonSocial', $row['RAZON_SOCIAL_EMISOR'] ?? 'N/A'));
        $infoTributaria->appendChild($doc->createElement('ruc', $row['RUC_EMISOR'] ?? ''));
        $infoTributaria->appendChild($doc->createElement('claveAcceso', $row['CLAVE_ACCESO']));
        $infoTributaria->appendChild($doc->createElement('codDoc', '01')); // Factura
        $serie = explode('-', $row['SERIE_COMPROBANTE'] ?? '000-000-000000000');
        $infoTributaria->appendChild($doc->createElement('estab', $serie[0] ?? '000'));
        $infoTributaria->appendChild($doc->createElement('ptoEmi', $serie[1] ?? '000'));
        $infoTributaria->appendChild($doc->createElement('secuencial', $serie[2] ?? '000000000'));
        $infoTributaria->appendChild($doc->createElement('dirMatriz', 'Guayaquil'));

        // infoFactura
        $infoFactura = $doc->createElement('infoFactura');
        $infoFactura->appendChild($doc->createElement('fechaEmision', $row['FECHA_EMISION'] ?? ''));
        $infoFactura->appendChild($doc->createElement('tipoIdentificacionComprador', '04')); // RUC
        $infoFactura->appendChild($doc->createElement('razonSocialComprador', 'PREBAM S.A.'));
        $infoFactura->appendChild($doc->createElement('identificacionComprador', $row['IDENTIFICACION_RECEPTOR'] ?? '0992301066001'));
        $infoFactura->appendChild($doc->createElement('totalSinImpuestos', $row['VALOR_SIN_IMPUESTOS'] ?? '0'));
        $infoFactura->appendChild($doc->createElement('importeTotal', $row['IMPORTE_TOTAL'] ?? '0'));
        $infoFactura->appendChild($doc->createElement('moneda', 'DOLAR'));

        // Total con impuestos (simulado)
        $totalConImpuestos = $doc->createElement('totalConImpuestos');
        $totalImpuesto = $doc->createElement('totalImpuesto');
        $totalImpuesto->appendChild($doc->createElement('codigo', '2'));
        $totalImpuesto->appendChild($doc->createElement('codigoPorcentaje', '2'));
        $totalImpuesto->appendChild($doc->createElement('baseImponible', $row['VALOR_SIN_IMPUESTOS'] ?? '0'));
        $totalImpuesto->appendChild($doc->createElement('valor', $row['IVA'] ?? '0'));
        $totalConImpuestos->appendChild($totalImpuesto);
        $infoFactura->appendChild($totalConImpuestos);

        // Detalle (genérico)
        $detalles = $doc->createElement('detalles');
        $detalle = $doc->createElement('detalle');
        $detalle->appendChild($doc->createElement('descripcion', 'Detalle genérico'));
        $detalle->appendChild($doc->createElement('cantidad', '1'));
        $detalle->appendChild($doc->createElement('precioUnitario', $row['VALOR_SIN_IMPUESTOS'] ?? '0'));
        $detalle->appendChild($doc->createElement('precioTotalSinImpuesto', $row['VALOR_SIN_IMPUESTOS'] ?? '0'));
        $detalles->appendChild($detalle);

        // Ensamblar XML
        $factura->appendChild($infoTributaria);
        $factura->appendChild($infoFactura);
        $factura->appendChild($detalles);

        $doc->appendChild($factura);
        return $doc->saveXML();
    }
}
