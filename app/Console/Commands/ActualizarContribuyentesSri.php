<?php

namespace App\Console\Commands;

use App\Models\SriDocument;
use App\Services\Sri\SriRucService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;

class ActualizarContribuyentesSri extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sri:actualizar-contribuyentes 
                            {--limit=50 : Límite de contribuyentes a actualizar por ejecución}
                            {--sleep=1 : Segundos de espera entre consultas al SRI}
                            {--force : Forzar actualización de todos los contribuyentes, incluso los ya registrados}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualiza la información de contribuyentes desde el SRI';

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
     * @param SriRucService $rucService
     * @return int
     */
    public function handle(SriRucService $rucService)
    {
        $this->info('Iniciando actualización de contribuyentes desde el SRI');

        // Obtener opciones
        $limit = (int)$this->option('limit');
        $sleep = (int)$this->option('sleep');
        $force = $this->option('force');

        // Construir la consulta
        $query = SriDocument::select('ruc_emisor')
            ->when(!$force, function ($query) {
                return $query->where(function ($q) {
                    $q->whereNull('razon_social_emisor')
                        ->orWhere('razon_social_emisor', '')
                        ->orWhere('razon_social_emisor', 'PENDIENTE CONSULTAR');
                });
            })
            ->groupBy('ruc_emisor')
            ->havingRaw('LENGTH(ruc_emisor) = 13');

        // Si hay límite, aplicarlo
        if ($limit > 0) {
            $query->limit($limit);
        }

        $rucs = $query->pluck('ruc_emisor')->toArray();

        $total = count($rucs);
        $this->info("Se procesarán {$total} RUCs");

        if ($total === 0) {
            $this->info('No hay contribuyentes para actualizar');
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $actualizados = 0;
        $errores = 0;

        foreach ($rucs as $ruc) {
            try {
                // Consultar información del contribuyente
                $result = $rucService->getContribuyenteInfo($ruc);

                if ($result['success']) {
                    // Actualizar todos los documentos con este RUC
                    $affected = SriDocument::where('ruc_emisor', $ruc)
                        ->update([
                            'razon_social_emisor' => $result['razonSocial']
                        ]);

                    $actualizados += $affected > 0 ? 1 : 0;
                } else {
                    $this->error("Error para RUC {$ruc}: " . ($result['message'] ?? 'Error desconocido'));
                    $errores++;
                }
            } catch (Exception $e) {
                $this->error("Error al procesar RUC {$ruc}: " . $e->getMessage());
                Log::error('Error al actualizar contribuyente', [
                    'ruc' => $ruc,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $errores++;
            }

            // Esperar para no sobrecargar el servicio del SRI
            if ($sleep > 0) {
                sleep($sleep);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("Proceso completado: {$actualizados} RUCs actualizados, {$errores} errores");

        return 0;
    }
}
