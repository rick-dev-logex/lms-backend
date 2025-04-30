<?php

namespace App\Imports;

use App\Models\Account;
use App\Models\Request;
use App\Models\Loan;
use App\Models\Reposicion;
use App\Services\UniqueIdService;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;
use Google\Cloud\Storage\StorageClient;

class LoanImport implements ToCollection, WithStartRow, WithChunkReading, SkipsEmptyRows
{
    protected $rowNumber = 0;
    protected $excelFile;
    protected $storage;
    protected $bucketName;
    protected $userId;
    protected $uniqueIdService;
    public $errors = [];
    protected $filePath;
    public $fileName;

    // Data structures to organize requests by persons/vehicles
    protected $loanGroups = [];
    protected $requests = [];
    public $allRequestIds = [];

    // Cache para evitar consultas repetidas
    protected $accountsCache = [];
    protected $cedulasCache = [];

    // Guarda el último ID generado para evitar duplicados
    protected $lastGeneratedId = null;

    /**
     * Constructor
     */
    public function __construct($excelFile = null, $userId = null, UniqueIdService $uniqueIdService = null)
    {
        // Log::info('LoanImport iniciado: ' . now());

        if (!$excelFile) {
            throw new Exception('No se proporcionó ningún archivo para importar');
        }

        $this->excelFile = $excelFile;
        $this->fileName = $excelFile->getClientOriginalName();
        $this->filePath = $excelFile->getPathname();
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

    /**
     * Comienza a leer desde la fila 4
     */
    public function startRow(): int
    {
        return 4;
    }

    /**
     * Procesa en chunks de 50 filas para optimizar memoria
     */
    public function chunkSize(): int
    {
        return 50;
    }

    /**
     * Procesa la colección de filas del Excel por chunks
     */
    public function collection(Collection $rows)
    {
        // Log::info('Procesando chunk de ' . $rows->count() . ' filas');

        // Precarga de cuentas por nombre para reducir consultas
        $accountNames = $rows->pluck(3)->filter()->unique()->toArray();
        if (!empty($accountNames)) {
            $accounts = Account::whereIn('name', $accountNames)->get();
            foreach ($accounts as $account) {
                $this->accountsCache[$account->name] = $account->toArray();
            }
        }

        // Precarga de cédulas para reducir consultas
        $responsibleNames = $rows->pluck(6)->filter()->unique()->toArray();
        if (!empty($responsibleNames)) {
            $cedulas = DB::connection('sistema_onix')
                ->table('onix_personal')
                ->whereIn('nombre_completo', $responsibleNames)
                ->select('name', 'nombre_completo')
                ->get();

            foreach ($cedulas as $cedula) {
                $this->cedulasCache[$cedula->nombre_completo] = $cedula->name;
            }
        }

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

                // Validaciones rápidas
                $rowErrors = [];

                // Normalizar tipo personal
                $mappedRow['personnel_type'] = $this->normalizePersonnelType(
                    $mappedRow['personnel_type'],
                    $mappedRow['responsable'],
                    $mappedRow['vehicle_plate']
                );

                // Validar factura
                if (empty($mappedRow['no_factura'])) {
                    $rowErrors[] = "Falta el número de factura";
                }

                // Validar valor
                if (!is_numeric($mappedRow['valor'])) {
                    $mappedRow['valor'] = str_replace(',', '.', $mappedRow['valor']);
                    if (!is_numeric($mappedRow['valor'])) {
                        $rowErrors[] = "El valor no es numérico";
                    }
                }

                // Validar proyecto
                if (empty($mappedRow['proyecto'])) {
                    $rowErrors[] = "Falta el proyecto";
                }

                // Parsear fecha
                $date = $this->parseDate($mappedRow['fecha']);
                if (!$date) {
                    $rowErrors[] = "Fecha inválida o faltante";
                }

                // Obtener cuenta
                $account = $this->getAccount($mappedRow['cuenta']);
                if (!$account) {
                    $rowErrors[] = "Cuenta inválida";
                }

                // Obtener cédula si es nómina
                if ($mappedRow['personnel_type'] === 'nomina' && empty($mappedRow['cedula_responsable'])) {
                    $mappedRow['cedula_responsable'] = $this->getCedulaByResponsable($mappedRow['responsable']);
                }

                // Si hay errores, saltar esta fila
                if (!empty($rowErrors) || !$date || !$account) {
                    $this->errors[] = "Fila {$this->rowNumber}: " . implode(", ", $rowErrors);
                    continue;
                }

                // Determinar responsable o vehículo según tipo
                $responsibleId = ($mappedRow['personnel_type'] === 'nomina') ? $mappedRow['responsable'] : null;
                $vehicleId = ($mappedRow['personnel_type'] === 'proveedor') ? $mappedRow['vehicle_plate'] : null;

                // Generar un ID único que no exista en la base de datos
                $uniqueId = $this->generateUniqueRequestId();

                // Datos del request
                $requestData = [
                    'unique_id' => $uniqueId,
                    'type' => 'discount',
                    'personnel_type' => $mappedRow['personnel_type'],
                    'status' => 'in_reposition',
                    'request_date' => $date->format('Y-m-d'),
                    'invoice_number' => $mappedRow['no_factura'],
                    'account_id' => $account->name,
                    'amount' => floatval($mappedRow['valor']),
                    'project' => $mappedRow['proyecto'],
                    'responsible_id' => $responsibleId,
                    'cedula_responsable' => $mappedRow['cedula_responsable'],
                    'vehicle_plate' => $vehicleId,
                    'note' => $mappedRow['note'],
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ];

                // Almacenar para procesamiento posterior
                $this->requests[] = $requestData;
                $this->allRequestIds[] = $uniqueId;

                // Agrupar para préstamos
                $loanKey = $this->getLoanGroupKey($requestData);
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
                            'vehicle_id' => $requestData['vehicle_plate'],
                            'cedula_responsable' => $requestData['cedula_responsable'],
                            'note' => $requestData['note'],
                        ]
                    ];
                }

                $this->loanGroups[$loanKey]['requests'][] = $uniqueId;
                $this->loanGroups[$loanKey]['total'] += $requestData['amount'];

                // Para liberar memoria después de procesar cada fila
                gc_collect_cycles();
            } catch (Exception $e) {
                $this->errors[] = "Error en fila {$this->rowNumber}: " . $e->getMessage();
                Log::error("Error procesando fila {$this->rowNumber}: " . $e->getMessage());
            }
        }
    }

    /**
     * Genera un ID único para el request que no exista ya en la base de datos
     */
    protected function generateUniqueRequestId()
    {
        // Si estamos generando IDs por primera vez en este proceso, obtenemos el máximo actual
        if ($this->lastGeneratedId === null) {
            // Encontrar el número más alto
            $prefix = 'P-';
            $lastRequest = Request::where('unique_id', 'like', $prefix . '%')
                ->orderByRaw('CAST(SUBSTRING(unique_id, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC')
                ->first();

            if ($lastRequest) {
                $lastNumber = (int)str_replace($prefix, '', $lastRequest->unique_id);
                $this->lastGeneratedId = $lastNumber;
            } else {
                $this->lastGeneratedId = 0;
            }

            // Log::info("Último ID encontrado: P-" . str_pad($this->lastGeneratedId, 5, '0', STR_PAD_LEFT));
        }

        // Incrementar el último ID usado
        $this->lastGeneratedId++;
        $newId = 'P-' . str_pad($this->lastGeneratedId, 5, '0', STR_PAD_LEFT);

        // Verificar que no exista ya (por seguridad)
        while (
            Request::where('unique_id', $newId)->exists() ||
            in_array($newId, $this->allRequestIds)
        ) {
            $this->lastGeneratedId++;
            $newId = 'P-' . str_pad($this->lastGeneratedId, 5, '0', STR_PAD_LEFT);
        }

        return $newId;
    }

    /**
     * Normaliza el tipo de personal (nómina o proveedor)
     */
    private function normalizePersonnelType($type, $responsable, $vehiclePlate)
    {
        if (empty($type)) {
            // Inferir tipo si está en blanco
            if (!empty($responsable)) {
                return 'nomina';
            } elseif (!empty($vehiclePlate)) {
                return 'proveedor';
            } else {
                return 'nomina'; // Default
            }
        }

        $lowerType = strtolower(trim($type));

        if (in_array($lowerType, ['nomina', 'nómina', 'personal', 'empleado'])) {
            return 'nomina';
        }

        if (in_array($lowerType, ['proveedor', 'vehiculo', 'vehículo', 'auto'])) {
            return 'proveedor';
        }

        // Default
        return 'nomina';
    }

    /**
     * Procesa y convierte diferentes formatos de fecha
     */
    private function parseDate($dateValue)
    {
        if (empty($dateValue)) {
            return null;
        }

        // Si es número Excel
        if (is_numeric($dateValue) && $dateValue > 0) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateValue);
            } catch (Exception $e) {
                Log::warning("Error al convertir fecha Excel: " . $e->getMessage());
                return null;
            }
        }

        // Si es string de fecha
        if (is_string($dateValue) && !empty($dateValue)) {
            try {
                $date = Carbon::parse($dateValue);
                return $date->isValid() ? $date : null;
            } catch (Exception $e) {
                Log::warning("Error al parsear fecha string: " . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    /**
     * Obtiene o crea una cuenta
     */
    private function getAccount($accountName)
    {
        if (empty($accountName)) {
            return null;
        }

        // Verificar caché primero
        if (isset($this->accountsCache[$accountName])) {
            return (object)$this->accountsCache[$accountName];
        }

        // Buscar en base de datos
        $account = Account::where('name', $accountName)->first();

        // Si no existe, usar cuenta predeterminada
        if (!$account) {
            $account = Account::first();
            if (!$account) {
                throw new Exception("No existe ninguna cuenta en el sistema");
            }
        }

        // Guardar en caché
        $this->accountsCache[$accountName] = $account->toArray();

        return (object)$this->accountsCache[$accountName];
    }

    /**
     * Busca la cédula de un responsable en la base de datos
     */
    private function getCedulaByResponsable($responsableName)
    {
        if (empty($responsableName)) {
            return null;
        }

        // Verificar caché primero
        if (isset($this->cedulasCache[$responsableName])) {
            return $this->cedulasCache[$responsableName];
        }

        // Buscar en base de datos
        $cedula = DB::connection('sistema_onix')
            ->table('onix_personal')
            ->where('nombre_completo', $responsableName)
            ->value('name');

        // Guardar en caché
        if ($cedula) {
            $this->cedulasCache[$responsableName] = $cedula;
        }

        return $cedula;
    }

    /**
     * Genera una clave única para agrupar préstamos
     */
    private function getLoanGroupKey($requestData)
    {
        return md5(
            $requestData['personnel_type'] .
                ($requestData['responsible_id'] ?? '') .
                ($requestData['vehicle_plate'] ?? '') .
                $requestData['project'] .
                $requestData['account_id'] .
                $requestData['invoice_number']
        );
    }

    /**
     * Finaliza la importación creando loans, reposiciones y actualizando requests
     */
    public function finalize()
    {
        // Log::info('Iniciando finalización de importación: ' . now() . ' - Registros: ' . count($this->requests));

        if (empty($this->requests)) {
            Log::warning('No hay datos de préstamos para procesar');
            $this->errors[] = "No se encontraron datos válidos para importar en el archivo Excel";
            return false;
        }

        // Incrementar tiempo límite de ejecución si es posible
        try {
            set_time_limit(300); // 5 minutos
        } catch (\Exception $e) {
            Log::warning("No se pudo extender el tiempo límite de ejecución");
        }

        // Subir archivo a Google Cloud Storage
        try {
            // Log::info('Subiendo archivo a Google Cloud: ' . $this->fileName);
            $bucket = $this->storage->bucket($this->bucketName);
            if (!$bucket->exists()) {
                throw new Exception("El bucket '{$this->bucketName}' no existe o no es accesible");
            }

            $object = $bucket->upload(
                fopen($this->filePath, 'r'),
                ['name' => $this->fileName]
            );
            $fileUrl = $object->signedUrl(new \DateTime('+10 years'));
            // Log::info('Archivo subido correctamente');
        } catch (Exception $e) {
            $this->errors[] = "Error al subir archivo: " . $e->getMessage();
            Log::error("Error al subir archivo: " . $e->getMessage());
            return false;
        }

        DB::beginTransaction();
        try {
            // 1. Crear todos los Request en chunks para evitar problemas de memoria
            // Log::info('Insertando ' . count($this->requests) . ' requests en chunks');

            foreach (array_chunk($this->requests, 100) as $chunk) {
                Request::insert($chunk);
            }
            // Log::info('Requests insertados correctamente');

            // 2. Crear préstamos agrupados
            // Log::info('Creando ' . count($this->loanGroups) . ' préstamos');

            foreach ($this->loanGroups as $groupKey => $groupData) {
                $installments = count($groupData['requests']);

                $loanData = [
                    'loan_date' => now(),
                    'type' => $groupData['metadata']['personnel_type'],
                    'account_id' => $groupData['metadata']['account_id'],
                    'account_name' => $groupData['metadata']['account_name'],
                    'amount' => $groupData['total'],
                    'project' => $groupData['metadata']['project'],
                    'file_path' => $this->fileName,
                    'note' => $groupData['metadata']['note'],
                    'installments' => $installments,
                    'responsible_id' => $groupData['metadata']['responsible_id'],
                    'vehicle_id' => $groupData['metadata']['vehicle_id'],
                    'status' => 'pending',
                ];

                Loan::create($loanData);
            }

            // 3. Crear una Reposición que contenga todos los Request
            // Log::info('Calculando total para reposición');
            $totalAmount = 0;
            foreach ($this->requests as $request) {
                $totalAmount += (float)$request['amount'];
            }

            // Obtener proyectos únicos para la descripción
            $uniqueProjects = array_unique(array_column($this->requests, 'project'));
            $projectName = $this->formatProjectsDescription($uniqueProjects);

            // Log::info('Creando reposición');
            // Crear reposición
            $reposicion = Reposicion::create([
                'fecha_reposicion' => now(),
                'total_reposicion' => $totalAmount,
                'status' => 'pending',
                'project' => $projectName,
                'detail' => $this->allRequestIds,
                'attachment_url' => $fileUrl,
                'attachment_name' => $this->fileName,
                'note' => "Importación masiva de préstamos - " . now()->format('Y-m-d H:i:s'),
            ]);

            // 4. Actualizar reposicion_id en todos los Request
            // Usar UPDATE en lugar de UPSERT para evitar el error de campos requeridos
            // Log::info('Actualizando reposicion_id en requests');

            foreach (array_chunk($this->allRequestIds, 100) as $chunk) {
                Request::whereIn('unique_id', $chunk)
                    ->update(['reposicion_id' => $reposicion->id]);
            }

            DB::commit();
            // Log::info('Importación finalizada exitosamente: ' . now());

            // Liberar memoria
            $this->requests = [];
            $this->loanGroups = [];
            $this->allRequestIds = [];
            gc_collect_cycles();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            $this->errors[] = "Error al finalizar la importación: " . $e->getMessage();
            Log::error("Error al finalizar la importación: " . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            // Liberar memoria
            $this->requests = [];
            $this->loanGroups = [];
            $this->allRequestIds = [];
            gc_collect_cycles();

            return false;
        }
    }

    /**
     * Formatea la descripción de proyectos para la reposición
     */
    private function formatProjectsDescription($projects)
    {
        $count = count($projects);

        if ($count === 0) {
            return "Sin proyecto";
        }

        if ($count === 1) {
            return reset($projects);
        }

        if ($count === 2) {
            return $projects[0] . " y " . $projects[1];
        }

        $projectName = $projects[0] . ", " . $projects[1];
        if ($count > 3) {
            $projectName .= " (+" . ($count - 2) . " más)";
        }

        return $projectName;
    }
}
