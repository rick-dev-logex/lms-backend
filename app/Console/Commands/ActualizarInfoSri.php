<?php

namespace App\Console\Commands;

use App\Models\SriDocument;
use App\Services\Sri\SriConsultaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;

class ActualizarInfoSri extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sri:actualizar-info 
                            {--limit=50 : Límite de documentos a procesar}
                            {--sleep=2 : Segundos a esperar entre consultas al SRI}
                            {--force : Actualizar todos los documentos, no solo los que faltan datos}
                            {--tipo=emisor : Tipo de actualización: emisor, comprobante o ambos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualiza la información de documentos consultando datos en el SRI';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @param SriConsultaService $consultaService
     * @return int
     */
    public function handle(SriConsultaService $consultaService)
    {
        $this->info('Iniciando actualización de información desde el SRI');

        // Obtener opciones
        $limit = (int)$this->option('limit');
        $sleep = (int)$this->option('sleep');
        $force = $this->option('force');
        $tipo = $this->option('tipo');

        // Validar opciones
        if (!in_array($tipo, ['emisor', 'comprobante', 'ambos'])) {
            $this->error('El tipo de actualización debe ser: emisor, comprobante o ambos');
            return 1;
        }

        // Construir consulta base
        $query = SriDocument::query();

        // Filtrar según tipo y si es forzado o no
        if (!$force) {
            if ($tipo === 'emisor' || $tipo === 'ambos') {
                $query->where(function ($q) {
                    $q->whereNull('razon_social_emisor')
                        ->orWhere('razon_social_emisor', '')
                        ->orWhere('razon_social_emisor', 'PENDIENTE CONSULTAR');
                });
            }

            if ($tipo === 'comprobante' || $tipo === 'ambos') {
                $query->orWhere(function ($q) {
                    $q->whereNotNull('clave_acceso')
                        ->where('clave_acceso', '!=', '')
                        ->where(function ($sq) {
                            $sq->whereNull('fecha_autorizacion')
                                ->orWhereNull('fecha_emision')
                                ->orWhere('valor_sin_impuestos', 0)
                                ->orWhereNull('valor_sin_impuestos');
                        });
                });
            }
        }

        // Aplicar límite si corresponde
        if ($limit > 0) {
            $query->limit($limit);
        }

        // Obtener documentos
        $documentos = $query->get();

        $total = $documentos->count();
        $this->info("Se procesarán {$total} documentos");

        if ($total === 0) {
            $this->info('No hay documentos para actualizar');
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $actualizadosEmisor = 0;
        $actualizadosComprobante = 0;
        $errores = 0;

        foreach ($documentos as $documento) {
            try {
                // Actualizar información del emisor
                if (($tipo === 'emisor' || $tipo === 'ambos') &&
                    !empty($documento->ruc_emisor) &&
                    (empty($documento->razon_social_emisor) || $force)
                ) {

                    $infoEmisor = $consultaService->consultarContribuyente($documento->ruc_emisor);

                    if ($infoEmisor['success']) {
                        $documento->razon_social_emisor = $infoEmisor['razonSocial'];
                        $documento->save();
                        $actualizadosEmisor++;
                    } else {
                        $this->error("Error al consultar RUC {$documento->ruc_emisor}: " . ($infoEmisor['message'] ?? 'Error desconocido'));
                        $errores++;
                    }

                    // Esperar entre consultas
                    sleep($sleep);
                }

                // Actualizar información del comprobante
                if (($tipo === 'comprobante' || $tipo === 'ambos') &&
                    !empty($documento->clave_acceso) &&
                    ($force ||
                        empty($documento->fecha_autorizacion) ||
                        empty($documento->fecha_emision) ||
                        empty($documento->valor_sin_impuestos))
                ) {

                    $infoComprobante = $consultaService->obtenerDatosDesdeClaveAcceso($documento->clave_acceso);

                    if ($infoComprobante['success']) {
                        // Extraer y actualizar datos
                        $datosActualizacion = [];

                        // Datos básicos
                        if (isset($infoComprobante['datosBasicos'])) {
                            $datosBasicos = $infoComprobante['datosBasicos'];

                            if (isset($datosBasicos['fechaEmision']) && !empty($datosBasicos['fechaEmision'])) {
                                $datosActualizacion['fecha_emision'] = $datosBasicos['fechaEmision'];
                            }

                            if (isset($datosBasicos['rucEmisor']) && !empty($datosBasicos['rucEmisor'])) {
                                $datosActualizacion['ruc_emisor'] = $datosBasicos['rucEmisor'];
                            }
                        }

                        // Datos del emisor
                        if (isset($infoComprobante['emisor']) && $infoComprobante['emisor']['success']) {
                            $emisor = $infoComprobante['emisor'];

                            if (isset($emisor['razonSocial']) && !empty($emisor['razonSocial'])) {
                                $datosActualizacion['razon_social_emisor'] = $emisor['razonSocial'];
                            }
                        }

                        // Datos del comprobante
                        if (isset($infoComprobante['comprobante']) && $infoComprobante['comprobante']['success']) {
                            $comprobante = $infoComprobante['comprobante'];

                            // Fecha de autorización
                            if (isset($comprobante['fechaAutorizacion']) && !empty($comprobante['fechaAutorizacion'])) {
                                $datosActualizacion['fecha_autorizacion'] = $this->formatearFecha(
                                    $comprobante['fechaAutorizacion'],
                                    true
                                );
                            }

                            // Detalles del comprobante
                            if (isset($comprobante['comprobante']['infoFactura'])) {
                                $infoFactura = $comprobante['comprobante']['infoFactura'];

                                if (isset($infoFactura['totalSinImpuestos'])) {
                                    $datosActualizacion['valor_sin_impuestos'] = $infoFactura['totalSinImpuestos'];
                                }

                                if (isset($infoFactura['importeTotal'])) {
                                    $datosActualizacion['importe_total'] = $infoFactura['importeTotal'];
                                }

                                if (isset($infoFactura['identificacionComprador'])) {
                                    $datosActualizacion['identificacion_receptor'] = $infoFactura['identificacionComprador'];
                                }
                            }

                            // Calcular IVA
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
                        }

                        // Actualizar si hay datos
                        if (!empty($datosActualizacion)) {
                            $documento->update($datosActualizacion);
                            $actualizadosComprobante++;
                        }
                    } else {
                        $this->error("Error al consultar comprobante {$documento->clave_acceso}: " . ($infoComprobante['message'] ?? 'Error desconocido'));
                        $errores++;
                    }

                    // Esperar entre consultas
                    sleep($sleep);
                }
            } catch (Exception $e) {
                $this->error("Error al procesar documento ID {$documento->id}: " . $e->getMessage());
                Log::error('Error al actualizar documento desde SRI', [
                    'documentId' => $documento->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $errores++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("Proceso completado:");
        $this->info("- Documentos procesados: {$total}");
        $this->info("- Emisores actualizados: {$actualizadosEmisor}");
        $this->info("- Comprobantes actualizados: {$actualizadosComprobante}");
        $this->info("- Errores: {$errores}");

        return 0;
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
