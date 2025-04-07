<?php

use App\Models\CajaChica;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

require __DIR__ . '/vendor/autoload.php';

// Cargar variables de entorno desde el archivo .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Configurar la conexión a la base de datos de producción manualmente
DB::purge('lms_backend'); // Limpiar cualquier conexión previa
DB::setDefaultConnection('lms_backend');

config([
    'database.connections.lms_backend' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', 'sgt.logex.com.ec'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'lms_backend'),
        'username' => env('DB_USERNAME', 'restrella'),
        'password' => env('DB_PASSWORD', 'LogeX-?2028*'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
    ],
]);

echo "Iniciando migración a caja_chica desde local a producción...\n";

try {
    $requests = DB::connection("lms_backend")->table('requests')->get();
    echo "Total de solicitudes encontradas: " . $requests->count() . "\n";

    $meses = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

    DB::beginTransaction();

    $exampleRequests = DB::connection("lms_backend")->table('requests')->take(5)->get();
    echo "Ejemplo de solicitudes:\n";
    foreach ($exampleRequests as $request) {
        echo "ID: {$request->id}, Fecha: {$request->request_date}, Monto: {$request->amount}, Proyecto: {$request->project}\n";
    }

    foreach ($requests as $request) {
        echo "Procesando ID: {$request->id}, request_date: '{$request->request_date}'\n";

        // Convertir request_date a un objeto Carbon
        $fecha = Carbon::parse($request->request_date);
        $centroCosto = $meses[$fecha->month - 1] . ' ' . $fecha->year;
        $mesServicio = $fecha->format('Y-m-d');

        $numeroCuenta = DB::table('accounts')->where('name', $request->account_id)->value('account_number');

        $nombreCuenta = strtoupper(Str::ascii($request->account_id));
        $proyecto = strtoupper($request->project);

        $codigo = 'CAJA CHICA ' . ($request->reposicion_id ? $request->reposicion_id . " " : '') . $request->unique_id;

        $proveedor = $request->type === 'expense' ? 'CAJA CHICA' : ($request->type === 'discount' ? 'DESCUENTOS' : 'CAJA CHICA');
        $tipo = $request->type === 'expense' ? 'GASTO' : ($request->type === 'discount' ? 'DESCUENTO' : 'GASTO');

        CajaChica::updateOrCreate(
            ['id' => $request->id],
            [
                'FECHA'             => $request->request_date,
                'CODIGO'            => $codigo,
                'DESCRIPCION'       => $request->note,
                'SALDO'             => $request->amount,
                'CENTRO_COSTO'      => $centroCosto,
                'CUENTA'            => $numeroCuenta ?? '—', // Valor por defecto si no hay número de cuenta
                'NOMBRE_DE_CUENTA'  => $nombreCuenta,
                'PROVEEDOR'         => $proveedor,
                'EMPRESA'           => 'SERSUPPORT',
                'PROYECTO'          => $proyecto,
                'I_E'               => 'EGRESO',
                'MES_SERVICIO'      => $mesServicio,
                'TIPO'              => $tipo,
                'ESTADO'            => $request->status,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]
        );
    }

    DB::commit();
    echo "✅ Migración completada correctamente.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Ocurrió un error: " . $e->getMessage() . "\n";
}
