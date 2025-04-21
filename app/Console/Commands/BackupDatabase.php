<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera una copia de seguridad de la base de datos en un archivo SQL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $database = config('database.connections.lms_backend.database');
        $username = config('database.connections.lms_backend.username');
        $password = config('database.connections.lms_backend.password');
        $host = config('database.connections.lms_backend.host');
        $backupPath = storage_path('app/backups');

        // Crear la carpeta si no existe
        if (!File::Exists($backupPath)) {
            File::makeDirectory($backupPath, 0775, true);
        }

        $filename = $backupPath . '/' . $database . '_' . date('Y-m-d_H-i-s') . '.sql';

        $command = "mysqldump --user={$username} --password=\"{$password}\" --host={$host} {$database} > {$filename}";
        $this->info("Ejecutando: $command");

        $result = null;
        system($command, $result);

        if ($result === 0) {
            $this->info("Copia de seguridad creada en: {$filename}");
        } else {
            $this->error("Error al crear la copia de seguridad.");
        }
    }
}
