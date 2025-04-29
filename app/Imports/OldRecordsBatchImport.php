<?php

namespace App\Imports;

use App\Models\Request;
use App\Services\UniqueIdService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OldRecordsBatchImport implements ToCollection, WithStartRow, WithChunkReading, WithBatchInserts
{
    protected UniqueIdService $ids;
    public array $uniqueIds = [];

    public function __construct(UniqueIdService $ids = null)
    {
        $this->ids = $ids ?: app(UniqueIdService::class);
    }

    public function startRow(): int
    {
        return 4;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function batchSize(): int
    {
        return 1000;
    }

    public function collection(Collection $rows)
    {
        $now = now()->toDateTimeString();
        $inserts = [];

        foreach ($rows as $row) {
            if (empty(array_filter($row->toArray()))) {
                continue;
            }

            // extraer safe
            $fechaRaw        = $row[0] ?? null;
            $personnelType   = $row[1] ?? null;
            $invoiceNumber   = $row[2] ?? null;
            $accountId       = $row[3] ?? null;
            $amountRaw       = $row[4] ?? null;
            $project         = $row[5] ?? null;
            $responsibleName = $row[6] ?? null;
            $vehiclePlate    = $row[7] ?? null;
            $note            = $row[9] ?? '—';

            // parse fecha
            $fecha = null;
            if (is_numeric($fechaRaw)) {
                $fecha = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($fechaRaw)
                    ->format('Y-m-d');
            } elseif (is_string($fechaRaw) && preg_match('/^\d{4}-\d{2}-\d{2}/', $fechaRaw)) {
                $fecha = Carbon::parse($fechaRaw)->format('Y-m-d');
            }

            // cédula
            if ($responsibleName && strtoupper(trim($responsibleName)) !== '#N/A') {
                $cedula = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->where('nombre_completo', $responsibleName)
                    ->value('name');
            } else {
                $cedula = $responsibleName;
            }

            // unique id
            $uid = $this->ids->generateUniqueRequestId('discount');
            $this->uniqueIds[] = $uid;

            $inserts[] = [
                'unique_id'          => $uid,
                'type'               => 'discount',
                'personnel_type'     => $personnelType,
                'status'             => 'paid',
                'request_date'       => $fecha,
                'invoice_number'     => $invoiceNumber,
                'account_id'         => $accountId,
                'amount'             => is_numeric($amountRaw) ? floatval($amountRaw) : 0,
                'project'            => $project,
                'responsible_id'     => $responsibleName,
                'vehicle_plate'      => $vehiclePlate,
                'cedula_responsable' => $cedula,
                'note'               => $note,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        if (!empty($inserts)) {
            Request::insert($inserts);
        }
    }
}
