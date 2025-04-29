<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\OldRecordsImport;
use App\Models\Request;
use App\Models\Reposicion;

class ImportOldRecords extends Command
{
    protected $signature = 'import:old-records {file : Ruta al archivo Excel/Csv}';
    protected $description = 'Importa solicitudes históricas y crea reposición';

    public function handle()
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("Archivo no encontrado: {$file}");
            return 1;
        }

        // 1) Quitar límites y desactivar query log para ahorro de memoria
        set_time_limit(0);
        ini_set('memory_limit', '1G');
        DB::connection()->disableQueryLog();

        // 2) Ejecutar la importación de solicitudes
        $import = new OldRecordsImport(app(\App\Services\UniqueIdService::class));
        Excel::import($import, $file);

        if (!empty($import->errors)) {
            $this->error("Errores durante importación:");
            foreach ($import->errors as $err) {
                $this->line("  • $err");
            }
            return 1;
        }

        // 3) Crear la reposición en un solo bloque
        $ids   = $import->requestUniqueIds;
        $total = Request::whereIn('unique_id', $ids)->sum('amount');

        $reposicion = Reposicion::create([
            'fecha_reposicion' => now(),
            'total_reposicion' => $total,
            'status'           => 'paid',
            'detail'           => $ids,
            'project'          => 'ADMN',
            'attachment_url'   => 'https://storage.googleapis.com/lms-archivos/descuentos_historicos.xlsx',
            'note'             => 'Migración histórica',
        ]);

        Request::whereIn('unique_id', $ids)
            ->update(['reposicion_id' => $reposicion->id]);

        $this->info("Importación completada. Reposición ID {$reposicion->id} creada con {$reposicion->total_reposicion} en {$reposicion->detail_count} solicitudes.");

        return 0;
    }
}
