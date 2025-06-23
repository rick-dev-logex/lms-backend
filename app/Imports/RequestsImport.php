<?php

namespace App\Imports;

use App\Models\Account;
use App\Models\Request;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UniqueIdService;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;
use Maatwebsite\Excel\Events\AfterImport;
use Str;

class RequestsImport implements ToModel, WithStartRow, WithChunkReading, SkipsEmptyRows, WithEvents, WithBatchInserts
{
    protected $rowNumber = 0;
    protected $context;
    protected $userId;
    protected $uniqueIdService;
    public $errors = []; // Para acumular errores
    public $validRows = []; // Para acumular las filas validas
    protected int $uniqueIdCounter;

    public function __construct(
        string $context,
        int $userId,
        UniqueIdService $uniqueIdService,
    ) {
        $this->context = $this->normalizeContext($context);
        $this->userId = $userId;
        $this->uniqueIdService = $uniqueIdService;

        // Obtener el último número base una vez y preparar contador para el lote
        $this->uniqueIdCounter = $this->uniqueIdService->getNextBaseNumber($this->context);
    }

    private function normalizeContext(string $context): string
    {
        $allowedContexts = ['expense', 'discount', 'income'];
        $normalized = strtolower($context);

        if ($normalized === 'expenses') {
            return 'expense';
        }
        if ($normalized === 'discounts') {
            return 'discount';
        }
        return in_array($normalized, $allowedContexts) ? $normalized : 'discount';
    }

    public function startRow(): int
    {
        return 4;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function model(array $row)
    {
        $this->rowNumber++;

        // Validaciones básicas y mapeo
        if (empty($row) || (count($row) === 1) || (count($row) > 1 && count(array_filter($row)) <= 1)) {
            return null;
        }

        if (count($row) < 10) {
            $error = "Fila {$this->rowNumber}: Datos incompletos, se requieren al menos 10 columnas";
            $this->errors[] = $error;
            Log::warning($error, $row);
            return null;
        }

        $mappedRow = [
            'fecha' => $row[0] ?? null,
            'personnel_type' => $row[1] ?? null,
            'no_factura' => $row[2] ?? null,
            'cuenta' => $row[3] ?? null,
            'valor' => $row[4] ?? null,
            'proyecto' => $row[5] ?? null,
            'responsable' => $row[6] ?? null,
            'vehicle_plate' => $row[7] ?? null,
            'cedula_responsable' => $row[8] ?? null,
            'note' => $row[9] ?? "—",
        ];

        $rowErrors = [];

        if (empty($mappedRow['personnel_type'])) {
            $rowErrors[] = "Falta el tipo de personal";
        }

        if (empty($mappedRow['no_factura'])) {
            $rowErrors[] = "Falta el número de factura";
        }

        if (!is_numeric($mappedRow['valor'])) {
            $rowErrors[] = "El valor no es numérico";
        }

        if (empty($mappedRow['proyecto'])) {
            $rowErrors[] = "Falta el proyecto";
        }

        $date = null;
        // Convertir la fecha a Carbon para que funcione between()
        if (is_numeric($mappedRow['fecha']) && $mappedRow['fecha'] > 0) {
            try {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($mappedRow['fecha']);
                $date = Carbon::instance($date); // <-- Convertir DateTime a Carbon
            } catch (Exception $e) {
                $rowErrors[] = "Fecha Excel inválida: " . $e->getMessage();
            }
        } else if (is_string($mappedRow['fecha']) && !empty($mappedRow['fecha'])) {
            try {
                $date = Carbon::parse($mappedRow['fecha']);
                if (!$date->isValid()) {
                    $rowErrors[] = "Fecha inválida";
                }
            } catch (Exception $e) {
                $rowErrors[] = "Fecha string inválida: " . $e->getMessage();
            }
        }

        if (!empty($mappedRow['cedula_responsable']) && (!is_numeric($mappedRow['cedula_responsable']) || strlen((string)$mappedRow['cedula_responsable']) < 10)) {
            $rowErrors[] = "Cédula del responsable inválida";
        }

        if (!empty($rowErrors) || $date === null) {
            $error = "Fila {$this->rowNumber}: " . implode(", ", $rowErrors);
            $this->errors[] = $error;
            Log::warning($error);
            return null;
        }

        // Consultas y validaciones adicionales sin lanzar excepciones, acumulando errores

        $cedulaOnix = DB::connection('sistema_onix')->table('onix_personal')->where('name', $mappedRow['cedula_responsable'])->value('nombre_completo');
        if ($cedulaOnix !== $mappedRow['responsable']) {
            $updatedRowNumber = $this->rowNumber + 3;
            $error = "Fila {$updatedRowNumber}: La cédula '{$mappedRow['cedula_responsable']}' no corresponde a '{$mappedRow['responsable']}'";
            $this->errors[] = $error;
            Log::warning($error);
            return null;
        }

        $estado = DB::connection('sistema_onix')->table('onix_personal')->where('name', $mappedRow['cedula_responsable'])->value('estado_personal');
        if ($mappedRow['personnel_type'] != "Transportista" && $estado !== "activo") {
            $error = "Fila {$this->rowNumber}: No puedes realizar operaciones con personal cesante";
            $this->errors[] = $error;
            Log::warning($error);
            return null;
        }

        $proyecto = DB::connection('sistema_onix')->table('onix_personal')->where('name', $mappedRow['cedula_responsable'])->value('proyecto');
        if ($mappedRow['personnel_type'] != "Transportista" && $proyecto !== $mappedRow['proyecto']) {
            $error = "Fila {$this->rowNumber}: {$mappedRow['responsable']} no pertenece al proyecto {$mappedRow['proyecto']}.";
            $this->errors[] = $error;
            Log::warning($error);
            return null;
        }

        $normalizedInput = $this->normalize($mappedRow['cuenta']);
        $cuenta = Account::all()->first(function ($account) use ($normalizedInput) {
            return $this->normalize($account->name) === $normalizedInput;
        });

        if (!$cuenta) {
            $error = "Fila {$this->rowNumber}: La cuenta '{$mappedRow['cuenta']}' no existe en el sistema. Asegúrate de escribirla correctamente.";
            $this->errors[] = $error;
            Log::warning($error);
            return null;
        }

        $requestData = [
            'unique_id' => $this->uniqueIdService->generateUniqueRequestId($this->context, $this->uniqueIdCounter++),
            'type' => $this->context,
            'personnel_type' => $mappedRow['personnel_type'],
            'status' => 'pending',
            'request_date' => $date instanceof \DateTime ? $date->format('Y-m-d') : null,
            'invoice_number' => $mappedRow['no_factura'],
            'account_id' => $cuenta->name, // nombre oficial
            'amount' => floatval($mappedRow['valor']),
            'project' => $mappedRow['proyecto'],
            'responsible_id' => $mappedRow['responsable'],
            'vehicle_plate' => $mappedRow['vehicle_plate'],
            'cedula_responsable' => $mappedRow['cedula_responsable'],
            'note' => $mappedRow['note'] ?? "—",
            'created_by' => User::find($this->userId)->name,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        // Validaciones de fechas según contexto
        $today = Carbon::today();

        if ($this->context === 'expense') {
            $firstAllowed = $today->copy()->subMonth()->startOfMonth();
            $lastAllowed = $today->copy()->startOfMonth()->addDays(5);

            if ($today->day >= 6) {
                $firstAllowed = $today->copy()->startOfMonth();
                $lastAllowed = $today->copy()->addMonth()->startOfMonth()->addDays(5);
            }

            if (!$date->between($firstAllowed, $lastAllowed)) {
                $error = "Fila {$this->rowNumber}: Fecha de gasto fuera del rango permitido ({$firstAllowed->format('Y-m-d')} al {$lastAllowed->format('Y-m-d')})";
                $this->errors[] = $error;
                Log::warning($error);
                return null;
            }
        } elseif ($this->context === 'discount') {
            $firstAllowed = $today->copy()->subMonth()->day(29);
            $lastAllowed = $today->copy()->day(28);

            if ($today->day >= 29) {
                $firstAllowed = $today->copy()->subMonth()->day(28);
            }

            if (!$date->between($firstAllowed, $lastAllowed)) {
                $error = "Fila {$this->rowNumber}: Fecha de descuento fuera del rango permitido ({$firstAllowed->format('Y-m-d')} al {$lastAllowed->format('Y-m-d')})";
                $this->errors[] = $error;
                Log::warning($error);
                return null;
            }
        }

        // Esto solo inserta si es que TODAS las filas son validas, no inserta parcialmente.
        $this->validRows[] = $requestData;
        return null; // Ya no insertamos aquí
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(\Illuminate\Support\Str::ascii(trim($text)));
    }

    public function registerEvents(): array
    {
        return [
            AfterImport::class => function (AfterImport $event) {
                if (!empty($this->validRows)) {
                    try {
                        Request::insert($this->validRows); // Inserción masiva
                        Log::info("Se insertaron " . count($this->validRows) . " solicitudes correctamente.");
                    } catch (\Exception $e) {
                        Log::error("Error al insertar las solicitudes: " . $e->getMessage());
                        $this->errors[] = $e->getMessage();
                    }
                }
            },
        ];
    }

    public function batchSize(): int
    {
        return 1000;
    }

    public function insertValidRows()
    {
        if (count($this->errors) === 0 && count($this->validRows) > 0) {
            try {
                DB::table('requests')->insert($this->validRows);
            } catch (\Exception $e) {
                $this->errors[] = $e->getMessage();
                Log::error($e->getMessage());
            }
        }
    }
}
