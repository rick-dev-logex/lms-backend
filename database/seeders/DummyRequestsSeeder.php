<?php

namespace Database\Seeders;

use App\Models\Request;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DummyRequestsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener IDs de cuentas, proyectos, responsables y transportes existentes
        $accounts = DB::table('accounts')->pluck('id');
        $projects = DB::connection('sistema_onix')->table('onix_proyectos')->pluck('id');
        $responsibles = DB::table('users')->pluck('id');
        $transports = DB::connection('sistema_onix')->table('onix_vehiculos')->where('deleted', 0)->pluck('id');

        // Generar registros de prueba
        for ($i = 1; $i <= 10; $i++) {
            Request::create([
                'unique_id' => ($i % 2 == 0 ? 'g-' : 'd-') . Str::uuid(),
                'type' => $i % 2 == 0 ? 'expense' : 'discount',
                'status' => ['pending', 'approved', 'rejected', 'review'][array_rand(['pending', 'approved', 'rejected', 'review'])],
                'request_date' => now()->subDays(rand(1, 365)),
                'invoice_number' => 'INV-' . rand(1000, 9999),
                'account_id' => $accounts->random(),
                'amount' => rand(1000, 100000) / 100,
                'project' => $projects->random(),
                'responsible_id' => $responsibles->random(),
                'transport_id' => $i % 2 == 0 ? $transports->random() : null,
                'attachment_path' => 'attachments/' . Str::random(10) . '.pdf',
                'note' => 'Nota de ejemplo para la solicitud #' . $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
