<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Request;
use App\Models\Reposicion;

class UpdateMonthFromExcel extends Command
{
    protected $signature = 'update:month-for-reposicion {file : Path to the Excel/CSV file} {reposicion_id : ID of the reposición to update}';
    protected $description = 'Update the month field for requests in a specific reposición based on mes_rol column in Excel';

    public function handle()
    {
        $file = $this->argument('file');
        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $reposicionId = $this->argument('reposicion_id');

        // Verify reposición exists
        $reposicion = Reposicion::find($reposicionId);
        if (!$reposicion) {
            $this->error("Reposición #{$reposicionId} not found.");
            return 1;
        }

        // Get the count of requests in this reposición
        $requestCount = Request::where('reposicion_id', $reposicionId)->count();
        $this->info("Found {$requestCount} requests in Reposición #{$reposicionId}");

        // Increase execution limits
        set_time_limit(0);
        ini_set('memory_limit', '1G');
        DB::connection()->disableQueryLog();

        $this->info('📥 Reading Excel file...');
        $allRows = Excel::toArray([], $file)[0];
        $dataRows = array_slice($allRows, 3); // Assuming header rows
        $validRows = array_filter($dataRows, function ($row) {
            return is_array($row) && !empty(array_filter($row));
        });

        $this->info("Found " . count($validRows) . " valid rows in Excel file");

        // Determine column index for mes_rol
        // Adjust this index based on where mes_rol is in your Excel file
        $mesRolIndex = 10; // Change this if mes_rol is in a different column

        $this->info('🔄 Preparing batch update...');

        DB::beginTransaction();
        try {
            // Get all requests for this reposición in order of ID
            // This assumes the order in DB matches the order in Excel
            $requests = Request::where('reposicion_id', $reposicionId)
                ->orderBy('id')
                ->get();

            if (count($requests) !== count($validRows)) {
                $this->warn("Warning: Number of requests in DB ({$requestCount}) doesn't match valid rows in Excel (" . count($validRows) . ")");
                if (!$this->confirm("Continue anyway?", true)) {
                    return 1;
                }
            }

            $updateCount = 0;
            $monthValues = []; // For statistics

            // Map each request to corresponding Excel row by position
            foreach ($requests as $index => $request) {
                // Skip if we run out of Excel rows
                if (!isset($validRows[$index])) {
                    $this->warn("No more Excel rows at index {$index}");
                    break;
                }

                $row = array_pad($validRows[$index], $mesRolIndex + 1, null);
                $mesRol = $row[$mesRolIndex];

                // Skip if mes_rol is empty
                if (empty($mesRol)) {
                    continue;
                }

                // Store the raw value directly, no normalization
                $monthValue = trim((string)$mesRol);

                // Count for statistics
                if (!isset($monthValues[$monthValue])) {
                    $monthValues[$monthValue] = 0;
                }
                $monthValues[$monthValue]++;

                // Update the request
                $request->month = $monthValue;
                $request->save();
                $updateCount++;

                // Show progress every 100 records
                if ($updateCount % 100 === 0) {
                    $this->info("Updated {$updateCount} records...");
                }
            }

            DB::commit();

            // Show month distribution
            $this->info('📊 Month value distribution:');
            ksort($monthValues);
            foreach ($monthValues as $month => $count) {
                $this->info("  - {$month}: {$count} records");
            }

            $this->info("✅ Complete. Updated {$updateCount} records with month value for Reposición #{$reposicionId}.");
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('UpdateMonthForReposicion failed', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            $this->error('❌ Update failed: ' . $e->getMessage());
            $this->error('Line: ' . $e->getLine());
            return 1;
        }
    }
}
