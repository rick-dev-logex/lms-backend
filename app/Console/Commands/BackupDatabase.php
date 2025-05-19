<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database';
    protected $description = 'Realiza un respaldo de la base de datos';

    public function handle()
    {
        $this->info('Iniciando respaldo de la base de datos...');

        try {
            // Nombre del archivo de respaldo
            $filename = 'backup_' . Carbon::now()->format('Y-m-d_H-i-s') . '.sql';

            // Ruta donde se guardará el respaldo
            $backupPath = storage_path('app/backups');

            // Crear directorio si no existe
            if (!file_exists($backupPath)) {
                mkdir($backupPath, 0755, true);
            }

            // Obtener credenciales de base de datos
            $host = config('database.connections.lms_backend.host');
            $database = config('database.connections.lms_backend.database');
            $username = config('database.connections.lms_backend.username');
            $password = config('database.connections.lms_backend.password');

            // Comando para PostgreSQL
            // if (config('database.default') === 'pgsql') {
            //     $command = "PGPASSWORD=\"{$password}\" pg_dump -h {$host} -U {$username} {$database} > " . $backupPath . '/' . $filename;
            // }
            // Comando para MySQL
            // else {
            $command = "mysqldump -h {$host} -u {$username} -p\"{$password}\" {$database} > " . $backupPath . '/' . $filename;
            // }

            // Ejecutar comando
            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);

            if ($returnVar !== 0) {
                throw new \Exception('Error en el comando de respaldo: ' . implode("\n", $output));
            }

            // Comprimir el archivo
            $zipFilename = $filename . '.gz';
            $zipCommand = "gzip -f " . $backupPath . '/' . $filename;
            exec($zipCommand);

            // Subir a GCS si está configurado
            if (config('filesystems.disks.gcs')) {
                Storage::disk('gcs')->put(
                    'backups/' . $zipFilename,
                    file_get_contents($backupPath . '/' . $zipFilename)
                );

                // Eliminar archivo local después de subir
                @unlink($backupPath . '/' . $zipFilename);

                $this->info('Respaldo subido a Google Cloud Storage: backups/' . $zipFilename);
            } else {
                $this->info('Respaldo guardado localmente: ' . $backupPath . '/' . $zipFilename);
            }

            // Eliminar respaldos antiguos (más de 7 días)
            $this->cleanOldBackups();

            Log::info('Respaldo de base de datos completado correctamente', [
                'filename' => $zipFilename
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error al realizar respaldo: ' . $e->getMessage());

            Log::error('Error al realizar respaldo de base de datos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    protected function cleanOldBackups()
    {
        $this->info('Limpiando respaldos antiguos...');

        $date = Carbon::now()->subDays(7);

        // Limpiar respaldos locales
        $backupPath = storage_path('app/backups');
        if (file_exists($backupPath)) {
            $files = glob($backupPath . '/backup_*.sql.gz');
            foreach ($files as $file) {
                if (filemtime($file) < $date->timestamp) {
                    @unlink($file);
                }
            }
        }

        // Limpiar respaldos en GCS
        if (config('filesystems.disks.gcs')) {
            $files = Storage::disk('gcs')->files('backups');
            foreach ($files as $file) {
                $lastModified = Storage::disk('gcs')->lastModified($file);
                if ($lastModified < $date->timestamp) {
                    Storage::disk('gcs')->delete($file);
                }
            }
        }
    }
}
