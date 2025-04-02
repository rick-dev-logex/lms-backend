<?php

namespace App\Imports;

use App\Models\Request;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RequestsImport implements ToModel, WithStartRow, WithChunkReading, SkipsEmptyRows
{
    protected $rowNumber = 0;
    protected $nextId;
    protected $context;

    public function __construct(string $context = 'discounts', $userId = null)
    {
        $this->context = in_array(strtolower($context), ['expense', 'discount']) ? strtolower($context) : ($context === 'expenses' ? 'expense' : 'discount');

        // Fetch the max unique_id from the database, considering both G- and D- prefixes
        $lastRequest = Request::orderBy('unique_id', 'desc')->first();
        if ($lastRequest && preg_match('/[GD]-(\d{5})/', $lastRequest->unique_id, $matches)) {
            $this->nextId = (int)$matches[1] + 1;
        } else {
            $this->nextId = 1; // Start at 1 if no records exist
        }
        $this->rowNumber = 0;
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

        if (count($row) < 10) {
            Log::warning("Skipping row {$this->rowNumber}: incomplete row", $row);
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

        $errors = [];

        if (empty($mappedRow['personnel_type'])) {
            $errors[] = "Missing personnel_type";
        }

        if (empty($mappedRow['no_factura'])) {
            $errors[] = "Missing invoice_number";
        }

        if (!is_numeric($mappedRow['valor'])) {
            $errors[] = "Invalid amount";
        }

        if (empty($mappedRow['proyecto'])) {
            $errors[] = "Missing project";
        }

        if (!is_numeric($mappedRow['fecha']) || $mappedRow['fecha'] <= 0) {
            $errors[] = "Invalid date";
        } else {
            try {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($mappedRow['fecha']);
            } catch (\Exception $e) {
                $errors[] = "Invalid date: " . $e->getMessage();
            }
        }

        // Additional validations if needed
        // For example, if cedula_responsable is required to be numeric and 10 digits
        if (!empty($mappedRow['cedula_responsable']) && (!is_numeric($mappedRow['cedula_responsable']) || strlen((string)$mappedRow['cedula_responsable']) < 10)) {
            $errors[] = "Invalid cedula_responsable";
        }

        if (!empty($errors)) {
            Log::warning("Skipping row {$this->rowNumber} due to errors:", $errors);
            return null;
        }

        // Prepare request data
        $requestData = [
            'unique_id' => sprintf("%s-%05d", $this->context === 'discount' ? 'D' : 'G', $this->nextId++),
            'type' => $this->context,
            'personnel_type' => $mappedRow['personnel_type'],
            'status' => 'pending',
            'request_date' => Carbon::instance($date)->toDateString(),
            'invoice_number' => $mappedRow['no_factura'],
            'account_id' => $mappedRow['cuenta'],
            'amount' => floatval($mappedRow['valor']),
            'project' => $mappedRow['proyecto'],
            'responsible_id' => $mappedRow['responsable'],
            'vehicle_plate' => $mappedRow['vehicle_plate'],
            'cedula_responsable' => $mappedRow['cedula_responsable'],
            'note' => $mappedRow['note'] ?? "—",
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        return new Request($requestData);
    }
}
