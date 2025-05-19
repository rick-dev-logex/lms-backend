<?php

namespace App\Services\Sri;

use App\Models\SriDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class DeclaracionIvaService
{
    /**
     * Genera los datos para la declaración de IVA de un período específico
     *
     * @param string $periodo Período en formato YYYY-MM
     * @return array Datos para la declaración de IVA
     */
    public function generarDatosDeclaracion(string $periodo): array
    {
        try {
            // Parsear período (YYYY-MM)
            list($anio, $mes) = explode('-', $periodo);
            $fechaInicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth();
            $fechaFin = $fechaInicio->copy()->endOfMonth();

            // Obtener compras del período
            $compras = SriDocument::whereBetween('fecha_emision', [$fechaInicio, $fechaFin])
                ->get();

            // Inicializar contadores
            $comprasTarifa0 = 0;
            $comprasTarifa12 = 0;
            $ivaCompras = 0;
            $comprasNoGravaIva = 0;
            $comprasExentas = 0;
            $totalCompras = 0;

            // Clasificar compras
            foreach ($compras as $compra) {
                $baseImponible = $compra->valor_sin_impuestos;
                $iva = $compra->iva;
                $total = $compra->importe_total;

                $totalCompras += $total;

                if ($iva > 0) {
                    // Compras tarifa 12%
                    $comprasTarifa12 += $baseImponible;
                    $ivaCompras += $iva;
                } else {
                    // Determinar si es tarifa 0% o no grava
                    if ($baseImponible === $total) {
                        $comprasTarifa0 += $baseImponible;
                    } else {
                        $comprasNoGravaIva += $baseImponible;
                    }
                }
            }

            // Totales generales
            $totalCreditoTributario = $ivaCompras;

            // Casilleros declaración IVA (simulados para este ejemplo)
            // Los códigos de casillero se basan en el formulario 104 del SRI
            $casilleros = [
                // Ventas (en este ejemplo no tenemos datos, se dejan en 0)
                '401' => 0, // Ventas locales (excluye activos fijos) gravadas tarifa diferente de 0%
                '411' => 0, // IVA generado
                '403' => 0, // Ventas locales (excluye activos fijos) gravadas tarifa 0%
                '405' => 0, // Ventas locales (excluye activos fijos) excluidas del pago de IVA
                '407' => 0, // Ventas de activos fijos gravadas tarifa diferente de 0%
                '417' => 0, // IVA generado en venta de activos fijos
                '409' => 0, // Ventas de activos fijos gravadas tarifa 0%
                '483' => 0, // Total ventas y otras operaciones
                '484' => 0, // Total transferencias gravadas con IVA
                '499' => 0, // Total impuesto generado (en ventas)

                // Compras
                '501' => $comprasTarifa12, // Adquisiciones gravadas tarifa diferente de 0%
                '511' => $ivaCompras, // IVA en compras
                '503' => $comprasTarifa0, // Adquisiciones gravadas tarifa 0%
                '505' => $comprasExentas, // Adquisiciones exentas del pago de IVA
                '507' => $comprasNoGravaIva, // Adquisiciones no objeto del pago de IVA
                '583' => $totalCompras, // Total adquisiciones y pagos
                '584' => $comprasTarifa12, // Total adquisiciones gravadas con IVA
                '599' => $ivaCompras, // Total impuesto generado (en compras)

                // Resumen impositivo
                '601' => 0, // Impuesto causado (si 499>599) o diferencia (499<599)
                '602' => $ivaCompras, // Crédito tributario aplicable en este período
                '605' => 0, // Saldo crédito tributario anterior
                '607' => $ivaCompras, // Crédito tributario disponible para próximo período
                '609' => 0, // Retenciones IVA que le han efectuado
                '619' => 0, // Subtotal a pagar
                '721' => 0, // Total IVA a pagar
                '902' => 0, // Total impuesto a pagar
                '903' => 0, // Interés por mora
                '904' => 0, // Multas
                '999' => 0  // Total pagado
            ];

            // Formato para Excel y para frontend
            return [
                'success' => true,
                'periodo' => $periodo,
                'fechaInicio' => $fechaInicio->format('Y-m-d'),
                'fechaFin' => $fechaFin->format('Y-m-d'),
                'resumen' => [
                    'totalCompras' => $totalCompras,
                    'totalVentas' => 0, // No tenemos datos de ventas en este ejemplo
                    'comprasTarifa0' => $comprasTarifa0,
                    'comprasTarifa12' => $comprasTarifa12,
                    'ivaCompras' => $ivaCompras,
                    'creditoTributario' => $totalCreditoTributario
                ],
                'casilleros' => $casilleros,
                'documentos' => [
                    'total' => $compras->count(),
                    'porTipo' => $compras->groupBy('tipo_comprobante')
                        ->map(function ($items) {
                            return [
                                'cantidad' => $items->count(),
                                'total' => $items->sum('importe_total')
                            ];
                        })
                ]
            ];
        } catch (Exception $e) {
            Log::error('Error al generar datos para declaración de IVA', [
                'periodo' => $periodo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al generar datos para declaración de IVA: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Genera un resumen de IVA por proveedor para un período específico
     *
     * @param string $periodo Período en formato YYYY-MM
     * @return array Resumen de IVA por proveedor
     */
    public function generarResumenPorProveedor(string $periodo): array
    {
        try {
            // Parsear período (YYYY-MM)
            list($anio, $mes) = explode('-', $periodo);
            $fechaInicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth();
            $fechaFin = $fechaInicio->copy()->endOfMonth();

            // Agrupar por proveedor
            $resumen = SriDocument::whereBetween('fecha_emision', [$fechaInicio, $fechaFin])
                ->select(
                    'ruc_emisor',
                    'razon_social_emisor',
                    DB::raw('COUNT(*) as documentos'),
                    DB::raw('SUM(valor_sin_impuestos) as baseImponible'),
                    DB::raw('SUM(iva) as iva'),
                    DB::raw('SUM(importe_total) as total')
                )
                ->groupBy('ruc_emisor', 'razon_social_emisor')
                ->orderByDesc('total')
                ->get();

            return [
                'success' => true,
                'periodo' => $periodo,
                'fechaInicio' => $fechaInicio->format('Y-m-d'),
                'fechaFin' => $fechaFin->format('Y-m-d'),
                'totalProveedores' => $resumen->count(),
                'totalDocumentos' => $resumen->sum('documentos'),
                'totalIva' => $resumen->sum('iva'),
                'totalBaseImponible' => $resumen->sum('baseImponible'),
                'totalGeneral' => $resumen->sum('total'),
                'proveedores' => $resumen
            ];
        } catch (Exception $e) {
            Log::error('Error al generar resumen por proveedor', [
                'periodo' => $periodo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al generar resumen por proveedor: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Genera un archivo ATS en formato XML para un período específico
     *
     * @param string $periodo Período en formato YYYY-MM
     * @param array $datosEmpresa Datos de la empresa que declara
     * @return array Resultado de la generación del ATS
     */
    public function generarAts(string $periodo, array $datosEmpresa): array
    {
        try {
            // Parsear período (YYYY-MM)
            list($anio, $mes) = explode('-', $periodo);
            $fechaInicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth();
            $fechaFin = $fechaInicio->copy()->endOfMonth();

            // Obtener documentos del período
            $compras = SriDocument::whereBetween('fecha_emision', [$fechaInicio, $fechaFin])
                ->orderBy('fecha_emision')
                ->get();

            if ($compras->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No se encontraron documentos para el período seleccionado'
                ];
            }

            // Iniciar XML
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;

            // Crear elemento raíz
            $iva = $dom->createElement('iva');

            // Agregar información de la empresa declarante
            $iva->appendChild($dom->createElement('TipoIDInformante', $datosEmpresa['tipoId'] ?? '01')); // 01 = RUC
            $iva->appendChild($dom->createElement('IdInformante', $datosEmpresa['ruc'] ?? '0992301066001')); // Ruc de PREBAM S.A.
            $iva->appendChild($dom->createElement('razonSocial', $datosEmpresa['razonSocial'] ?? 'PREBAM S.A.'));
            $iva->appendChild($dom->createElement('Anio', $anio));
            $iva->appendChild($dom->createElement('Mes', str_pad($mes, 2, '0', STR_PAD_LEFT)));
            $iva->appendChild($dom->createElement('numEstabRuc', $datosEmpresa['numEstabRuc'] ?? '001'));

            // Agregar sección de compras
            $comprasXml = $dom->createElement('compras');

            // Elemento detalleCompras para contener todos los detalles
            $detalleCompras = $dom->createElement('detalleCompras');

            // Procesar cada compra
            foreach ($compras as $index => $compra) {
                $detalleCompra = $dom->createElement('detalleCompra');

                // Información del documento
                $codSustento = $dom->createElement('codSustento', '01'); // 01 = Crédito Tributario para IVA
                $detalleCompra->appendChild($codSustento);

                // Información del proveedor
                $tpIdProv = $dom->createElement('tpIdProv', '01'); // 01 = RUC
                $detalleCompra->appendChild($tpIdProv);

                $idProv = $dom->createElement('idProv', $compra->ruc_emisor);
                $detalleCompra->appendChild($idProv);

                // Tipo de comprobante
                $tipoComprobante = $this->getTipoComprobanteCodigoAts($compra->tipo_comprobante);
                $detalleCompra->appendChild($dom->createElement('tipoComprobante', $tipoComprobante));

                // Parte relacionada (no en este caso)
                $detalleCompra->appendChild($dom->createElement('parteRel', 'NO'));

                // Fechas
                $fechaRegistro = Carbon::parse($compra->fecha_emision)->format('d/m/Y');
                $detalleCompra->appendChild($dom->createElement('fechaRegistro', $fechaRegistro));

                $fechaEmision = Carbon::parse($compra->fecha_emision)->format('d/m/Y');
                $detalleCompra->appendChild($dom->createElement('fechaEmision', $fechaEmision));

                // Serie y secuencial
                $partes = explode('-', $compra->serie_comprobante);
                if (count($partes) >= 3) {
                    $establecimiento = $partes[0];
                    $puntoEmision = $partes[1];
                    $secuencial = $partes[2];
                } else {
                    $establecimiento = '000';
                    $puntoEmision = '000';
                    $secuencial = '000000000';
                }

                $detalleCompra->appendChild($dom->createElement('establecimiento', $establecimiento));
                $detalleCompra->appendChild($dom->createElement('puntoEmision', $puntoEmision));
                $detalleCompra->appendChild($dom->createElement('secuencial', $secuencial));

                // Autorización
                $detalleCompra->appendChild($dom->createElement('autorizacion', $compra->clave_acceso));

                // Valores
                $baseImponible = $compra->valor_sin_impuestos;
                $iva = $compra->iva;

                // Determinar si tiene IVA o no
                if ($iva > 0) {
                    // Compra con IVA
                    $detalleCompra->appendChild($dom->createElement('baseNoGraIva', '0.00'));
                    $detalleCompra->appendChild($dom->createElement('baseImponible', '0.00'));
                    $detalleCompra->appendChild($dom->createElement('baseImpGrav', number_format($baseImponible, 2, '.', '')));
                    $detalleCompra->appendChild($dom->createElement('montoIva', number_format($iva, 2, '.', '')));

                    // Código de porcentaje de IVA (2 = 12%)
                    $detalleCompra->appendChild($dom->createElement('codPorcentajeIva', '2'));
                } else {
                    // Compra sin IVA (tarifa 0%)
                    $detalleCompra->appendChild($dom->createElement('baseNoGraIva', '0.00'));
                    $detalleCompra->appendChild($dom->createElement('baseImponible', number_format($baseImponible, 2, '.', '')));
                    $detalleCompra->appendChild($dom->createElement('baseImpGrav', '0.00'));
                    $detalleCompra->appendChild($dom->createElement('montoIva', '0.00'));

                    // Código de porcentaje de IVA (0 = 0%)
                    $detalleCompra->appendChild($dom->createElement('codPorcentajeIva', '0'));
                }

                // Otros campos requeridos por el ATS
                $detalleCompra->appendChild($dom->createElement('montoIce', '0.00'));
                $detalleCompra->appendChild($dom->createElement('valorRetBienes', '0.00'));
                $detalleCompra->appendChild($dom->createElement('valorRetServicios', '0.00'));

                // Agregar el detalle a detalleCompras
                $detalleCompras->appendChild($detalleCompra);
            }

            // Agregar detalleCompras a compras
            $comprasXml->appendChild($detalleCompras);

            // Agregar compras a iva
            $iva->appendChild($comprasXml);

            // Agregar iva al DOM
            $dom->appendChild($iva);

            // Generar el XML como string
            $xmlString = $dom->saveXML();

            // Nombre del archivo
            $nombreArchivo = "ATS_{$datosEmpresa['ruc']}_{$anio}_{$mes}.xml";

            return [
                'success' => true,
                'periodo' => $periodo,
                'fechaInicio' => $fechaInicio->format('Y-m-d'),
                'fechaFin' => $fechaFin->format('Y-m-d'),
                'nombreArchivo' => $nombreArchivo,
                'xmlContent' => $xmlString,
                'documentosProcesados' => $compras->count(),
                'message' => "Archivo ATS generado correctamente con {$compras->count()} documentos"
            ];
        } catch (Exception $e) {
            Log::error('Error al generar ATS', [
                'periodo' => $periodo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al generar ATS: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Convierte el tipo de comprobante al código utilizado en el ATS
     *
     * @param string $tipoComprobante Descripción del tipo de comprobante
     * @return string Código para el ATS
     */
    protected function getTipoComprobanteCodigoAts(string $tipoComprobante): string
    {
        $tipos = [
            'FACTURA' => '01',
            'LIQUIDACIÓN DE COMPRA' => '03',
            'NOTA DE CRÉDITO' => '04',
            'NOTA DE DÉBITO' => '05',
            'GUÍA DE REMISIÓN' => '06',
            'COMPROBANTE DE RETENCIÓN' => '07',
            'DOCUMENTOS AUTORIZADOS' => '18',
            'COMPROBANTES DE PAGO' => '19',
            'DOCUMENTOS ADUANEROS' => '20',
            'CARTA DE PORTE AÉREO' => '21',
            'COMPROBANTE DE VENTA POR REEMBOLSO' => '41',
            'NOTA DE CRÉDITO POR REEMBOLSO' => '47',
            'NOTA DE DÉBITO POR REEMBOLSO' => '48',
        ];

        // Si encuentra el tipo, devuelve el código; si no, devuelve '01' por defecto
        return $tipos[$tipoComprobante] ?? '01';
    }
}
