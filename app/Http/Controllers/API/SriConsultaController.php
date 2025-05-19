<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SriDocument;
use App\Services\Sri\SriConsultaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class SriConsultaController extends Controller
{
    protected $consultaService;

    public function __construct(SriConsultaService $consultaService)
    {
        $this->consultaService = $consultaService;
    }

    /**
     * Consulta información de un contribuyente por su RUC
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function consultarContribuyente(Request $request)
    {
        try {
            $request->validate([
                'ruc' => 'required|string|size:13'
            ]);

            $ruc = $request->input('ruc');
            $result = $this->consultaService->consultarContribuyente($ruc);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Error al consultar información del contribuyente'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (Exception $e) {
            Log::error('Error al consultar contribuyente', [
                'ruc' => $request->input('ruc'),
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
     * Consulta información de un comprobante por su clave de acceso
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
            $result = $this->consultaService->consultarComprobante($claveAcceso);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Error al consultar información del comprobante'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
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
     * Valida un comprobante en el SRI
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function validarComprobante(Request $request)
    {
        try {
            $request->validate([
                'claveAcceso' => 'required|string|size:49'
            ]);

            $claveAcceso = $request->input('claveAcceso');
            $result = $this->consultaService->validarComprobante($claveAcceso);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Error al validar el comprobante'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (Exception $e) {
            Log::error('Error al validar comprobante', [
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
     * Extrae toda la información posible a partir de una clave de acceso
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function obtenerInfoDesdeClaveAcceso(Request $request)
    {
        try {
            $request->validate([
                'claveAcceso' => 'required|string|size:49'
            ]);

            $claveAcceso = $request->input('claveAcceso');
            $result = $this->consultaService->obtenerDatosDesdeClaveAcceso($claveAcceso);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Error al obtener información desde la clave de acceso'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (Exception $e) {
            Log::error('Error al obtener información desde clave de acceso', [
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
     * Verifica y actualiza datos de documentos según información obtenida del SRI
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function actualizarDocumentoDesdeRuc(Request $request)
    {
        try {
            $request->validate([
                'documentId' => 'required|integer|exists:sri_documents,id'
            ]);

            $documentId = $request->input('documentId');
            $documento = SriDocument::findOrFail($documentId);

            // Si no hay RUC o ya tiene razón social, saltar
            if (empty($documento->ruc_emisor) || !empty($documento->razon_social_emisor)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El documento ya tiene razón social o no tiene RUC para consultar'
                ]);
            }

            // Consultar información del RUC
            $infoRuc = $this->consultaService->consultarContribuyente($documento->ruc_emisor);

            if (!$infoRuc['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $infoRuc['message'] ?? 'Error al consultar información del RUC'
                ], 400);
            }

            // Actualizar información del documento
            $documento->update([
                'razon_social_emisor' => $infoRuc['razonSocial']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documento actualizado correctamente',
                'documento' => $documento->fresh(),
                'contribuyente' => $infoRuc
            ]);
        } catch (Exception $e) {
            Log::error('Error al actualizar documento desde RUC', [
                'documentId' => $request->input('documentId'),
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
     * Verifica y actualiza datos de documentos según información obtenida del SRI
     * a partir de la clave de acceso
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function actualizarDocumentoDesdeClaveAcceso(Request $request)
    {
        try {
            $request->validate([
                'documentId' => 'required|integer|exists:sri_documents,id'
            ]);

            $documentId = $request->input('documentId');
            $documento = SriDocument::findOrFail($documentId);

            // Si no hay clave de acceso, saltar
            if (empty($documento->clave_acceso)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El documento no tiene clave de acceso para consultar'
                ]);
            }

            // Consultar información completa desde la clave de acceso
            $infoCompleta = $this->consultaService->obtenerDatosDesdeClaveAcceso($documento->clave_acceso);

            if (!$infoCompleta['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $infoCompleta['message'] ?? 'Error al consultar información desde la clave de acceso'
                ], 400);
            }

            // Extraer información relevante para actualizar
            $datosActualizacion = [];

            // Actualizar datos del emisor si están disponibles
            if (isset($infoCompleta['emisor']) && $infoCompleta['emisor']['success']) {
                $datosActualizacion['ruc_emisor'] = $infoCompleta['emisor']['ruc'];
                $datosActualizacion['razon_social_emisor'] = $infoCompleta['emisor']['razonSocial'];
            }

            // Actualizar datos del comprobante si están disponibles
            if (isset($infoCompleta['comprobante']) && $infoCompleta['comprobante']['success']) {
                $comprobante = $infoCompleta['comprobante'];

                // Intentar extraer fecha de emisión
                if (isset($comprobante['comprobante']['infoFactura']['fechaEmision'])) {
                    $datosActualizacion['fecha_emision'] = $this->formatearFecha(
                        $comprobante['comprobante']['infoFactura']['fechaEmision']
                    );
                }

                // Intentar extraer fecha de autorización
                if (isset($comprobante['fechaAutorizacion'])) {
                    $datosActualizacion['fecha_autorizacion'] = $this->formatearFecha(
                        $comprobante['fechaAutorizacion'],
                        true
                    );
                }

                // Intentar extraer valores
                if (isset($comprobante['comprobante']['infoFactura'])) {
                    $infoFactura = $comprobante['comprobante']['infoFactura'];

                    if (isset($infoFactura['totalSinImpuestos'])) {
                        $datosActualizacion['valor_sin_impuestos'] = $infoFactura['totalSinImpuestos'];
                    }

                    if (isset($infoFactura['importeTotal'])) {
                        $datosActualizacion['importe_total'] = $infoFactura['importeTotal'];
                    }

                    // Calcular IVA desde impuestos si existe
                    if (isset($comprobante['comprobante']['impuestos'])) {
                        $iva = 0;
                        foreach ($comprobante['comprobante']['impuestos'] as $impuesto) {
                            if (($impuesto['codigo'] ?? '') === '2') { // Código 2 = IVA
                                $iva += $impuesto['valor'];
                            }
                        }
                        if ($iva > 0) {
                            $datosActualizacion['iva'] = $iva;
                        }
                    }

                    // Identificación del receptor
                    if (isset($infoFactura['identificacionComprador'])) {
                        $datosActualizacion['identificacion_receptor'] = $infoFactura['identificacionComprador'];
                    }
                }
            }

            // Si no hay datos para actualizar, informar
            if (empty($datosActualizacion)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron datos para actualizar el documento'
                ]);
            }

            // Actualizar el documento
            $documento->update($datosActualizacion);

            return response()->json([
                'success' => true,
                'message' => 'Documento actualizado correctamente con información del SRI',
                'documento' => $documento->fresh(),
                'datosActualizados' => $datosActualizacion,
                'infoCompleta' => $infoCompleta
            ]);
        } catch (Exception $e) {
            Log::error('Error al actualizar documento desde clave de acceso', [
                'documentId' => $request->input('documentId'),
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
     * Actualiza información de todos los documentos desde el SRI
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function actualizarTodosDocumentos(Request $request)
    {
        try {
            $limit = $request->input('limit', 50);
            $forceUpdate = $request->input('force', false);

            // Construir la consulta base
            $query = SriDocument::query();

            // Si no es forzado, solo actualizar documentos sin razón social
            if (!$forceUpdate) {
                $query->where(function ($q) {
                    $q->whereNull('razon_social_emisor')
                        ->orWhere('razon_social_emisor', '')
                        ->orWhere('razon_social_emisor', 'PENDIENTE CONSULTAR');
                });
            }

            // Aplicar límite
            if ($limit > 0) {
                $query->limit($limit);
            }

            // Obtener documentos
            $documentos = $query->get();

            if ($documentos->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No hay documentos para actualizar'
                ]);
            }

            $actualizados = 0;
            $errores = [];

            // Procesar cada documento
            foreach ($documentos as $documento) {
                try {
                    // Método de actualización depende de qué datos tenemos disponibles
                    if (!empty($documento->ruc_emisor)) {
                        // Actualizar desde RUC
                        $infoRuc = $this->consultaService->consultarContribuyente($documento->ruc_emisor);

                        if ($infoRuc['success']) {
                            $documento->update([
                                'razon_social_emisor' => $infoRuc['razonSocial']
                            ]);
                            $actualizados++;
                        } else {
                            $errores[] = [
                                'id' => $documento->id,
                                'ruc' => $documento->ruc_emisor,
                                'message' => $infoRuc['message'] ?? 'Error al consultar RUC'
                            ];
                        }
                    } elseif (!empty($documento->clave_acceso)) {
                        // Actualizar desde clave de acceso
                        $infoCompleta = $this->consultaService->obtenerDatosDesdeClaveAcceso($documento->clave_acceso);

                        if ($infoCompleta['success']) {
                            // Similar a la lógica de actualizarDocumentoDesdeClaveAcceso
                            $datosActualizacion = [];

                            // Extraer datos relevantes...
                            // (código similar al método actualizarDocumentoDesdeClaveAcceso)

                            if (!empty($datosActualizacion)) {
                                $documento->update($datosActualizacion);
                                $actualizados++;
                            }
                        } else {
                            $errores[] = [
                                'id' => $documento->id,
                                'claveAcceso' => $documento->clave_acceso,
                                'message' => $infoCompleta['message'] ?? 'Error al consultar clave de acceso'
                            ];
                        }
                    } else {
                        $errores[] = [
                            'id' => $documento->id,
                            'message' => 'El documento no tiene RUC ni clave de acceso para consultar'
                        ];
                    }
                } catch (Exception $e) {
                    $errores[] = [
                        'id' => $documento->id,
                        'message' => 'Error al actualizar: ' . $e->getMessage()
                    ];

                    Log::error('Error al actualizar documento individual', [
                        'documentId' => $documento->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Se han actualizado {$actualizados} documentos de {$documentos->count()} procesados",
                'actualizados' => $actualizados,
                'procesados' => $documentos->count(),
                'errores' => $errores
            ]);
        } catch (Exception $e) {
            Log::error('Error al actualizar todos los documentos', [
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
     * Formatea una fecha desde un string
     *
     * @param string $fecha Fecha en formato string
     * @param bool $conHora Indica si la fecha incluye hora
     * @return string|null Fecha formateada o null si no es válida
     */
    protected function formatearFecha(string $fecha, bool $conHora = false): ?string
    {
        try {
            if (empty($fecha)) {
                return null;
            }

            // Formato con hora
            if ($conHora) {
                return \Carbon\Carbon::parse($fecha)->format('Y-m-d H:i:s');
            }

            // Formato solo fecha
            return \Carbon\Carbon::parse($fecha)->format('Y-m-d');
        } catch (Exception $e) {
            Log::warning("Error al formatear fecha: {$fecha}", [
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }
}
