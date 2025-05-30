<?php

namespace App\Services\Sri;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class SriPdfGeneratorService
{
    /**
     * Genera un PDF a partir de los datos del documento SRI
     *
     * @param array $data Los datos del documento
     * @return string Contenido del PDF generado
     */
    public function generateFromData(array $data): string
    {
        // Preparar los datos para la vista
        $viewData = $this->prepareDataForTemplate($data);

        // Generar el HTML usando una plantilla Blade
        $html = View::make('pdf.factura', $viewData)->render();

        // Configurar opciones del PDF
        $options = [
            'margin-top'    => '10mm',
            'margin-bottom' => '10mm',
            'margin-right'  => '10mm',
            'margin-left'   => '10mm',
            'encoding'      => 'UTF-8',
            'default-font'  => 'sans-serif'
        ];

        // Generar el PDF
        $pdf = Pdf::loadHTML($html)->setOptions($options);

        // Retornar el contenido del PDF como string
        return $pdf->output();
    }

    /**
     * Prepara los datos para la plantilla del PDF
     *
     * @param array $data Datos originales del documento
     * @return array Datos estructurados para la plantilla
     */
    protected function prepareDataForTemplate(array $data): array
    {
        // Procesar fechas
        $fechaEmision = null;
        $fechaAutorizacion = null;

        if (!empty($data['FECHA_EMISION'])) {
            try {
                $fechaEmision = Carbon::createFromFormat('d/m/Y', $data['FECHA_EMISION'])->format('d/m/Y');
            } catch (\Exception $e) {
                try {
                    $fechaEmision = Carbon::parse($data['FECHA_EMISION'])->format('d/m/Y');
                } catch (\Exception $e) {
                    $fechaEmision = $data['FECHA_EMISION'];
                }
            }
        }

        if (!empty($data['FECHA_AUTORIZACION'])) {
            try {
                $fechaAutorizacion = Carbon::createFromFormat('d/m/Y H:i:s', $data['FECHA_AUTORIZACION'])->format('d/m/Y H:i:s');
            } catch (\Exception $e) {
                try {
                    $fechaAutorizacion = Carbon::parse($data['FECHA_AUTORIZACION'])->format('d/m/Y H:i:s');
                } catch (\Exception $e) {
                    $fechaAutorizacion = $data['FECHA_AUTORIZACION'];
                }
            }
        }

        // Extraer partes de la serie del comprobante
        $seriePartes = explode('-', $data['SERIE_COMPROBANTE'] ?? '000-000-000000000');
        $establecimiento = $seriePartes[0] ?? '000';
        $puntoEmision = $seriePartes[1] ?? '000';
        $secuencial = $seriePartes[2] ?? '000000000';

        // Preparar valores numéricos - asegurándonos de convertir a float y usar valores por defecto en caso de nulos
        $subtotal = isset($data['VALOR_SIN_IMPUESTOS']) ? (float)$data['VALOR_SIN_IMPUESTOS'] : 0;
        $iva = isset($data['IVA']) ? (float)$data['IVA'] : 0;
        $total = isset($data['IMPORTE_TOTAL']) ? (float)$data['IMPORTE_TOTAL'] : 0;

        // Log para debugging
        Log::debug('Valores numéricos en prepareDataForTemplate', [
            'subtotal' => $subtotal,
            'iva' => $iva,
            'total' => $total,
            'datos_originales' => [
                'VALOR_SIN_IMPUESTOS' => $data['VALOR_SIN_IMPUESTOS'],
                'IVA' => $data['IVA'],
                'IMPORTE_TOTAL' => $data['IMPORTE_TOTAL'],
            ]
        ]);

        // Si no hay total pero hay subtotal + IVA, calcularlo
        if ($total == 0 && ($subtotal > 0 || $iva > 0)) {
            $total = $subtotal + $iva;
        }

        // Crear un detalle con los valores correctos
        $detalles = [
            [
                'descripcion' => 'Producto/Servicio según factura',
                'cantidad' => 1,
                'precioUnitario' => $subtotal,
                'descuento' => 0,
                'precioTotal' => $subtotal,
                'codigoIVA' => $iva > 0 ? '2' : '0', // 2 = tarifa 12%, 0 = tarifa 0%
                'valorIVA' => $iva
            ]
        ];

        // Estructura final para la plantilla
        return [
            // Datos del documento
            'claveAcceso' => $data['CLAVE_ACCESO'] ?? '',
            'ambiente' => '2', // Por defecto ambiente de producción
            'tipoEmision' => '1', // Por defecto emisión normal
            'razonSocial' => $data['RAZON_SOCIAL_EMISOR'] ?? '',
            'nombreComercial' => $data['RAZON_SOCIAL_EMISOR'] ?? '',
            'ruc' => $data['RUC_EMISOR'] ?? '',
            'estab' => $establecimiento,
            'ptoEmi' => $puntoEmision,
            'secuencial' => $secuencial,
            'dirMatriz' => 'Dirección registrada en el SRI',
            'fechaEmision' => $fechaEmision,
            'fechaAutorizacion' => $fechaAutorizacion,
            'tipoComprobante' => $data['TIPO_COMPROBANTE'] ?? 'Factura',
            'codDoc' => '01', // Factura por defecto

            // Datos del cliente
            'razonSocialComprador' => 'PREBAM S.A.',
            'identificacionComprador' => $data['IDENTIFICACION_RECEPTOR'] ?? '0992301066001',
            'direccionComprador' => 'Guayaquil, Ecuador',

            // Valores
            'subtotal' => $subtotal,
            'subtotal0' => $iva > 0 ? 0 : $subtotal,
            'subtotal12' => $iva > 0 ? $subtotal : 0,
            'iva' => $iva,
            'total' => $total,
            'detalles' => $detalles,

            // Información adicional
            'infoAdicional' => [
                'Email' => 'prebam@ejemplo.com',
                'Teléfono' => '042123456'
            ]
        ];
    }
}
