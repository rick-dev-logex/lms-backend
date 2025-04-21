<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RequestIdFixer;
use Illuminate\Support\Facades\Log;

class FixRequests extends Command
{
    protected $signature = 'fix:requests {--only-reposicion-ids : Solo asignar IDs de reposición faltantes} 
                                        {--only-discount-ids : Solo normalizar IDs de descuentos} 
                                        {--verify : Solo verificar integridad de la base de datos}';

    protected $description = 'Corrige problemas con IDs de requests y reposiciones';

    /**
     * @var RequestIdFixer
     */
    protected $fixer;

    /**
     * Constructor
     */
    public function __construct(RequestIdFixer $fixer)
    {
        parent::__construct();
        $this->fixer = $fixer;
    }

    /**
     * Ejecutar el comando
     */
    public function handle()
    {
        $this->info('Iniciando proceso de corrección...');

        try {
            // Determinar qué acciones ejecutar según las opciones
            if ($this->option('verify')) {
                $this->verifyIntegrity();
            } elseif ($this->option('only-reposicion-ids')) {
                $this->assignRepositionIds();
            } elseif ($this->option('only-discount-ids')) {
                $this->normalizeDiscountIds();
            } else {
                $this->runFullFix();
            }

            $this->info('Proceso completado exitosamente.');
        } catch (\Exception $e) {
            $this->error('Error durante el proceso: ' . $e->getMessage());
            Log::error('Error en comando fix:requests: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Ejecutar verificación de integridad
     */
    protected function verifyIntegrity()
    {
        $this->info('Verificando integridad de la base de datos...');
        $results = $this->fixer->verifyIntegrity();

        $this->line('Resultados de la verificación:');
        $this->line('- IDs anormalmente grandes: ' . $results['abnormal_ids']);
        $this->line('- Requests sin reposicion_id: ' . $results['missing_reposicion_ids']);

        if (!empty($results['inconsistencies'])) {
            $this->warn('Se encontraron ' . count($results['inconsistencies']) . ' inconsistencias:');
            foreach ($results['inconsistencies'] as $issue) {
                $this->warn('  * ' . $issue);
            }
        } else {
            $this->info('No se encontraron inconsistencias adicionales.');
        }
    }

    /**
     * Asignar reposicion_id faltantes
     */
    protected function assignRepositionIds()
    {
        $this->info('Asignando reposicion_id faltantes...');
        $stats = $this->fixer->assignMissingRepositionIds();

        $this->line('Resultados:');
        $this->line('- Total procesados: ' . $stats['total_processed']);
        $this->line('- Actualizados: ' . $stats['updated']);
        $this->line('- Errores: ' . $stats['errors']);

        if ($stats['updated'] > 0) {
            $this->info('Se han asignado correctamente los reposicion_id faltantes.');
        } elseif ($stats['total_processed'] === 0) {
            $this->info('No se encontraron requests pendientes de asignar reposicion_id.');
        } else {
            $this->warn('No se pudo actualizar ningún registro a pesar de encontrar candidatos.');
        }
    }

    /**
     * Normalizar IDs de descuentos
     */
    protected function normalizeDiscountIds()
    {
        $this->info('Normalizando IDs de descuentos...');
        $stats = $this->fixer->updateDiscountIds();

        $this->line('Resultados:');
        $this->line('- Total IDs problemáticos: ' . $stats['total']);
        $this->line('- Requests actualizados: ' . $stats['updated_requests']);
        $this->line('- Reposiciones actualizadas: ' . $stats['updated_reposiciones']);
        $this->line('- Errores: ' . $stats['errors']);

        if ($stats['updated_requests'] > 0) {
            $this->info('Se han normalizado correctamente los IDs de descuentos.');
        } elseif ($stats['total'] === 0) {
            $this->info('No se encontraron IDs de descuentos para normalizar.');
        } else {
            $this->warn('No se pudo actualizar ningún ID a pesar de encontrar candidatos.');
        }
    }

    /**
     * Ejecutar el proceso completo
     */
    protected function runFullFix()
    {
        $this->info('Ejecutando el proceso completo de corrección...');

        if (!$this->confirm('¿Estás seguro? Esto modificará IDs en toda la base de datos. ¿Has hecho una copia de seguridad?', true)) {
            $this->warn('Operación cancelada por el usuario.');
            return;
        }

        $results = $this->fixer->fixAll();

        $this->line('1. Asignación de reposicion_id:');
        $this->line('   - Total procesados: ' . $results['reposicion_assignment']['total_processed']);
        $this->line('   - Actualizados: ' . $results['reposicion_assignment']['updated']);

        $this->line('2. Normalización de IDs:');
        $this->line('   - Total IDs problemáticos: ' . $results['id_normalization']['total']);
        $this->line('   - Requests actualizados: ' . $results['id_normalization']['updated_requests']);
        $this->line('   - Reposiciones actualizadas: ' . $results['id_normalization']['updated_reposiciones']);

        $this->line('3. Verificación final:');
        $this->line('   - IDs anormalmente grandes restantes: ' . $results['verification']['abnormal_ids']);
        $this->line('   - Requests sin reposicion_id restantes: ' . $results['verification']['missing_reposicion_ids']);

        if (empty($results['verification']['inconsistencies'])) {
            $this->info('No se encontraron inconsistencias después de la corrección.');
        } else {
            $this->warn('Inconsistencias restantes después de la corrección: ' . count($results['verification']['inconsistencies']));
            if ($this->option('verbose')) {
                foreach ($results['verification']['inconsistencies'] as $issue) {
                    $this->warn('  * ' . $issue);
                }
            }
        }
    }
}
