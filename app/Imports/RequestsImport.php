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

    public function __construct(string $context = 'discounts', $userId = null)
    {
        $this->context = $context;

        try {
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
            Log::info("Proyectos disponibles: " . json_encode($this->projects));
            Log::info("Proyectos asignados al usuario: " . json_encode($this->userProjects));
            Log::info("Personals cargados: " . json_encode($this->personals));
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
        if ($this->shouldStop) {
            return null;
        }

        $this->rowNumber++;

        $mappedRow = [
            'fecha' => $row[0] ?? null,
            'personnel_type' => $this->normalizePersonnelType($row[1] ?? null),
            'no_factura' => $row[2] ?? null,
            'cuenta' => $row[3] ?? null,
            'valor' => $row[4] ?? null,
            'proyecto' => $row[5] ?? null,
            'responsable' => $row[6] ?? null,
            'placa' => $row[7] ?? null,
            'cedula' => $row[8] ?? null,
            'observacion' => $row[9] ?? null,
        ];

        Log::info("Fila {$this->rowNumber} mapeada: " . json_encode($mappedRow));

        if (strtolower($mappedRow['fecha']) === 'fecha' || stripos($mappedRow['fecha'], 'completa_una_fila') !== false) {
            Log::info("Ignorando fila descriptiva o encabezado: " . json_encode($mappedRow));
            return null;
        }

        $requiredFields = ['fecha', 'personnel_type', 'valor'];
        $hasRequiredFields = array_reduce($requiredFields, function ($carry, $field) use ($mappedRow) {
            return $carry && !empty($mappedRow[$field]);
        }, true);

        if (!$hasRequiredFields) {
            Log::info("Fila {$this->rowNumber} incompleta, ignorada: " . json_encode($mappedRow));
            $this->shouldStop = true;
            return null;
        }

        $errors = [];

        if (empty($mappedRow['fecha'])) {
            $errors[] = "Fila {$this->rowNumber}: Falta la fecha";
        }

        if (empty($mappedRow['personnel_type'])) {
            $errors[] = "Fila {$this->rowNumber}: Falta el tipo de personal";
        } elseif (!in_array($mappedRow['personnel_type'], ['nomina', 'transportista'])) {
            $errors[] = "Fila {$this->rowNumber}: El tipo de personal debe ser 'nomina' o 'transportista'";
        }

        if (empty($mappedRow['valor']) || !is_numeric($mappedRow['valor'])) {
            $errors[] = "Fila {$this->rowNumber}: Falta el valor o no es numérico";
        }

        if (empty($mappedRow['cuenta'])) {
            $errors[] = "Fila {$this->rowNumber}: Falta la cuenta";
        }

        if (empty($mappedRow['proyecto'])) {
            $errors[] = "Fila {$this->rowNumber}: Falta el proyecto";
        }

        $projectName = strtolower(trim($mappedRow['proyecto']));
        $userProjectNames = array_map('strtolower', array_map(function ($project) {
            return array_search($project, $this->projects) ?: $project;
        }, $this->userProjects));
        Log::info("Proyectos asignados convertidos a nombres: " . json_encode($userProjectNames));
        if (!in_array($projectName, $userProjectNames)) {
            $errors[] = "Fila {$this->rowNumber}: Proyecto '{$mappedRow['proyecto']}' no está asignado al usuario";
        }
        $projectId = $this->projects[$mappedRow['proyecto']] ?? null;
        if (!$projectId) {
            $errors[] = "Fila {$this->rowNumber}: Proyecto '{$mappedRow['proyecto']}' no encontrado en la base de datos";
        }

        $accountName = trim($mappedRow['cuenta']);
        $accountId = $this->accounts[$accountName] ?? null;
        if (!$accountId) {
            $errors[] = "Fila {$this->rowNumber}: Cuenta '{$accountName}' no encontrada";
        }

        $responsibleId = null;
        $cedula = trim($mappedRow['cedula'] ?? '');
        if ($mappedRow['personnel_type'] === 'nomina') {
            if (empty($cedula)) {
                $errors[] = "Fila {$this->rowNumber}: Cédula requerida para tipo 'nomina'";
            } else {
                $responsibleId = $this->personals[$cedula] ?? null;
                if (!$responsibleId) {
                    $errors[] = "Fila {$this->rowNumber}: Responsable con cédula '{$cedula}' no encontrado";
                }
            }
        }

        $transportId = null;
        $plate = $this->normalizePlate($mappedRow['placa'] ?? '');
        if ($mappedRow['personnel_type'] === 'transportista') {
            if (empty($plate)) {
                $errors[] = "Fila {$this->rowNumber}: Placa requerida para tipo 'transportista'";
            } else {
                $transportId = $this->vehicles[$plate] ?? null;
                if (!$transportId) {
                    $errors[] = "Fila {$this->rowNumber}: Vehículo no encontrado con placa '{$plate}'";
                }
            }
        }

        $prefix = $this->context === 'discounts' ? 'D-' : 'G-';
        $uniqueId = $this->nextSequence <= 9999
            ? sprintf('%s%05d', $prefix, $this->nextSequence)
            : sprintf('%s%d', $prefix, $this->nextSequence);

        $potentialRequestData = [
            'unique_id' => $uniqueId,
            'type' => $this->context === 'discounts' ? 'discount' : 'expense',
            'personnel_type' => $mappedRow['personnel_type'],
            'status' => 'pending',
            'request_date' => $this->parseDate($mappedRow['fecha']),
            'invoice_number' => $mappedRow['no_factura'] ?? null,
            'account_id' => $accountId,
            'amount' => floatval($mappedRow['valor']),
            'project' => $projectName,
            'responsible_id' => $responsibleId,
            'transport_id' => $transportId ?? null,
            'note' => $mappedRow['observacion'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        Log::info("Datos potenciales para fila {$this->rowNumber} antes de validaciones: " . json_encode($potentialRequestData));

        if (!empty($errors)) {
            $this->errors = array_merge($this->errors, $errors);
            Log::warning("Errores en fila {$this->rowNumber}: " . implode(', ', $errors));
            $this->shouldStop = true;
            return null;
        }

        $requestData = $potentialRequestData;
        $this->nextSequence++;

        Log::info("Registro a crear en fila {$this->rowNumber}: " . json_encode($requestData));
        $this->processedRows++;
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
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)->format('Y-m-d');
        }
        return date('Y-m-d', strtotime($date));
    }
}
