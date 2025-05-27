<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Request;
use App\Models\Reposicion;

class MigrateRequestRepositions extends Command
{
    protected $signature = 'migrate:request-repositions';
    protected $description = 'Asocia cada request con su reposición correspondiente usando la columna detail';

    public function handle()
    {
        $reposiciones = Reposicion::whereNotNull('detail')
            ->whereNull('deleted_at')
            ->get();

        $total = 0;

        foreach ($reposiciones as $reposicion) {
            $detailsRaw = is_string($reposicion->detail)
                ? $reposicion->detail
                : json_encode($reposicion->detail);

            $details = json_decode($detailsRaw, true);

            if (!is_array($details)) continue;

            $updated = Request::whereIn('unique_id', $details)
                ->whereNull('deleted_at')
                ->update(['reposicion_id' => $reposicion->id]);

            $this->info("Reposición ID {$reposicion->id} actualizó $updated solicitudes.");
            $total += $updated;
        }

        $this->info("✅ Migración completa. Total de solicitudes actualizadas: $total");

        return Command::SUCCESS;
    }
}
