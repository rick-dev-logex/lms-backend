<?php

use App\Models\Request;
use App\Models\CajaChica;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Iniciando migración real a caja_chica...\n";

try {
    $requests = Request::all(); // Volvemos a todos los registros
    echo "Total de solicitudes encontradas: " . $requests->count() . "\n";

    $meses = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

    DB::beginTransaction();

    $exampleRequests = Request::take(5)->get();
    echo "Ejemplo de solicitudes:\n";
    foreach ($exampleRequests as $request) {
        echo "ID: {$request->id}, Fecha: {$request->request_date}, Monto: {$request->amount}, Proyecto: {$request->project}\n";
    }

    foreach ($requests as $request) {
        // Depuración mínima para confirmar
        echo "Procesando ID: {$request->id}, request_date: '{$request->request_date}'\n";

        // Manejo de la fecha
        $fecha = $request->request_date; // Ya es Carbon, no necesitamos parsear

        $centroCosto = $meses[$fecha->month - 1] . ' ' . $fecha->year;
        $mesServicio = $fecha->format('Y-m-d'); // Cambiamos a Y-m-d para evitar parseo inválido

        $numeroCuenta = DB::table('accounts')->where('name', $request->account_id)->value('account_number');

        $nombreCuenta = strtoupper(Str::ascii($request->account_id));
        $proyecto = strtoupper($request->project);

        $codigo = 'CAJA CHICA ' . ($request->reposition_id ? $request->reposition_id : '') . $request->unique_id;

        $proveedor = $request->type === 'expense' ? 'CAJA CHICA' : ($request->type === 'discount' ? 'DESCUENTOS' : 'CAJA CHICA');
        $tipo = $request->type === 'expense' ? 'GASTO' : ($request->type === 'discount' ? 'DESCUENTO' : 'GASTO');

        CajaChica::updateOrCreate(
            ['id' => $request->id],
            [
                'fecha'             => $request->request_date,
                'codigo'            => $codigo,
                'descripcion'       => $request->note,
                'saldo'             => $request->amount,
                'centro_costo'      => $centroCosto,
                'cuenta'            => $numeroCuenta,
                'nombre_de_cuenta'  => $nombreCuenta,
                'proveedor'         => $proveedor,
                'empresa'           => 'SERSUPPORT',
                'proyecto'          => $proyecto,
                'i_e'               => 'EGRESO',
                'mes_servicio'      => $mesServicio, // Ahora es '2025-04-16' en lugar de '16/4/2025'
                'tipo'              => $tipo,
                'estado'            => $request->status,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]
        );
    }

    DB::commit();
    echo "✅ Migración completada correctamente.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ Ocurrió un error: " . $e->getMessage() . "\n";
}
