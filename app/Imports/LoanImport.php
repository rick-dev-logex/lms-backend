<?php

namespace App\Imports;

use App\Models\Account;
use App\Models\Request;
use App\Models\Loan;
use App\Models\Reposicion;
use App\Services\UniqueIdService;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;
use Google\Cloud\Storage\StorageClient;

class LoanImport implements ToModel, WithStartRow, WithChunkReading, SkipsEmptyRows
{
    protected $rowNumber = 0;
    protected $excelFile;
    protected $storage;
    protected $bucketName;
    protected $userId;
    protected $uniqueIdService;
    protected $loanGroups = []; // Para agrupar préstamos relacionados
    protected $reposicionData = []; // Para organizar las reposiciones
    public $errors = []; // Para acumular errores

    // Propiedades adicionales para el archivo
    protected $filePath;
    protected $fileName;

    /**
     * Constructor de la clase
     * 
     * @param \Illuminate\Http\UploadedFile|null $excelFile Archivo Excel subido
     * @param string|null $userId ID del usuario que realiza la importación
     * @param UniqueIdService|null $uniqueIdService Servicio para generar IDs únicos
     * @throws \Exception Si no se proporciona un archivo válido
     */
    public function __construct($excelFile = null, $userId = null, UniqueIdService $uniqueIdService = null)
    {
        // Validación más estricta del archivo
        if (!$excelFile) {
            Log::error('Error en constructor LoanImport: No se proporcionó archivo');
            throw new \Exception('No se proporcionó ningún archivo para importar');
        }

        if (!is_object($excelFile)) {
            Log::error('Error en constructor LoanImport: El archivo no es un objeto', [
                'type' => gettype($excelFile)
            ]);
            throw new \Exception('El archivo proporcionado no es válido (no es un objeto)');
        }

        if (!method_exists($excelFile, 'getClientOriginalName')) {
            Log::error('Error en constructor LoanImport: El archivo no tiene el método getClientOriginalName', [
                'class' => get_class($excelFile),
                'methods' => get_class_methods($excelFile)
            ]);
            throw new \Exception('El archivo proporcionado no es un archivo válido (no implementa getClientOriginalName)');
        }

        if (!$excelFile->isValid()) {
            Log::error('Error en constructor LoanImport: El archivo no es válido', [
                'error_code' => $excelFile->getError()
            ]);
            throw new \Exception('El archivo subido no es válido: código de error ' . $excelFile->getError());
        }

        // Registrar información del archivo
        Log::info('Iniciando importación de préstamos', [
            'file_name' => $excelFile->getClientOriginalName(),
            'file_size' => $excelFile->getSize(),
            'file_extension' => $excelFile->getClientOriginalExtension(),
            'mime_type' => $excelFile->getMimeType(),
            'user_id' => $userId
        ]);

        $this->excelFile = $excelFile;
        $this->userId = $userId;
        $this->uniqueIdService = $uniqueIdService ?: app(UniqueIdService::class);
        $this->rowNumber = 0;

        // Configurar Google Cloud Storage
        $base64Key = env('GOOGLE_CLOUD_KEY_BASE64');
        if (!$base64Key) {
            throw new Exception('La clave de Google Cloud no está definida en el archivo .env.');
        }
        $credentials = json_decode(base64_decode($base64Key), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error al decodificar las credenciales de Google Cloud: ' . json_last_error_msg());
        }

        $this->storage = new StorageClient([
            'keyFile' => $credentials
        ]);
        $this->bucketName = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'lms-archivos');
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

            // Validación de tipo de personal (debe ser 'nomina' o 'proveedor')
            if (empty($mappedRow['personnel_type']) || !in_array(strtolower($mappedRow['personnel_type']), ['nomina', 'proveedor'])) {
                $rowErrors[] = "El tipo de personal debe ser 'nomina' o 'proveedor'";
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

            // Validar que la cuenta exista en la BD
            $account = Account::where('name', 'like', '%' . $mappedRow['cuenta'] . '%')->first();
            if (!$account) {
                $rowErrors[] = "La cuenta '{$mappedRow['cuenta']}' no existe en el sistema";
            }

            if (!empty($rowErrors) || $date === null) {
                $error = "Fila {$this->rowNumber}: " . implode(", ", $rowErrors);
                $this->errors[] = $error;
                Log::warning($error);
                return null;
            }

            // Generar ID único para la solicitud
            $prefix = 'P-';
            $lastRequest = Request::where('unique_id', 'like', 'P-%')
                ->orderBy('id', 'desc')
                ->first();
            $nextId = $lastRequest ? ((int)str_replace($prefix, '', $lastRequest->unique_id) + 1) : 1;
            $uniqueId = sprintf('%s%05d', $prefix, $nextId);

            // Determinar ID del responsable o vehículo según el tipo
            $responsibleId = null;
            $vehicleId = null;

            if (strtolower($mappedRow['personnel_type']) === 'nomina') {
                $responsibleId = $mappedRow['responsable']; // Nombre del responsable
            } else {
                $vehicleId = $mappedRow['vehicle_plate']; // ID o placa del vehículo
            }

            // Preparar datos para la solicitud (Request)
            $requestData = [
                'unique_id' => $uniqueId,
                'type' => 'discount',  // Según el contexto normalizado en el constructor original
                'personnel_type' => strtolower($mappedRow['personnel_type']),
                'status' => 'in_reposition',
                'request_date' => $date instanceof \DateTime ? $date->format('Y-m-d') : now()->format('Y-m-d'),
                'invoice_number' => $mappedRow['no_factura'],
                'account_id' => $account->name,
                'amount' => floatval($mappedRow['valor']),
                'project' => $mappedRow['proyecto'],
                'responsible_id' => $responsibleId,
                'transport_id' => $vehicleId,
                'note' => $mappedRow['note'] ?? "—",
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];

            // Agrupar solicitudes por criterios de préstamo (para crear loans)
            $loanKey = md5($requestData['personnel_type'] .
                $requestData['invoice_number'] .
                $requestData['account_id'] .
                $requestData['project'] .
                $requestData['responsible_id'] .
                $requestData['transport_id']);

            if (!isset($this->loanGroups[$loanKey])) {
                $this->loanGroups[$loanKey] = [
                    'requests' => [],
                    'total' => 0,
                    'metadata' => [
                        'personnel_type' => $requestData['personnel_type'],
                        'invoice_number' => $requestData['invoice_number'],
                        'account_id' => $account->id,
                        'account_name' => $account->name,
                        'project' => $requestData['project'],
                        'responsible_id' => $requestData['responsible_id'],
                        'vehicle_id' => $requestData['transport_id'],
                        'note' => $requestData['note'],
                    ]
                ];
            }

            $this->loanGroups[$loanKey]['requests'][] = $uniqueId;
            $this->loanGroups[$loanKey]['total'] += $requestData['amount'];

            // Agrupar por proyecto para las reposiciones
            $repoKey = md5($requestData['project']);
            if (!isset($this->reposicionData[$repoKey])) {
                $this->reposicionData[$repoKey] = [
                    'project' => $requestData['project'],
                    'requests' => [],
                    'total' => 0
                ];
            }

            $this->reposicionData[$repoKey]['requests'][] = $uniqueId;
            $this->reposicionData[$repoKey]['total'] += $requestData['amount'];

            // Crear el modelo Request para devolver
            return new Request($requestData);
        } catch (Exception $e) {
            $error = "Error en fila {$this->rowNumber}: " . $e->getMessage();
            $this->errors[] = $error;
            Log::error($error);
            return null;
        }
    }

    /**
     * Método para finalizar la importación y crear los registros de préstamos y reposiciones
     */
    public function finalize()
    {
        DB::beginTransaction();
        try {
            // Verificar que tenemos datos para procesar
            if (empty($this->loanGroups)) {
                Log::warning('No hay datos de préstamos para procesar. Verifica el formato del archivo Excel.');
                throw new Exception("No se encontraron datos válidos para importar en el archivo Excel. Verifica el formato.");
            }

            Log::info('Finalizando importación de préstamos', [
                'grupos_prestamos' => count($this->loanGroups),
                'grupos_reposicion' => count($this->reposicionData),
                'file_name' => $this->fileName,
                'file_path' => $this->filePath
            ]);

            // Subir el archivo de Excel a Google Cloud Storage
            $bucket = $this->storage->bucket($this->bucketName);
            if (!$bucket->exists()) {
                throw new Exception("El bucket '{$this->bucketName}' no existe o no es accesible");
            }

            Log::info('Subiendo archivo a Google Cloud Storage', [
                'bucket' => $this->bucketName,
                'file' => $this->fileName
            ]);

            // Asegurarnos de que podemos acceder al archivo real
            if (!file_exists($this->filePath)) {
                throw new Exception("No se puede acceder al archivo en la ruta: " . $this->filePath);
            }

            $object = $bucket->upload(
                fopen($this->filePath, 'r'),
                ['name' => $this->fileName]
            );

            $fileUrl = $object->signedUrl(new \DateTime('+10 years'));

            // Crear préstamos agrupados
            foreach ($this->loanGroups as $groupKey => $groupData) {
                $installments = count($groupData['requests']);

                // Crear el préstamo
                $loan = Loan::create([
                    'loan_date' => now(),
                    'type' => $groupData['metadata']['personnel_type'],
                    'account_id' => $groupData['metadata']['account_id'],
                    'account_name' => $groupData['metadata']['account_name'],
                    'amount' => $groupData['total'],
                    'project' => $groupData['metadata']['project'],
                    'file_path' => $this->fileName,  // Nombre del archivo Excel
                    'note' => $groupData['metadata']['note'],
                    'installments' => $installments,
                    'responsible_id' => $groupData['metadata']['responsible_id'],
                    'vehicle_id' => $groupData['metadata']['vehicle_id'],
                    'status' => 'pending',
                ]);

                // Actualizar las solicitudes con el ID del préstamo
                Request::whereIn('unique_id', $groupData['requests'])
                    ->update(['loan_id' => $loan->id]);
            }

            // Crear reposiciones por proyecto
            foreach ($this->reposicionData as $repoKey => $repoData) {
                $reposicion = Reposicion::create([
                    'fecha_reposicion' => now(),
                    'total_reposicion' => $repoData['total'],
                    'status' => 'pending',
                    'project' => $repoData['project'],
                    'detail' => $repoData['requests'],
                    'attachment_url' => $fileUrl,
                    'attachment_name' => $this->fileName,
                    'note' => "Importación masiva de préstamos - " . now()->format('Y-m-d H:i:s'),
                ]);

                // Actualizar las solicitudes con el ID de la reposición
                Request::whereIn('unique_id', $repoData['requests'])
                    ->update(['reposicion_id' => $reposicion->id]);
            }

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            $this->errors[] = "Error al finalizar la importación: " . $e->getMessage();
            Log::error("Error al finalizar la importación masiva de préstamos: " . $e->getMessage());
            return false;
        }
    }
}
