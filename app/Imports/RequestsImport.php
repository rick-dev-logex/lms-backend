<?php

namespace App\Imports;

use App\Models\Account;
use App\Models\Request;
use App\Services\UniqueIdService;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;
use Str;

class RequestsImport implements ToModel, WithStartRow, WithChunkReading, SkipsEmptyRows
{
    protected $rowNumber = 0;
    protected $context;
    protected $userId;
    protected $uniqueIdService;
    public $errors = []; // Para acumular errores

    /**
     * Constructor de la clase
     * 
     * @param string $context Contexto de la importación ('discounts', 'expenses', 'income')
     * @param string|null $userId ID del usuario que realiza la importación
     * @param UniqueIdService $uniqueIdService Servicio para generar IDs únicos
     */
    public function __construct(string $context = 'discounts', $userId = null, UniqueIdService $uniqueIdService = null)
    {
        $this->context = $this->normalizeContext($context);
        $this->userId = $userId;
        $this->uniqueIdService = $uniqueIdService ?: app(UniqueIdService::class);
        $this->rowNumber = 0;
    }

    /**
     * Normaliza el contexto para asegurar que sea uno de los valores permitidos
     * 
     * @param string $context Contexto original
     * @return string Contexto normalizado
     */
    private function normalizeContext(string $context): string
    {
        $allowedContexts = ['expense', 'discount', 'income'];
        $normalized = strtolower($context);

        // Manejar caso especial: 'expenses' → 'expense'
        if ($normalized === 'expenses') {
            return 'expense';
        }

        // Manejar caso especial: 'discounts' → 'discount'
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

        try {
            // Verificar si la fila está vacía o solo contiene un elemento
            if (empty($row) || (count($row) === 1) || (count($row) > 1 && count(array_filter($row)) <= 1)) {
                // Simplemente ignoramos esta fila sin registrar errores
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

            // Manejo de fechas mejorado con validación más robusta
            $date = null;

            if (is_numeric($mappedRow['fecha']) && $mappedRow['fecha'] > 0) {
                // Si es un número serial de Excel
                try {
                    $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($mappedRow['fecha']);
                } catch (Exception $e) {
                    $rowErrors[] = "Fecha Excel inválida: " . $e->getMessage();
                }
            } else if (is_string($mappedRow['fecha']) && !empty($mappedRow['fecha'])) {
                // Si es una fecha en formato string
                try {
                    $date = Carbon::parse($mappedRow['fecha']);
                    // Verificar que sea una fecha válida
                    if (!$date->isValid()) {
                        throw new Exception("Fecha inválida");
                    }
                } catch (Exception $e) {
                    $rowErrors[] = "Fecha string inválida: " . $e->getMessage();
                }
            } else {
                $rowErrors[] = "Fecha inválida o faltante";
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

            // Generar ID único usando el servicio
            $uniqueId = $this->uniqueIdService->generateUniqueRequestId($this->context);

            // Preparar datos para el registro
            $requestData = [
                'unique_id' => $uniqueId,
                'type' => $this->context,
                'personnel_type' => $mappedRow['personnel_type'],
                'status' => 'pending',
                'request_date' => $date instanceof \DateTime ? $date->format('Y-m-d') : null,
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

            $cedulaOnix = DB::connection('sistema_onix')->table('onix_personal')->where('name', $mappedRow['cedula_responsable'])->value('nombre_completo');

            if ($cedulaOnix !== $mappedRow['responsable']) {
                Log::warning("La cédula '" . $mappedRow['cedula_responsable'] . "' no corresponde a " . $mappedRow['responsable'] . ".");
                throw new Exception("La cédula '" . $mappedRow['cedula_responsable'] . "' no corresponde a " . $mappedRow['responsable'] . ".", 422);
            }

            $normalizedInput = $this->normalize($requestData['account_id']);

            $cuenta = Account::all()->first(function ($account) use ($normalizedInput) {
                return $this->normalize($account->name) === $normalizedInput;
            });

            if (!$cuenta) {
                Log::warning("La cuenta '" . $requestData['account_id'] . "' no existe en el sistema.");
                throw new Exception("La cuenta '" . $requestData['account_id'] . "' no existe en el sistema. Asegúrate de escribirla correctamente.", 422);
            }

            // Usar el nombre oficial tal como está en la BD
            $requestData['account_id'] = $cuenta->name;

            // Crear registro principal
            $newRequest = new Request($requestData);

            return $newRequest;
        } catch (Exception $e) {
            $error = "Error en fila {$this->rowNumber}: " . $e->getMessage();
            $this->errors[] = $error;
            Log::error($error);
            return null;
        }
    }

    /**
     * Normaliza el texto eliminando tildes, caracteres especiales y no es case-sensitive
     * 
     * @param string $text nombre de la cuenta
     * @return void
     */
    private function normalize(string $text): string
    {
        return mb_strtolower(\Illuminate\Support\Str::ascii(trim($text)));
    }
}
