<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SriDocument;
use App\Services\Sri\ComprobanteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class ComprobanteController extends Controller
{
    protected $comprobanteService;

    public function __construct(ComprobanteService $comprobanteService)
    {
        $this->comprobanteService = $comprobanteService;
    }

    /**
     * Consulta un comprobante por su clave de acceso
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function consultarComprobante(Request $request)
    {
        try {
            $request->validate([
                'claveAcceso' => 'required|string|size:49'
            ]);

            $claveAcceso = $request->input('claveAcceso');
            $resultado = $this->comprobanteService->consultarComprobante($claveAcceso);

            if (!$resultado['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $resultado['message'] ?? 'Error al consultar comprobante'
                ], 400);
            }

            // Obtener información adicional del emisor
            $emisorData = $this->obtenerDatosEmisor($resultado);

            // Crear objeto de respuesta
            $response = [
                'success' => true,
                'datosBasicos' => $this->extraerDatosBasicos($resultado),
                'emisor' => $emisorData,
                'comprobante' => $resultado
            ];

            return response()->json($response);
        } catch (Exception $e) {
            Log::error('Error al consultar comprobante', [
                'claveAcceso' => $request->input('claveAcceso'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Guarda un comprobante consultado en la base de datos
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function guardarComprobante(Request $request)
    {
        try {
            $request->validate([
                'claveAcceso' => 'required|string|size:49'
            ]);

            $claveAcceso = $request->input('claveAcceso');

            // Consultar el comprobante
            $resultado = $this->comprobanteService->consultarComprobante($claveAcceso);

            if (!$resultado['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $resultado['message'] ?? 'Error al consultar comprobante'
                ], 400);
            }

            // Extraer datos para guardar
            $datosBasicos = $this->extraerDatosBasicos($resultado);
            $emisorData = $this->obtenerDatosEmisor($resultado);
            $comprobanteData = $resultado['comprobante'] ?? [];

            // Preparar datos para el modelo
            $serieComprobante = "{$datosBasicos['establecimiento']}-{$datosBasicos['puntoEmision']}-{$datosBasicos['secuencial']}";

            // Procesar fechas
            $fechaEmision = $this->parseDate($datosBasicos['fechaEmision'] ?? null);
            $fechaAutorizacion = $this->parseDate($resultado['autorizacion']['fechaAutorizacion'] ?? null, true);

            // Extraer valores
            $valorSinImpuestos = 0;
            $iva = 0;
            $importeTotal = 0;
            $identificacionReceptor = '';

            if (isset($comprobanteData['infoFactura'])) {
                $valorSinImpuestos = (float)($comprobanteData['infoFactura']['totalSinImpuestos'] ?? 0);
                $importeTotal = (float)($comprobanteData['infoFactura']['importeTotal'] ?? 0);
                $identificacionReceptor = $comprobanteData['infoFactura']['identificacionComprador'] ?? '';
            } elseif (isset($comprobanteData['infoNotaCredito'])) {
                $valorSinImpuestos = (float)($comprobanteData['infoNotaCredito']['totalSinImpuestos'] ?? 0);
                $importeTotal = (float)($comprobanteData['infoNotaCredito']['valorModificacion'] ?? 0);
                $identificacionReceptor = $comprobanteData['infoNotaCredito']['identificacionComprador'] ?? '';
            } elseif (isset($comprobanteData['infoLiquidacionCompra'])) {
                $valorSinImpuestos = (float)($comprobanteData['infoLiquidacionCompra']['totalSinImpuestos'] ?? 0);
                $importeTotal = (float)($comprobanteData['infoLiquidacionCompra']['importeTotal'] ?? 0);
                $identificacionReceptor = $comprobanteData['infoLiquidacionCompra']['identificacionProveedor'] ?? '';
            }

            // Extraer IVA
            $iva = (float)($comprobanteData['iva'] ?? 0);

            // Crear o actualizar el documento
            $documento = SriDocument::updateOrCreate(
                ['clave_acceso' => $claveAcceso],
                [
                    'ruc_emisor' => $datosBasicos['rucEmisor'] ?? '',
                    'razon_social_emisor' => $emisorData['razonSocial'] ?? '',
                    'tipo_comprobante' => $datosBasicos['nombreTipoComprobante'] ?? '',
                    'serie_comprobante' => $serieComprobante,
                    'fecha_emision' => $fechaEmision,
                    'fecha_autorizacion' => $fechaAutorizacion,
                    'valor_sin_impuestos' => $valorSinImpuestos,
                    'iva' => $iva,
                    'importe_total' => $importeTotal,
                    'identificacion_receptor' => $identificacionReceptor,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Documento guardado correctamente',
                'documento' => $documento
            ]);
        } catch (Exception $e) {
            Log::error('Error al guardar comprobante', [
                'claveAcceso' => $request->input('claveAcceso'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al guardar el comprobante: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Guarda varios comprobantes consultados en la base de datos
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function guardarVariosComprobantes(Request $request)
    {
        try {
            $request->validate([
                'clavesAcceso' => 'required|array',
                'clavesAcceso.*' => 'required|string|size:49'
            ]);

            $clavesAcceso = $request->input('clavesAcceso');
            $documentosGuardados = [];
            $errores = [];

            foreach ($clavesAcceso as $claveAcceso) {
                try {
                    // Consultar el comprobante
                    $resultado = $this->comprobanteService->consultarComprobante($claveAcceso);

                    if (!$resultado['success']) {
                        $errores[] = [
                            'claveAcceso' => $claveAcceso,
                            'message' => $resultado['message'] ?? 'Error al consultar comprobante'
                        ];
                        continue;
                    }

                    // Extraer datos para guardar
                    $datosBasicos = $this->extraerDatosBasicos($resultado);
                    $emisorData = $this->obtenerDatosEmisor($resultado);
                    $comprobanteData = $resultado['comprobante'] ?? [];

                    // Preparar datos para el modelo
                    $serieComprobante = "{$datosBasicos['establecimiento']}-{$datosBasicos['puntoEmision']}-{$datosBasicos['secuencial']}";

                    // Procesar fechas
                    $fechaEmision = $this->parseDate($datosBasicos['fechaEmision'] ?? null);
                    $fechaAutorizacion = $this->parseDate($resultado['autorizacion']['fechaAutorizacion'] ?? null, true);

                    // Extraer valores
                    $valorSinImpuestos = 0;
                    $iva = 0;
                    $importeTotal = 0;
                    $identificacionReceptor = '';

                    if (isset($comprobanteData['infoFactura'])) {
                        $valorSinImpuestos = (float)($comprobanteData['infoFactura']['totalSinImpuestos'] ?? 0);
                        $importeTotal = (float)($comprobanteData['infoFactura']['importeTotal'] ?? 0);
                        $identificacionReceptor = $comprobanteData['infoFactura']['identificacionComprador'] ?? '';
                    } elseif (isset($comprobanteData['infoNotaCredito'])) {
                        $valorSinImpuestos = (float)($comprobanteData['infoNotaCredito']['totalSinImpuestos'] ?? 0);
                        $importeTotal = (float)($comprobanteData['infoNotaCredito']['valorModificacion'] ?? 0);
                        $identificacionReceptor = $comprobanteData['infoNotaCredito']['identificacionComprador'] ?? '';
                    } elseif (isset($comprobanteData['infoLiquidacionCompra'])) {
                        $valorSinImpuestos = (float)($comprobanteData['infoLiquidacionCompra']['totalSinImpuestos'] ?? 0);
                        $importeTotal = (float)($comprobanteData['infoLiquidacionCompra']['importeTotal'] ?? 0);
                        $identificacionReceptor = $comprobanteData['infoLiquidacionCompra']['identificacionProveedor'] ?? '';
                    }

                    // Extraer IVA
                    $iva = (float)($comprobanteData['iva'] ?? 0);

                    // Crear o actualizar el documento
                    $documento = SriDocument::updateOrCreate(
                        ['clave_acceso' => $claveAcceso],
                        [
                            'ruc_emisor' => $datosBasicos['rucEmisor'] ?? '',
                            'razon_social_emisor' => $emisorData['razonSocial'] ?? '',
                            'tipo_comprobante' => $datosBasicos['nombreTipoComprobante'] ?? '',
                            'serie_comprobante' => $serieComprobante,
                            'fecha_emision' => $fechaEmision,
                            'fecha_autorizacion' => $fechaAutorizacion,
                            'valor_sin_impuestos' => $valorSinImpuestos,
                            'iva' => $iva,
                            'importe_total' => $importeTotal,
                            'identificacion_receptor' => $identificacionReceptor,
                        ]
                    );

                    $documentosGuardados[] = $documento;
                } catch (Exception $e) {
                    $errores[] = [
                        'claveAcceso' => $claveAcceso,
                        'message' => 'Error: ' . $e->getMessage()
                    ];

                    Log::error('Error al guardar comprobante individual', [
                        'claveAcceso' => $claveAcceso,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => count($documentosGuardados) . ' documentos guardados correctamente',
                'documentos' => $documentosGuardados,
                'errores' => $errores
            ]);
        } catch (Exception $e) {
            Log::error('Error al guardar varios comprobantes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extrae los datos básicos de un comprobante
     *
     * @param array $resultado Resultado de la consulta
     * @return array Datos básicos del comprobante
     */
    protected function extraerDatosBasicos(array $resultado): array
    {
        $tipoDocumentoMap = [
            'factura' => '01',
            'notaCredito' => '04',
            'notaDebito' => '05',
            'liquidacionCompra' => '03'
        ];

        $tipoDocumentoNombre = [
            'factura' => 'FACTURA',
            'notaCredito' => 'NOTA DE CRÉDITO',
            'notaDebito' => 'NOTA DE DÉBITO',
            'liquidacionCompra' => 'LIQUIDACIÓN DE COMPRA'
        ];

        $datosBasicos = [
            'claveAcceso' => $resultado['claveAcceso'] ?? '',
            'rucEmisor' => '',
            'tipoComprobante' => '',
            'nombreTipoComprobante' => '',
            'establecimiento' => '',
            'puntoEmision' => '',
            'secuencial' => '',
            'fechaEmision' => '',
            'ambiente' => $resultado['autorizacion']['ambiente'] === '2' ? 'PRODUCCIÓN' : 'PRUEBAS'
        ];

        // Extraer información tributaria
        if (isset($resultado['comprobante']['infoTributaria'])) {
            $infoTributaria = $resultado['comprobante']['infoTributaria'];

            $datosBasicos['rucEmisor'] = $infoTributaria['ruc'] ?? '';
            $datosBasicos['tipoComprobante'] = $infoTributaria['codDoc'] ?? '';
            $datosBasicos['establecimiento'] = $infoTributaria['estab'] ?? '';
            $datosBasicos['puntoEmision'] = $infoTributaria['ptoEmi'] ?? '';
            $datosBasicos['secuencial'] = $infoTributaria['secuencial'] ?? '';

            // Mapear el tipo de documento a un nombre legible
            $codDoc = $infoTributaria['codDoc'] ?? '';
            $tipoDoc = array_search($codDoc, $tipoDocumentoMap);
            if ($tipoDoc !== false) {
                $datosBasicos['nombreTipoComprobante'] = $tipoDocumentoNombre[$tipoDoc] ?? 'COMPROBANTE';
            }
        }

        // Extraer fecha de emisión según tipo de documento
        if (isset($resultado['comprobante']['infoFactura'])) {
            $datosBasicos['fechaEmision'] = $resultado['comprobante']['infoFactura']['fechaEmision'] ?? '';
        } elseif (isset($resultado['comprobante']['infoNotaCredito'])) {
            $datosBasicos['fechaEmision'] = $resultado['comprobante']['infoNotaCredito']['fechaEmision'] ?? '';
        } elseif (isset($resultado['comprobante']['infoNotaDebito'])) {
            $datosBasicos['fechaEmision'] = $resultado['comprobante']['infoNotaDebito']['fechaEmision'] ?? '';
        } elseif (isset($resultado['comprobante']['infoLiquidacionCompra'])) {
            $datosBasicos['fechaEmision'] = $resultado['comprobante']['infoLiquidacionCompra']['fechaEmision'] ?? '';
        }

        return $datosBasicos;
    }

    /**
     * Obtiene los datos del emisor
     *
     * @param array $resultado Resultado de la consulta
     * @return array Datos del emisor
     */
    protected function obtenerDatosEmisor(array $resultado): array
    {
        $emisorData = [
            'success' => true,
            'ruc' => '',
            'razonSocial' => '',
            'nombreComercial' => '',
            'direccionMatriz' => ''
        ];

        // Extraer información del emisor desde el XML
        if (isset($resultado['comprobante']['infoTributaria'])) {
            $infoTributaria = $resultado['comprobante']['infoTributaria'];

            $emisorData['ruc'] = $infoTributaria['ruc'] ?? '';
            $emisorData['razonSocial'] = $infoTributaria['razonSocial'] ?? '';
            $emisorData['nombreComercial'] = $infoTributaria['nombreComercial'] ?? $infoTributaria['razonSocial'] ?? '';
            $emisorData['direccionMatriz'] = $infoTributaria['dirMatriz'] ?? '';
        }

        return $emisorData;
    }

    /**
     * Parsea una fecha
     *
     * @param string|null $fechaStr Fecha en formato string
     * @param bool $includeTime Si debe incluir tiempo en el formato
     * @return string|null Fecha formateada
     */
    protected function parseDate(?string $fechaStr, bool $includeTime = false): ?string
    {
        if (empty($fechaStr)) {
            return null;
        }

        try {
            // Intentar diversos formatos
            $formats = [
                'd/m/Y',
                'd/m/Y H:i:s',
                'Y-m-d',
                'Y-m-d H:i:s',
                'Y-m-dTH:i:s'
            ];

            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $fechaStr);
                if ($date !== false) {
                    return $includeTime ? $date->format('Y-m-d H:i:s') : $date->format('Y-m-d');
                }
            }

            // Si ningún formato funciona, intentar con strtotime
            $timestamp = strtotime($fechaStr);
            if ($timestamp !== false) {
                return $includeTime ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d', $timestamp);
            }
        } catch (Exception $e) {
            Log::warning("Error al parsear fecha: {$fechaStr}", [
                'error' => $e->getMessage()
            ]);
        }

        return $fechaStr; // Devolver el valor original si no se pudo parsear
    }
}
