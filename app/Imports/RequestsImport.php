<?php

namespace App\Imports;

use App\Models\Request;
use App\Models\Account;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class RequestsImport implements ToModel, WithHeadingRow, WithChunkReading
{
    private $context;
    private $accounts;
    private $projects;
    private $personals;
    private $vehicles;
    private $nextSequence;

    public function __construct(string $context = 'discounts')
    {
        $this->context = $context;

        // Cargar datos en caché
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

        // Calcular el siguiente número de secuencia
        $prefix = $this->context === 'discounts' ? 'D-' : 'G-';
        $lastRequest = Request::where('unique_id', 'like', "{$prefix}%")
            ->orderBy('unique_id', 'desc')
            ->first();

        $this->nextSequence = $lastRequest
            ? (int) substr($lastRequest->unique_id, 2) + 1
            : 1; // Comienza en 1 si no hay registros
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function model(array $row)
    {
        $row = array_change_key_case($row, CASE_LOWER);

        Log::info('Fila procesada: ' . json_encode($row));

        if (empty($row['fecha']) || empty($row['tipo']) || empty($row['valor'])) {
            throw new \Exception('Fila inválida: faltan datos requeridos - ' . json_encode($row));
        }

        $type = strtolower(trim($row['tipo']));
        $accountName = trim($row['cuenta']);
        $projectName = trim($row['proyecto']);
        $cedula = trim($row['cedula'] ?? '');
        $plate = $this->normalizePlate($row['placa'] ?? '');

        $accountId = $this->accounts[$accountName] ?? null;
        $projectId = $this->projects[$projectName] ?? null;

        if (!$accountId || !$projectId) {
            throw new \Exception("Cuenta o proyecto no encontrado: Cuenta: {$accountName}, Proyecto: {$projectName}");
        }

        $responsibleId = null;
        if ($type === 'nomina') {
            if (empty($cedula)) {
                throw new \Exception("Cédula requerida para tipo Nómina en fila: " . json_encode($row));
            }
            $responsibleId = $this->personals[$cedula] ?? null;
            if (!$responsibleId) {
                throw new \Exception("Responsable no encontrado con cédula: {$cedula}");
            }
        }

        $transportId = null;
        if ($type === 'transportista') {
            if (empty($plate)) {
                throw new \Exception("Placa requerida para tipo Transportista en fila: " . json_encode($row));
            }
            $transportId = $this->vehicles[$plate] ?? null;
            if (!$transportId) {
                throw new \Exception("Vehículo no encontrado con placa: {$plate}");
            }
        }

        // Generar unique_id con el siguiente número
        $prefix = $this->context === 'discounts' ? 'D-' : 'G-';
        $uniqueId = sprintf('%s%05d', $prefix, $this->nextSequence++);

        $requestData = [
            'unique_id' => $uniqueId,
            'type' => $type === 'nomina' ? 'nomina' : 'transportista',
            'status' => 'pending',
            'request_date' => $this->parseDate($row['fecha']),
            'invoice_number' => $row['no_factura'] ?? null,
            'account_id' => $accountId,
            'amount' => floatval($row['valor']),
            'project_id' => $projectId,
            'responsible_id' => $responsibleId,
            'transport_id' => $transportId,
            'note' => $row['observacion'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        Log::info('Registro a crear: ' . json_encode($requestData));

        return new Request($requestData);
    }

    private function normalizePlate(string $plate): string
    {
        return str_replace([' ', '-'], '', trim($plate));
    }

    private function parseDate($date): string
    {
        if (is_numeric($date)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)->format('Y-m-d');
        }
        return date('Y-m-d', strtotime($date));
    }
}
