<?php

namespace App\Imports;

use App\Models\Request;
use App\Models\Account;
use App\Models\Project;
use App\Models\UserAssignedProjects;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class RequestsImport implements ToModel, WithStartRow, WithChunkReading, SkipsEmptyRows
{
    private $context;
    private $accounts;
    private $projects;
    private $personals;
    private $vehicles;
    private $nextSequence;
    public $errors = [];
    private $rowNumber = 0;
    private $processedRows = 0;
    private $userProjects;
    private $shouldStop = false;
    protected $nextId;

    public function __construct(string $context = 'discounts', $userId = null)
    {
        $this->context = $context;

        try {
            // Fetch and normalize account names
            $this->accounts = Account::where('account_status', 'active')
                ->whereIn('account_affects', $this->context === 'discounts' ? ['discount', 'both'] : ['expense', 'both'])
                ->pluck('id', 'name')
                ->mapWithKeys(function ($id, $name) {
                    $normalizedName = preg_replace('/\s+/', ' ', trim(strtolower($name)));
                    return [$normalizedName => $id];
                })->toArray();

            Log::info("Cuentas cargadas para importación:", ['accounts' => $this->accounts]);

            $this->accounts = Account::where('account_status', 'active')
                ->whereIn('account_affects', $this->context === 'discounts' ? ['discount', 'both'] : ['expense'])
                ->pluck('id', 'name')
                ->toArray();

            $this->projects = Project::pluck('id', 'name')->toArray();

            $this->personals = DB::connection('sistema_onix')
                ->table('onix_personal')
                ->pluck('id', 'name')
                ->toArray();

            $this->vehicles = DB::connection('sistema_onix')
                ->table('onix_vehiculos')
                ->pluck('id', 'name')
                ->toArray();

            $this->projects = Project::all()->pluck('id', 'name')->mapWithKeys(function ($id, $name) {
                return [trim(strtolower($name)) => $id];
            })->toArray();

            $prefix = $this->context === 'discounts' ? 'D-' : 'G-';
            $lastRequest = Request::where('unique_id', 'like', "{$prefix}%")
                ->orderBy('unique_id', 'desc')
                ->first();

            $this->nextSequence = $lastRequest
                ? (int) substr($lastRequest->unique_id, 2) + 1
                : 1;

            if ($userId) {
                $userProjects = UserAssignedProjects::where('user_id', $userId)->first();
                $this->userProjects = $userProjects ? $userProjects->projects : [];
            } else {
                $this->userProjects = [];
            }

            Log::info("Iniciando importación con contexto: {$context}, próximo ID: {$this->nextSequence}");
        } catch (\Exception $e) {
            Log::error("Error al inicializar importación: " . $e->getMessage());
            throw new \Exception("No se pudo inicializar la importación. Contacte al administrador.");
        }
    }

    public function startRow(): int
    {
        return 3;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function model(array $row)
    {
        $this->rowNumber++; // Increment row number for error reporting

        // Map Excel columns to meaningful keys (adjust based on your column mapping)
        $mappedRow = [
            'fecha' => $row['fecha'] ?? null,
            'personnel_type' => $row['personnel_type'] ?? null,
            'no_factura' => $row['no_factura'] ?? null,
            'cuenta' => $row['cuenta'] ?? null,
            'valor' => $row['valor'] ?? null,
            'proyecto' => $row['proyecto'] ?? null,
            'responsable' => $row['responsable'] ?? null,
            'placa' => $row['placa'] ?? null,
            'cedula' => $row['cedula'] ?? null,
            'observacion' => $row['observacion'] ?? null,
        ];

        // Skip header or descriptive rows
        if ($this->rowNumber === 1 && ($mappedRow['fecha'] === 'Fecha' || empty($mappedRow['valor']))) {
            Log::info("Ignorando fila descriptiva o encabezado:", $mappedRow);
            return null;
        }

        // Initialize errors array
        $errors = [];

        // Normalize and find account ID
        $accountName = preg_replace('/\s+/', ' ', trim(strtolower($mappedRow['cuenta'])));
        Log::info("Buscando cuenta en fila {$this->rowNumber}:", [
            'input' => $accountName,
            'available' => array_keys($this->accounts)
        ]);

        $accountId = $this->accounts[$accountName] ?? null;
        if (!$accountId) {
            $errors[] = "Fila {$this->rowNumber}: Cuenta '{$mappedRow['cuenta']}' no encontrada";
        } else {
            Log::info("Cuenta encontrada en fila {$this->rowNumber}:", ['account_id' => $accountId]);
        }

        // Basic validations
        if (empty($mappedRow['no_factura'])) {
            $errors[] = "Fila {$this->rowNumber}: El número de factura no puede estar vacío";
        }

        if (empty($mappedRow['valor']) || !is_numeric($mappedRow['valor'])) {
            $errors[] = "Fila {$this->rowNumber}: El valor debe ser un número válido";
        }

        // Validate Excel date
        if (empty($mappedRow['fecha']) || !is_numeric($mappedRow['fecha']) || $mappedRow['fecha'] < 1) {
            $errors[] = "Fila {$this->rowNumber}: La fecha no es válida";
        } else {
            try {
                $date = Date::excelToDateTimeObject($mappedRow['fecha']);
                if (!$date instanceof \DateTime) {
                    $errors[] = "Fila {$this->rowNumber}: La fecha no es válida";
                }
            } catch (\Exception $e) {
                $errors[] = "Fila {$this->rowNumber}: La fecha no es válida ({$e->getMessage()})";
            }
        }

        // Prepare request data with raw values
        $requestData = [
            'unique_id' => sprintf("G-%05d", $this->nextId++),
            'type' => $this->context, // 'expense' or 'discounts' from constructor
            'personnel_type' => $mappedRow['personnel_type'],
            'status' => 'pending',
            'request_date' => isset($date) ? \Carbon\Carbon::instance($date)->toDateString() : null,
            'invoice_number' => $mappedRow['no_factura'],
            'account_id' => $accountId, // Still an ID, not raw name
            'amount' => floatval($mappedRow['valor']),
            'project' => $mappedRow['proyecto'], // Raw name, e.g., "CNYA"
            'responsible_id' => $mappedRow['responsable'], // Raw name, e.g., "MORA CASTILLO CRISTOFER HERNANDO"
            'transport_id' => $mappedRow['placa'] ?? null, // Assuming plate number, e.g., "EUBA3845"
            'note' => $mappedRow['observacion'] ?? null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        // Log potential data for debugging
        Log::info("Datos potenciales para fila {$this->rowNumber} antes de validaciones:", $requestData);

        // Throw exception if there are errors
        if (!empty($errors)) {
            Log::warning("Errores en fila {$this->rowNumber}:", $errors);
            throw new \Exception(implode(", ", $errors));
        }

        // Return new Request instance
        return new Request($requestData);
    }

    private function normalizePlate(string $plate): string
    {
        return str_replace([' ', '-'], '', trim($plate));
    }

    private function normalizePersonnelType(?string $type): string
    {
        if (!$type) return '';
        return str_replace('ó', 'o', strtolower(trim($type)));
    }

    private function parseDate($date): string
    {
        if (is_numeric($date)) {
            return Date::excelToDateTimeObject($date)->format('Y-m-d');
        }
        return date('Y-m-d', strtotime($date));
    }
}
