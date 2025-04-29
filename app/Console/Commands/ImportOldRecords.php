<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Request;
use App\Models\Reposicion;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;

class ImportOldRecords extends Command
{
    protected $signature = 'import:old-records {file : Path to the Excel/CSV file}';
    protected $description = 'Import historical requests in batch and create a paid reposición with no validation.';

    public function handle()
    {
        $file = $this->argument('file');
        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        // Increase execution limits
        set_time_limit(0);
        ini_set('memory_limit', '1G');
        DB::connection()->disableQueryLog();

        $this->info('📥 Reading entire sheet...');
        $allRows  = Excel::toArray([], $file)[0];
        $dataRows = array_slice($allRows, 3);

        $this->info('🔄 Formatting data for batch insert (no validations)...');
        $now = Carbon::now()->toDateTimeString();

        // Determine current maximum suffix for D- IDs
        $maxSuffix = DB::table('requests')
            ->where('unique_id', 'like', 'D-%')
            ->max(DB::raw("CAST(SUBSTRING(unique_id, 3) AS UNSIGNED)")) ?: 0;

        $this->info("Current max D- sequence is: {$maxSuffix}");

        $finalUniqueIds = [];
        $totalAmount = 0;

        DB::beginTransaction();
        try {
            $successCount = 0;
            $this->info("⚡ Processing " . count($dataRows) . " records...");

            // Process each row individually for better control
            foreach ($dataRows as $index => $row) {
                if (!is_array($row) || empty(array_filter($row))) {
                    continue;
                }

                // Ensure at least 10 columns
                $row = array_pad($row, 10, null);
                list(
                    $fechaRaw,
                    $persType,
                    $invoice,
                    $acct,
                    $monto,
                    $proj,
                    $respName,
                    $vehiclePlate,
                    $cedulaRaw,
                    $note
                ) = $row;

                // Parse date from Excel serial or string
                $fecha = null;
                if (is_numeric($fechaRaw)) {
                    $fecha = ExcelDate::excelToDateTimeObject($fechaRaw)->format('Y-m-d');
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', trim((string)$fechaRaw))) {
                    $fecha = Carbon::parse($fechaRaw)->format('Y-m-d');
                }

                // Generate final unique ID directly
                $seq = $maxSuffix + $successCount + 1;
                $newUid = 'D-' . str_pad($seq, 5, '0', STR_PAD_LEFT);

                // Process amount
                $amount = is_numeric($monto) ? floatval($monto) : 0;
                $totalAmount += $amount;

                // Insert record with final unique_id directly
                $request = new Request([
                    'type'               => 'discount',
                    'personnel_type'     => $persType,
                    'status'             => 'paid',
                    'request_date'       => $fecha,
                    'invoice_number'     => $invoice,
                    'account_id'         => $acct,
                    'amount'             => $amount,
                    'project'            => $proj,
                    'responsible_id'     => $respName,
                    'vehicle_plate'      => $vehiclePlate,
                    'cedula_responsable' => $cedulaRaw,
                    'note'               => $note,
                    'unique_id'          => $newUid,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);

                $request->save();
                $finalUniqueIds[] = $newUid;
                $successCount++;

                // Show progress every 100 records
                if ($successCount % 100 === 0) {
                    $this->info("Processed {$successCount} records...");
                }
            }

            // Create reposición
            $this->info('✏️ Creating reposición...');
            $repos = Reposicion::create([
                'fecha_reposicion' => now(),
                'total_reposicion' => $totalAmount,
                'status'           => 'paid',
                'project'          => "ADMN",
                'detail'           => $finalUniqueIds,
                'note'             => 'Migración histórica de solicitudes de caja chica antiguo',
                'attachment_url'   => 'https://storage.googleapis.com/lms-archivos/descuentos_historicos.xlsx',
                'attachment_name'  => 'descuentos_historicos.xlsx',
            ]);

            // Associate reposicion_id directly
            $this->info('🔗 Updating reposicion_id on requests...');
            $updatedCount = Request::whereIn('unique_id', $finalUniqueIds)
                ->update(['reposicion_id' => $repos->id]);

            DB::commit();

            $this->info("✅ Complete. Reposición #{$repos->id} with total {$totalAmount} and {$updatedCount} requests.");
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ImportOldRecords failed', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->error('❌ Import failed: ' . $e->getMessage());
            $this->error('Line: ' . $e->getLine());
            return 1;
        }
    }
}
