<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\MaintenanceNotification;
use Illuminate\Console\Command;

class NotifyMaintenance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:maintenance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envía un correo de mantenimiento programado a todos los usuarios.';


    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->ask('¿Qué fecha tendrá el mantenimiento? (Ej: 21 de abril de 2025)');
        $start = $this->ask('¿A qué hora empieza el mantenimiento? (Ej: 12:00 p.m.)');
        $end = $this->ask('¿A qué hora termina el mantenimiento? (Ej: 12:20 p.m.)');

        $this->info("Enviando correos con mantenimiento programado el {$date} de {$start} a {$end}...");

        $users = User::all();

        foreach ($users as $user) {
            $user->notify(new MaintenanceNotification($date, $start, $end));
        }

        $this->info('Correos enviados con éxito.');
    }
}
