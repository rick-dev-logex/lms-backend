<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RequestIdValidator;

class ValidateDiscountIds extends Command
{
    protected $signature = 'validate:discount-ids';
    protected $description = 'Valida IDs de descuentos y relaciones con reposiciones';

    public function handle()
    {
        $validator = new RequestIdValidator();
        $results = $validator->validateDiscountIds();
        $this->info('Validación completada. Revisa los logs para detalles.');
        $this->info(json_encode($results, JSON_PRETTY_PRINT));
    }
}
