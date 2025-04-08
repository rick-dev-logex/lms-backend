<?php

namespace App\Imports;

use App\Models\Account;
use App\Models\Request;
use App\Models\CajaChica;
use App\Services\UniqueIdService;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

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
     * @param string $context Contexto de la importación ('discounts', 'expenses')
     * @param string|null $userId ID del usuario que realiza la importación
     * @param UniqueIdService $uniqueIdService Servicio para generar IDs únicos
     */
    public function __construct(string $context = 'discounts', $userId = null, UniqueIdService $uniqueIdService = null)
    {
        $this->context = in_array(strtolower($context), ['expense', 'discount']) ? strtolower($context) : ($context === 'expenses' ? 'expense' : 'discount');
        $this->userId = $userId;
        $this->uniqueIdService = $uniqueIdService ?: new UniqueIdService();
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
                } catch (\Exception $e) {
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
                } catch (\Exception $e) {
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

            // Crear registro principal
            $newRequest = new Request($requestData);

            // Crear registro en CajaChica (después de persistir el modelo)
            DB::afterCommit(function () use ($requestData, $uniqueId) {
                try {
                    $this->createCajaChicaRecord($requestData, $uniqueId);
                } catch (Exception $e) {
                    Log::error('Error al crear registro en CajaChica durante importación:', [
                        'row' => $this->rowNumber,
                        'unique_id' => $uniqueId,
                        'error' => $e->getMessage()
                    ]);
                }
            });

            return $newRequest;
        } catch (Exception $e) {
            $error = "Error en fila {$this->rowNumber}: " . $e->getMessage();
            $this->errors[] = $error;
            Log::error($error);
            return null;
        }
    }

    /**
     * Crea un registro en CajaChica a partir de los datos importados
     * 
     * @param array $requestData Datos de la solicitud
     * @param string $uniqueId ID único de la solicitud
     * @return void
     */
    private function createCajaChicaRecord(array $requestData, string $uniqueId): void
    {
        try {
            // Formatear correctamente la fecha
            $fecha = $requestData['request_date'];
            if (is_string($fecha)) {
                // Intentar convertir la fecha a un formato compatible
                $fechaObj = Carbon::parse($fecha);
                $fechaFormateada = $fechaObj->format('Y-m-d'); // Formato YYYY-MM-DD
            } else {
                $fechaFormateada = $fecha;
            }

            $numeroCuenta = Account::where('name', $requestData['account_id'])->pluck('account_number')->first();
            $nombreCuenta = strtoupper(\Illuminate\Support\Str::ascii($requestData['account_id'])); // sin tildes
            $proyecto = strtoupper($requestData['project']);

            // Formatear centro_costo: ENE 2025, ABR 2025, etc.
            $meses = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
            $fechaObj = Carbon::parse($fechaFormateada);
            $centroCosto = $meses[$fechaObj->month - 1] . ' ' . $fechaObj->year;

            // mes_servicio: 1/1/2025, 1/2/2025, etc.
            // mes_servicio: 01/01/2025, etc., usando d/m/Y correctamente
            $fechaServicioObj = Carbon::createFromFormat('d/m/Y', $requestData['request_date']);
            $mesServicio = $fechaServicioObj->startOfMonth()->format('d/m/Y');


            CajaChica::create([
                'FECHA' => $fechaFormateada, // Usar la fecha formateada
                'CODIGO' => "CAJA CHICA" . $uniqueId,
                'DESCRIPCION' => $requestData['note'],
                'SALDO' => $requestData['amount'],
                'CENTRO COSTO' => $centroCosto,
                'CUENTA' => $numeroCuenta,
                'NOMBRE DE CUENTA' => $nombreCuenta,
                'PROVEEDOR' => $this->context === "expense" ? 'CAJA CHICA' : ($this->context === "discount" ? "DESCUENTOS" : "INGRESO"),
                'EMPRESA' => 'SERSUPPORT',
                'PROYECTO' => $proyecto,
                'I_E' => $this->context === "income" ? 'INGRESO' : 'EGRESO',
                'MES SERVICIO' => $mesServicio,
                'TIPO' => $this->context === "expense" ? "GASTO" : ($this->context === "discount" ? "DESCUENTO" : "INGRESO"),
                'ESTADO' => $requestData['status'],
            ]);
        } catch (Exception $e) {
            Log::error('Error al crear registro en CajaChica durante importación:', [
                'row' => $this->rowNumber,
                'unique_id' => $uniqueId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
