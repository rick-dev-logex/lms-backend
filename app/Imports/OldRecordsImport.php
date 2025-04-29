<?php

namespace App\Imports;

use App\Models\Reposicion;
use App\Models\Request;
use App\Services\UniqueIdService;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Excel;

class OldRecordsImport implements ToModel, WithStartRow, WithChunkReading, SkipsEmptyRows
{
    protected $uniqueIdService;
    public $requestUniqueIds = [];
    public $errors = [];

    public function __construct(UniqueIdService $uniqueIdService = null)
    {
        $this->uniqueIdService = $uniqueIdService ?: app(UniqueIdService::class);
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
        if (empty(array_filter($row))) {
            return null;
        }

        try {
            $fechaRaw        = $row[0] ?? null;
            $personnelType   = $row[1] ?? null;
            $invoiceNumber   = $row[2] ?? null;
            $accountId       = $row[3] ?? null;
            $amountRaw       = $row[4] ?? null;
            $project         = $row[5] ?? null;
            $responsibleName = $row[6] ?? null;
            $vehiclePlate    = $row[7] ?? null;
            $note            = $row[9] ?? '—';

            // Preserve #N/A and blank responsible as-is; otherwise lookup cedula
            if ($responsibleName && strtoupper(trim($responsibleName)) !== '#N/A') {
                $cedulaResponsable = DB::connection('sistema_onix')
                    ->table('onix_personal')
                    ->where('nombre_completo', $responsibleName)
                    ->value('name');
            } else {
                $cedulaResponsable = $responsibleName;
            }

            // Parse date only if serial or valid date string
            $fecha = null;
            if (is_numeric($fechaRaw)) {
                $fecha = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($fechaRaw)->format('Y-m-d');
            } elseif (is_string($fechaRaw) && preg_match('/^\d{4}-\d{2}-\d{2}/', trim($fechaRaw))) {
                $fecha = Carbon::parse($fechaRaw)->format('Y-m-d');
            }

            $uniqueId = $this->uniqueIdService->generateUniqueRequestId('discount');
            $this->requestUniqueIds[] = $uniqueId;

            $amount = is_numeric($amountRaw) ? floatval($amountRaw) : 0;

            return new Request([
                'unique_id'          => $uniqueId,
                'type'               => 'discount',
                'personnel_type'     => $personnelType,
                'status'             => 'paid',
                'request_date'       => $fecha,
                'invoice_number'     => $invoiceNumber,
                'account_id'         => $accountId,
                'amount'             => $amount,
                'project'            => $project,
                'responsible_id'     => $responsibleName,
                'vehicle_plate'      => $vehiclePlate,
                'cedula_responsable' => $cedulaResponsable,
                'note'               => $note,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        } catch (Exception $e) {
            Log::error('Error en importación histórico:', ['message' => $e->getMessage()]);
            $this->errors[] = $e->getMessage();
            return null;
        }
    }

    public function import(HttpRequest $request, Excel $excel)
    {
        $request->validate([
            'file'    => 'required|file|mimes:xlsx,xls,csv',
        ]);

        try {
            DB::beginTransaction();

            $file   = $request->file('file');
            $import = new OldRecordsImport(app(UniqueIdService::class));

            // Importa solo las solicitudes
            $excel->import($import, $file);

            if (!empty($import->errors)) {
                throw new Exception(json_encode($import->errors));
            }

            DB::commit();

            // Ahora creas la reposición en un paso separado, rápido
            $ids   = $import->requestUniqueIds;
            $total = Request::whereIn('unique_id', $ids)->sum('amount');

            $reposicion = Reposicion::create([
                'fecha_reposicion' => now(),
                'total_reposicion' => $total,
                'status'           => 'paid',
                'detail'           => $ids,
                'note'             => 'Migración histórica',
            ]);

            Request::whereIn('unique_id', $ids)
                ->update(['reposicion_id' => $reposicion->id]);

            return response()->json([
                'message'     => 'Importación y reposición exitosa',
                'reposicion'  => $reposicion,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en importación histórica:', ['error' => $e->getMessage()]);
            return response()->json([
                'errors' => json_decode($e->getMessage(), true) ?: [$e->getMessage()],
            ], 500);
        }
    }
}
