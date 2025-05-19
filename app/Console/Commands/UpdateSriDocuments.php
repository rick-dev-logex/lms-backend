<?php

namespace App\Console\Commands;

use App\Services\AutomationService;
use Illuminate\Console\Command;

class UpdateSriDocuments extends Command
{
    protected $signature = 'sri:update-documents';
    protected $description = 'Actualiza la información de todos los documentos del SRI';

    protected $automationService;

    public function __construct(AutomationService $automationService)
    {
        parent::__construct();
        $this->automationService = $automationService;
    }

    public function handle()
    {
        $this->info('Iniciando actualización de documentos SRI...');

        $stats = $this->automationService->updateAllDocuments();

        $this->info('Actualización completada:');
        $this->table(
            ['Total', 'Actualizados', 'Fallidos', 'No procesados', 'Fecha'],
            [[$stats['total'], $stats['updated'], $stats['failed'], $stats['unprocessed'], $stats['timestamp']]]
        );

        return Command::SUCCESS;
    }
}
