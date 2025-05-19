<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SriDocument;
use App\Services\Sri\SriRucService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class SriContribuyenteController extends Controller
{
    protected $rucService;

    public function __construct(SriRucService $rucService)
    {
        $this->rucService = $rucService;
    }

    /**
     * Obtiene la información detallada de un contribuyente por su RUC
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function getContribuyenteInfo(Request $request)
    {
        try {
            $request->validate([
                'ruc' => 'required|string|size:13'
            ]);

            $ruc = $request->input('ruc');
            $result = $this->rucService->getContribuyenteInfo($ruc);

            if (!is_array($result) || !isset($result['success']) || !$result['success']) {
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
            Log::error('Error al obtener información del contribuyente', [
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
     * Actualiza todos los documentos con información de contribuyentes
     *
     * @return \Illuminate\Http\Response
     */
    public function actualizarContribuyentes()
    {
        try {
            // Obtener RUCs únicos que necesitan actualización
            $rucs = SriDocument::whereRaw('razon_social_emisor IS NULL OR razon_social_emisor = ""')
                ->orWhere('razon_social_emisor', 'PENDIENTE CONSULTAR')
                ->pluck('ruc_emisor')
                ->unique()
                ->filter(function ($ruc) {
                    return !empty($ruc) && strlen($ruc) == 13;
                });

            $total = count($rucs);
            $actualizados = 0;
            $errores = [];

            // Procesar cada RUC
            foreach ($rucs as $ruc) {
                try {
                    // Consultar información del contribuyente
                    $result = $this->rucService->getContribuyenteInfo($ruc);

                    if ($result['success']) {
                        // Actualizar todos los documentos con este RUC
                        $affected = SriDocument::where('ruc_emisor', $ruc)
                            ->update([
                                'razon_social_emisor' => $result['razonSocial']
                            ]);

                        $actualizados += $affected;
                    } else {
                        $errores[] = [
                            'ruc' => $ruc,
                            'error' => $result['message'] ?? 'Error desconocido'
                        ];
                    }
                } catch (Exception $e) {
                    $errores[] = [
                        'ruc' => $ruc,
                        'error' => $e->getMessage()
                    ];

                    Log::error('Error al actualizar contribuyente', [
                        'ruc' => $ruc,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Proceso completado. Se actualizaron {$actualizados} documentos de {$total} RUCs.",
                'total_rucs' => $total,
                'rucs_actualizados' => $total - count($errores),
                'documentos_actualizados' => $actualizados,
                'errores' => $errores
            ]);
        } catch (Exception $e) {
            Log::error('Error general al actualizar contribuyentes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error general al actualizar contribuyentes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Consulta y actualiza la información de un contribuyente específico
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function actualizarContribuyente(Request $request)
    {
        try {
            $request->validate([
                'ruc' => 'required|string|size:13'
            ]);

            $ruc = $request->input('ruc');

            // Consultar información del contribuyente
            $result = $this->rucService->getContribuyenteInfo($ruc);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Error al consultar información del contribuyente'
                ], 400);
            }

            // Actualizar todos los documentos con este RUC
            $affected = SriDocument::where('ruc_emisor', $ruc)
                ->update([
                    'razon_social_emisor' => $result['razonSocial']
                ]);

            return response()->json([
                'success' => true,
                'message' => "Se actualizaron {$affected} documentos con el RUC {$ruc}",
                'contribuyente' => [
                    'ruc' => $ruc,
                    'razonSocial' => $result['razonSocial'],
                    'estado' => $result['estado'] ?? 'No disponible',
                    'tipoContribuyente' => $result['tipoContribuyente'] ?? 'No disponible',
                    'direccion' => $result['direccion'] ?? 'No disponible',
                    'actividadEconomica' => $result['actividadEconomica'] ?? 'No disponible'
                ],
                'documentos_actualizados' => $affected
            ]);
        } catch (Exception $e) {
            Log::error('Error al actualizar contribuyente específico', [
                'ruc' => $request->input('ruc'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar contribuyente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene un resumen de los contribuyentes que han emitido documentos
     *
     * @return \Illuminate\Http\Response
     */
    public function getResumenContribuyentes()
    {
        try {
            // Obtener resumen de contribuyentes por RUC
            $contribuyentes = SriDocument::select(
                'ruc_emisor as ruc',
                'razon_social_emisor as razonSocial',
                DB::raw('COUNT(*) as documentos'),
                DB::raw('SUM(importe_total) as montoTotal'),
                DB::raw('MIN(fecha_emision) as primerDocumento'),
                DB::raw('MAX(fecha_emision) as ultimoDocumento')
            )
                ->groupBy('ruc_emisor', 'razon_social_emisor')
                ->orderByRaw('COUNT(*) DESC')
                ->get();

            // Enriquecer con información del SRI para los top contribuyentes
            $topContribuyentes = $contribuyentes->take(10)->map(function ($contribuyente) {
                $infoAdicional = [];

                if ($contribuyente->ruc && strlen($contribuyente->ruc) == 13) {
                    try {
                        $result = $this->rucService->getContribuyenteInfo($contribuyente->ruc);
                        if ($result['success']) {
                            $infoAdicional = [
                                'estado' => $result['estado'] ?? 'No disponible',
                                'tipoContribuyente' => $result['tipoContribuyente'] ?? 'No disponible',
                                'actividadEconomica' => $result['actividadEconomica'] ?? 'No disponible',
                                'obligadoContabilidad' => $result['obligadoContabilidad'] ?? 'NO'
                            ];
                        }
                    } catch (Exception $e) {
                        Log::warning('Error al consultar información adicional del contribuyente', [
                            'ruc' => $contribuyente->ruc,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                return array_merge($contribuyente->toArray(), $infoAdicional);
            });

            // Totales
            $totales = [
                'contribuyentes' => $contribuyentes->count(),
                'documentos' => $contribuyentes->sum('documentos'),
                'montoTotal' => $contribuyentes->sum('montoTotal')
            ];

            return response()->json([
                'success' => true,
                'totales' => $totales,
                'topContribuyentes' => $topContribuyentes,
                'contribuyentes' => $contribuyentes
            ]);
        } catch (Exception $e) {
            Log::error('Error al obtener resumen de contribuyentes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener resumen de contribuyentes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Valida un RUC ecuatoriano
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function validarRuc(Request $request)
    {
        try {
            $request->validate([
                'ruc' => 'required|string'
            ]);

            $ruc = $request->input('ruc');
            $esValido = $this->rucService->validarFormatoRuc($ruc);

            if ($esValido) {
                // Si es válido, intentar consultar información adicional
                $info = $this->rucService->getContribuyenteInfo($ruc);

                return response()->json([
                    'success' => true,
                    'valido' => true,
                    'ruc' => $ruc,
                    'info' => $info['success'] ? $info : null
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'valido' => false,
                    'ruc' => $ruc,
                    'message' => 'El formato del RUC no es válido'
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error al validar RUC', [
                'ruc' => $request->input('ruc'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al validar RUC: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Valida una autorización de comprobante electrónico
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function validarAutorizacion(Request $request)
    {
        try {
            $request->validate([
                'autorizacion' => 'required|string'
            ]);

            $autorizacion = $request->input('autorizacion');
            $esValido = $this->rucService->validarAutorizacion($autorizacion);

            return response()->json([
                'success' => true,
                'valido' => $esValido,
                'autorizacion' => $autorizacion,
                'message' => $esValido ? 'Autorización con formato válido' : 'El formato de la autorización no es válido'
            ]);
        } catch (Exception $e) {
            Log::error('Error al validar autorización', [
                'autorizacion' => $request->input('autorizacion'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al validar autorización: ' . $e->getMessage()
            ], 500);
        }
    }
}
