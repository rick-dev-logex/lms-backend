<?php
// Guarda este archivo como fix_requests_table.php en la raíz de tu proyecto Laravel
// Ejecútalo con: php fix_requests_table.php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

class RequestsTableFixer
{
    private $fixedRecords = [
        'account_id' => 0,
        'project' => 0,
        'responsible_id' => 0,
        'cedula_responsable' => 0,
        'null_fields' => 0
    ];

    private $totalRecords = 0;

    public function run()
    {
        echo "Iniciando corrección de la tabla requests...\n";

        // Verificar que las tablas necesarias existen
        if (!$this->checkTables()) {
            echo "ERROR: Faltan tablas necesarias. Abortando.\n";
            return;
        }

        // Verificar que existe la columna cedula_responsable
        if (!$this->checkCedulaResponsableColumn()) {
            echo "ERROR: No existe la columna cedula_responsable en la tabla requests. Abortando.\n";
            return;
        }

        $this->totalRecords = DB::connection('lms_backend')->table('requests')->count();
        echo "Total de registros a procesar: {$this->totalRecords}\n";

        try {
            // Iniciar transacción para asegurar consistencia
            DB::connection('lms_backend')->beginTransaction();

            // 1. Corregir account_id (de ID a nombre)
            $this->fixAccountIds();

            // 2. Corregir project (de UUID a nombre)
            $this->fixProjects();

            // 3. Corregir responsible_id (de UUID a nombre completo)
            $this->fixResponsibleIds();

            // 4. Actualizar cedula_responsable desde nombre
            $this->updateCedulaResponsable();

            // 5. Reemplazar valores NULL
            $this->fixNullValues();

            // Confirmar los cambios
            DB::connection('lms_backend')->commit();

            echo "\n¡Corrección completada exitosamente!\n";
            echo "Resumen de cambios:\n";
            echo "- Accounts corregidos: {$this->fixedRecords['account_id']} de {$this->totalRecords}\n";
            echo "- Projects corregidos: {$this->fixedRecords['project']} de {$this->totalRecords}\n";
            echo "- Responsible IDs corregidos: {$this->fixedRecords['responsible_id']} de {$this->totalRecords}\n";
            echo "- Cédulas actualizadas: {$this->fixedRecords['cedula_responsable']} de {$this->totalRecords}\n";
            echo "- Campos NULL reemplazados: {$this->fixedRecords['null_fields']}\n";
        } catch (\Exception $e) {
            // Revertir cambios en caso de error
            DB::connection('lms_backend')->rollBack();
            echo "ERROR: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        }
    }

    private function checkTables()
    {
        // Verificar que existen las tablas necesarias usando consultas directas
        // en lugar de Schema, para evitar problemas de compatibilidad

        try {
            // Verificar tabla requests
            $requestsExist = DB::connection('lms_backend')
                ->select("SHOW TABLES LIKE 'requests'");
            if (empty($requestsExist)) {
                echo "No se encontró la tabla 'requests' en la base de datos 'lms_backend'\n";
                return false;
            }

            // Verificar tabla accounts
            $accountsExist = DB::connection('lms_backend')
                ->select("SHOW TABLES LIKE 'accounts'");
            if (empty($accountsExist)) {
                echo "No se encontró la tabla 'accounts' en la base de datos 'lms_backend'\n";
                return false;
            }

            // Verificar tabla onix_proyectos en sistema_onix
            $proyectosExist = DB::connection('sistema_onix')
                ->select("SHOW TABLES LIKE 'onix_proyectos'");
            if (empty($proyectosExist)) {
                echo "No se encontró la tabla 'onix_proyectos' en la base de datos 'sistema_onix'\n";
                return false;
            }

            // Verificar tabla onix_personal en sistema_onix
            $personalExist = DB::connection('sistema_onix')
                ->select("SHOW TABLES LIKE 'onix_personal'");
            if (empty($personalExist)) {
                echo "No se encontró la tabla 'onix_personal' en la base de datos 'sistema_onix'\n";
                return false;
            }

            return true;
        } catch (\Exception $e) {
            echo "Error al verificar tablas: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function checkCedulaResponsableColumn()
    {
        // Verificar si existe la columna cedula_responsable usando consulta directa
        $columnsResult = DB::connection('lms_backend')
            ->select("SHOW COLUMNS FROM requests LIKE 'cedula_responsable'");

        if (empty($columnsResult)) {
            echo "No se encontró la columna cedula_responsable. Intentando crearla...\n";

            try {
                DB::connection('lms_backend')
                    ->statement("ALTER TABLE requests ADD COLUMN cedula_responsable VARCHAR(255) NULL");
                echo "Columna cedula_responsable creada exitosamente.\n";
                return true;
            } catch (\Exception $e) {
                echo "ERROR al crear la columna cedula_responsable: " . $e->getMessage() . "\n";
                return false;
            }
        }

        return true;
    }

    private function fixAccountIds()
    {
        echo "\nActualizando account_id de ID a nombre...\n";

        // Cargar todos los accounts para mapeo rápido
        $accounts = DB::connection('lms_backend')->table('accounts')->pluck('name', 'id')->toArray();

        if (empty($accounts)) {
            echo "ADVERTENCIA: No se encontraron cuentas en la tabla accounts\n";
            return;
        }

        // Obtener todos los requests para actualizar
        $requests = DB::connection('lms_backend')->table('requests')
            ->whereNotNull('account_id')
            ->whereRaw("account_id REGEXP '^[0-9]+$'") // Solo IDs numéricos
            ->get(['id', 'account_id']);

        $updatedCount = 0;
        $errorCount = 0;

        foreach ($requests as $request) {
            try {
                // Verificar si el account_id es numérico y existe en el mapeo
                if (is_numeric($request->account_id) && isset($accounts[$request->account_id])) {
                    // Actualizar el registro con el nombre de la cuenta
                    DB::connection('lms_backend')->table('requests')
                        ->where('id', $request->id)
                        ->update(['account_id' => $accounts[$request->account_id]]);

                    $updatedCount++;

                    // Mostrar progreso
                    if ($updatedCount % 100 === 0) {
                        echo "Procesados $updatedCount registros...\n";
                    }
                } else {
                    // Reportar si no se encuentra la cuenta
                    echo "ADVERTENCIA: No se encontró nombre para account_id {$request->account_id}\n";
                    $errorCount++;
                }
            } catch (\Exception $e) {
                echo "ERROR en request ID {$request->id}: " . $e->getMessage() . "\n";
                $errorCount++;
            }
        }

        $this->fixedRecords['account_id'] = $updatedCount;
        echo "Completado account_id: $updatedCount actualizados, $errorCount errores\n";
    }

    private function fixProjects()
    {
        echo "\nActualizando project de UUID a nombre...\n";

        // Obtener todos los requests para actualizar (solo los que tienen UUIDs)
        $uuidPattern = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';
        $requests = DB::connection('lms_backend')->table('requests')
            ->whereNotNull('project')
            ->whereRaw("project REGEXP '$uuidPattern'") // Solo UUIDs
            ->get(['id', 'project']);

        $updatedCount = 0;
        $errorCount = 0;

        foreach ($requests as $request) {
            try {
                // Buscar el nombre del proyecto por su UUID
                $proyecto = DB::connection('sistema_onix')
                    ->table('onix_proyectos')
                    ->where('id', $request->project)
                    ->first(['name']);

                if ($proyecto && $proyecto->name) {
                    // Actualizar el registro con el nombre del proyecto
                    DB::connection('lms_backend')->table('requests')
                        ->where('id', $request->id)
                        ->update(['project' => $proyecto->name]);

                    $updatedCount++;

                    // Mostrar progreso
                    if ($updatedCount % 100 === 0) {
                        echo "Procesados $updatedCount registros...\n";
                    }
                } else {
                    // Reportar si no se encuentra el proyecto
                    echo "ADVERTENCIA: No se encontró nombre para project UUID {$request->project}\n";
                    $errorCount++;
                }
            } catch (\Exception $e) {
                echo "ERROR en request ID {$request->id}: " . $e->getMessage() . "\n";
                $errorCount++;
            }
        }

        $this->fixedRecords['project'] = $updatedCount;
        echo "Completado project: $updatedCount actualizados, $errorCount errores\n";
    }

    private function fixResponsibleIds()
    {
        echo "\nActualizando responsible_id de UUID a nombre completo...\n";

        // Obtener todos los requests para actualizar (solo los que tienen UUIDs)
        $uuidPattern = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';
        $requests = DB::connection('lms_backend')->table('requests')
            ->whereNotNull('responsible_id')
            ->whereRaw("responsible_id REGEXP '$uuidPattern'") // Solo UUIDs
            ->get(['id', 'responsible_id']);

        $updatedCount = 0;
        $errorCount = 0;

        foreach ($requests as $request) {
            try {
                // Buscar el nombre completo del responsable por su UUID
                $persona = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->where('id', $request->responsible_id)
                    ->first(['nombre_completo', 'name']);

                if ($persona && $persona->nombre_completo) {
                    // Actualizar el registro con el nombre completo del responsable
                    DB::connection('lms_backend')->table('requests')
                        ->where('id', $request->id)
                        ->update([
                            'responsible_id' => $persona->nombre_completo,
                            'cedula_responsable' => $persona->name // Actualizar también la cédula
                        ]);

                    $updatedCount++;

                    // Mostrar progreso
                    if ($updatedCount % 100 === 0) {
                        echo "Procesados $updatedCount registros...\n";
                    }
                } else {
                    // Reportar si no se encuentra la persona
                    echo "ADVERTENCIA: No se encontró nombre para responsible_id UUID {$request->responsible_id}\n";
                    $errorCount++;
                }
            } catch (\Exception $e) {
                echo "ERROR en request ID {$request->id}: " . $e->getMessage() . "\n";
                $errorCount++;
            }
        }

        $this->fixedRecords['responsible_id'] = $updatedCount;
        echo "Completado responsible_id: $updatedCount actualizados, $errorCount errores\n";
    }

    private function updateCedulaResponsable()
    {
        echo "\nActualizando cedula_responsable según el nombre del responsable...\n";

        // Obtener todos los requests que tienen responsible_id pero no cedula_responsable
        $requests = DB::connection('lms_backend')->table('requests')
            ->whereNotNull('responsible_id')
            ->where(function ($query) {
                $query->whereNull('cedula_responsable')
                    ->orWhere('cedula_responsable', '');
            })
            ->whereRaw("responsible_id REGEXP '[A-Za-z]'") // Solo nombres, no UUIDs
            ->get(['id', 'responsible_id']);

        $updatedCount = 0;
        $errorCount = 0;

        foreach ($requests as $request) {
            try {
                // Buscar la cédula por el nombre completo
                $persona = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->where('nombre_completo', $request->responsible_id)
                    ->first(['name']);

                if ($persona && $persona->name) {
                    // Actualizar el registro con la cédula del responsable
                    DB::connection('lms_backend')->table('requests')
                        ->where('id', $request->id)
                        ->update(['cedula_responsable' => $persona->name]);

                    $updatedCount++;

                    // Mostrar progreso
                    if ($updatedCount % 100 === 0) {
                        echo "Procesados $updatedCount registros...\n";
                    }
                } else {
                    // Intentar buscar con LIKE por si hay diferencias menores
                    $personaLike = DB::connection('sistema_onix')
                        ->table('onix_personal')
                        ->whereRaw("LOWER(nombre_completo) LIKE ?", ['%' . strtolower($request->responsible_id) . '%'])
                        ->first(['name', 'nombre_completo']);

                    if ($personaLike && $personaLike->name) {
                        // Actualizar el registro con la cédula del responsable
                        DB::connection('lms_backend')->table('requests')
                            ->where('id', $request->id)
                            ->update([
                                'cedula_responsable' => $personaLike->name,
                                'responsible_id' => $personaLike->nombre_completo // Actualizar también el nombre para consistencia
                            ]);

                        $updatedCount++;
                        echo "Encontrado por coincidencia parcial: '{$request->responsible_id}' -> '{$personaLike->nombre_completo}'\n";
                    } else {
                        // Reportar si no se encuentra la persona
                        echo "ADVERTENCIA: No se encontró cédula para responsable '{$request->responsible_id}'\n";
                        $errorCount++;
                    }
                }
            } catch (\Exception $e) {
                echo "ERROR en request ID {$request->id}: " . $e->getMessage() . "\n";
                $errorCount++;
            }
        }

        $this->fixedRecords['cedula_responsable'] = $updatedCount;
        echo "Completado cedula_responsable: $updatedCount actualizados, $errorCount errores\n";
    }

    private function fixNullValues()
    {
        echo "\nReemplazando valores NULL en responsible_id, vehicle_plate, vehicle_number y cedula_responsable...\n";

        $nullReplacements = [
            'responsible_id' => '—',
            'vehicle_plate' => '—',
            'vehicle_number' => '—',
            'cedula_responsable' => '—'
        ];

        $totalUpdated = 0;

        foreach ($nullReplacements as $column => $replacement) {
            $affected = DB::connection('lms_backend')->table('requests')
                ->whereNull($column)
                ->update([$column => $replacement]);

            echo "- Campo $column: $affected registros actualizados\n";
            $totalUpdated += $affected;
        }

        $this->fixedRecords['null_fields'] = $totalUpdated;
        echo "Completado reemplazo de NULLs: $totalUpdated campos actualizados\n";
    }
}

// Ejecutar el script
$fixer = new RequestsTableFixer();
$fixer->run();
