<?php

namespace App\Console\Commands;

use App\Services\AutomationService;
use Illuminate\Console\Command;

class SendDailySummary extends Command
{
    protected $signature = 'sri:daily-summary';
    protected $description = 'Envía un resumen diario de documentos SRI';

    protected $automationService;

    public function __construct(AutomationService $automationService)
    {
        parent::__construct();
        $this->automationService = $automationService;
    }

    public function handle()
    {
        $this->info('Enviando resumen diario de documentos SRI...');

        $result = $this->automationService->sendDailySummary();

        if ($result) {
            $this->info('Resumen diario enviado correctamente.');
        } else {
            $this->error('Error al enviar el resumen diario.');
        }

        return $result ? Command::SUCCESS : Command::FAILURE;
    }
}
