<?php

namespace App\Imports;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;

class ImportSriInvoicesFromExcel implements ToCollection, WithStartRow, WithChunkReading, SkipsEmptyRows
{
    protected $rowNumber = 0;
    protected $excelFile;
    protected $userId;
    protected $errors = [];
    
    protected function __construct($excelFile = null, $userId = null)
    {
        if (!$excelFile) {
            throw new Exception('No proporcionaste ningún archivo para importar');
        }

        $this->excelFile = $excelFile;
        $this->userId = $userId;
        $this->rowNumber = 0;
    }
    
    /**
     * Comienza a leer desde la fila 4
     */
    public function startRow(): int
    {
        return 2;
    }

    /**
     * Procesa en chunks de 50 filas para optimizar memoria
     */
    public function chunkSize(): int
    {
        return 150;
    }

    /**
     * Procesa la colección de filas del Excel por chunks
     */
    public function collection(Collection $rows)
    {
        // Log::info('Procesando chunk de ' . $rows->count() . ' filas');
        foreach ($rows as $index => $row) {
            $this->rowNumber++;

            try {
                // Verificar fila vacía
                if (empty($row) || $row->filter()->count() <= 1) {
                    continue;
                }

                // Verificar columnas mínimas
                if ($row->count() < 10) {
                    $this->errors[] = "Fila {$this->rowNumber}: Datos incompletos";
                    continue;
                }

                // Mapear columnas
                $mappedRow = [
                    'purchase' => $row[0] ?? null,
                    'date' => $row[1] ?? null,
                    'approval_date' => $row[2] ?? null,
                    'price' => $row[3] ?? null,
                    'project' => $row[4] ?? null,
                    'centro_costo' => $row[5] ?? null,
                    'notes' => $row[6] ?? null,
                    'observation' => $row[7] ?? null,
                    'contabilized' => $row[8] ?? null,
                    'invoice_series' => $row[9] ?? null,
                    'provider' => $row[10] ?? null,
                ];

                // Validaciones rápidas
                $rowErrors = [];

                // Para liberar memoria después de procesar cada fila
                gc_collect_cycles();
            } catch (Exception $e) {
                $this->errors[] = "Error en fila {$this->rowNumber}: " . $e->getMessage();
                Log::error("Error procesando fila {$this->rowNumber}: " . $e->getMessage());
            }
        }
    }
}
