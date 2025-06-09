<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Reposicion;
use App\Models\ReposicionProyecto;
use App\Models\Request;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;


class ReposicionController extends Controller
{
    private $storage;
    private $bucketName;
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->storage = new StorageClient([
            'keyFilePath' => env('GOOGLE_CLOUD_KEY_FILE')
        ]);
        $this->bucketName = env('GOOGLE_CLOUD_STORAGE_BUCKET');
        $this->authService = $authService;
    }

    public function index(HttpRequest $request)
    {
        try {
            // Obtener usuario y proyectos asignados
            $jwtToken = $request->cookie('jwt-token');
            if (!$jwtToken) {
                throw new \Exception("No se encontró el token de autenticación en la cookie.");
            }

            $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
            $userId = $decoded->user_id ?? null;
            if (!$userId) {
                throw new \Exception("No se encontró el ID de usuario en el token JWT.");
            }

            $user = User::find($userId);
            if (!$user) {
                throw new \Exception("Usuario no encontrado.");
            }

            // Proyectos asignados
            $assignedProjectIds = [];

            if ($user && isset($user->assignedProjects)) {
                if (is_object($user->assignedProjects) && isset($user->assignedProjects->projects)) {
                    $projectsValue = $user->assignedProjects->projects;
                    $assignedProjectIds = is_string($projectsValue)
                        ? json_decode($projectsValue, true) ?: []
                        : (is_array($projectsValue) ? $projectsValue : []);
                } elseif (is_array($user->assignedProjects)) {
                    $assignedProjectIds = $user->assignedProjects;
                }
            }

            $assignedProjectIds = array_map('strval', $assignedProjectIds);

            $query = Reposicion::query();

            // Period, Status, Mode
            $period = $request->input('period', 'last_month');
            $status = $request->input('status', 'pending');
            $mode = $request->input('mode', 'all');

            // Convert UUIDs a nombres de proyectos
            $projectNames = [];
            if (!empty($assignedProjectIds)) {
                $projectNames = DB::connection('sistema_onix')
                    ->table('onix_proyectos')
                    ->whereIn('id', $assignedProjectIds)
                    ->pluck('name')
                    ->map(fn($name) => strtoupper(trim($name))) // aseguramos consistencia
                    ->toArray();

                // Filtrar por nombre de proyecto usando REGEXP exacto
                $query->where(function ($q) use ($projectNames) {
                    foreach ($projectNames as $projectName) {
                        $escaped = preg_quote($projectName, '/');
                        $q->orWhereRaw("CONCAT(',', project, ',') REGEXP ?", ["(,{$escaped},)"]);
                    }
                });
            }

            // Periodo
            match ($period) {
                'last_3_months' => $query->where('created_at', '>=', Carbon::now()->subMonths(3)->startOfMonth()),
                'last_month'    => $query->where('created_at', '>=', Carbon::now()->subMonth()->startOfMonth()),
                'last_week'     => $query->where('created_at', '>=', Carbon::now()->subWeek()->startOfWeek()),
                'all'           => $query->where('created_at', '<=', Carbon::now()),
                default         => null
            };

            // Estado
            match ($status) {
                'pending'  => $query->where('status', 'pending'),
                'paid'     => $query->where('status', 'paid'),
                'rejected' => $query->where('status', 'rejected'),
                'all'      => $query->where('status', '!=', 'in_reposition'),
                default    => null
            };

            // Filtros adicionales
            if ($request->filled('project')) {
                $query->where('project', $request->input('project'));
            }

            if ($request->filled('month')) {
                $query->where('month', $request->input('month'));
            }

            // Consultar reposiciones con relaciones
            $reposiciones = $query->with('requestsWithRelations')->orderByDesc('id')->get();

            // Filtrar según el tipo
            $reposiciones = $reposiciones->filter(function ($reposicion) use ($mode) {
                $requestIds = $reposicion->requests->pluck('unique_id')->all();
                $allIncome = count($requestIds) > 0 && count($requestIds) === count(array_filter($requestIds, fn($id) => str_starts_with($id, 'I-')));

                return $mode === 'income' ? $allIncome : !$allIncome;
            });

            // Obtener nombres de proyectos para vista
            $projects = !empty($assignedProjectIds)
                ? DB::connection('sistema_onix')
                ->table('onix_proyectos')
                ->whereIn('id', $assignedProjectIds)
                ->select('id', 'name')
                ->get()
                ->mapWithKeys(fn($project) => [$project->id => $project->name])
                ->all()
                : [];

            $data = $reposiciones->map(function ($reposicion) use ($projects) {
                $repoData = $reposicion->toArray();
                $repoData['project_name'] = $reposicion->project; // ya es string
                return $repoData;
            })->values()->all();

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error en ReposicionController@index', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error al procesar la solicitud',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(HttpRequest $request)
    {
        try {
            DB::beginTransaction();

            $requestIds = $request->input('request_ids', []);

            if (empty($requestIds) || !is_array($requestIds)) {
                throw ValidationException::withMessages([
                    'request_ids' => ['Se requiere al menos un ID de solicitud válido.']
                ]);
            }

            // **OPTIMIZACIÓN: Una sola consulta con locks para evitar condiciones de carrera**
            $existingRequests = Request::whereIn('unique_id', $requestIds)
                ->lockForUpdate() // Evitar modificaciones concurrentes
                ->get();

            if ($existingRequests->count() !== count($requestIds)) {
                $foundIds = $existingRequests->pluck('unique_id')->toArray();
                $missingIds = array_diff($requestIds, $foundIds);
                throw ValidationException::withMessages([
                    'request_ids' => ['Solicitudes no encontradas: ' . implode(', ', $missingIds)]
                ]);
            }

            $alreadyAssigned = $existingRequests->filter(function ($req) {
                return $req->reposicion_id !== null;
            });

            if ($alreadyAssigned->count() > 0) {
                $invalidIds = $alreadyAssigned->pluck('unique_id')->toArray();
                throw ValidationException::withMessages([
                    'request_ids' => ['Las siguientes solicitudes ya tienen una reposición asignada: ' . implode(', ', $invalidIds)]
                ]);
            }

            $invalidStatus = $existingRequests->filter(function ($req) {
                return $req->status !== 'pending';
            });

            if ($invalidStatus->count() > 0) {
                $invalidIds = $invalidStatus->pluck('unique_id')->toArray();
                throw ValidationException::withMessages([
                    'request_ids' => ['Las siguientes solicitudes no están en estado pendiente: ' . implode(', ', $invalidIds)]
                ]);
            }

            // Validar archivo
            $file = $request->file('attachment');
            if (!$file || !$file->isValid()) {
                throw ValidationException::withMessages([
                    'attachment' => ['Se requiere un archivo válido.']
                ]);
            }

            // Validar tamaño (20MB)
            if ($file->getSize() > 20 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'attachment' => ['El archivo excede el límite de 20MB.']
                ]);
            }

            $user = $this->authService->getUser($request);
            $projects = $existingRequests->pluck('project')->unique();

            // **VALIDACIÓN DE PERMISOS PARA PROYECTOS MÚLTIPLES**
            if ($projects->count() > 1) {
                $allowedUsers = [
                    'michelle.quintana@logex.ec',
                    'nicolas.iza@logex.ec',
                    'ricardo.estrella@logex.ec',
                    'diego.merisalde@logex.ec'
                ];

                if (!in_array($user->email, $allowedUsers)) {
                    throw ValidationException::withMessages([
                        'request_ids' => ['No tienes permisos para crear reposiciones con múltiples proyectos.']
                    ]);
                }
            }

            // **OPTIMIZACIÓN: Subida de archivo en paralelo (si es posible)**
            $fileName = null;
            $shortUrl = null;

            try {
                // Procesar y subir el archivo a Google Cloud Storage
                $fileName = null;
                $shortUrl = null;
                if (!$request->hasFile('attachment') && !$request->hasFile('file')) {
                    throw ValidationException::withMessages([
                        'attachment' => ['The attachment field is required.'],
                    ]);
                }
                $file = $request->hasFile('attachment') ? $request->file('attachment') : $request->file('file');

                if ($request->file('attachment') || $request->file('file')) {
                    $fileName = $file->getClientOriginalName();

                    try {
                        $base64Key = env('GOOGLE_CLOUD_KEY_BASE64');

                        if (!$base64Key) {
                            throw new \Exception('La clave de Google Cloud no está definida en el archivo .env.');
                        }

                        $credentials = json_decode(base64_decode($base64Key), true);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new \Exception('Error al decodificar las credenciales de Google Cloud: ' . json_last_error_msg());
                        }

                        $storage = new StorageClient([
                            'keyFile' => $credentials
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Error al conectar con Google Cloud Storage', ['error' => $e->getMessage()]);
                        throw new \Exception('Error al conectar con Google Cloud Storage. ¿Está correctamente definida la configuración en .env?');
                    }

                    try {
                        $bucketName = env('GOOGLE_CLOUD_BUCKET');
                        $bucket = $storage->bucket($bucketName);
                        if (!$bucket->exists()) {
                            throw new \Exception("El bucket '$bucketName' no existe o no es accesible");
                        }
                    } catch (\Exception $e) {
                        Log::error('Error al conectar con Google Cloud Storage', ['error' => $e->getMessage()]);
                        throw new \Exception('Error al conectar con el bucket de Google Cloud Storage. ¿Está definido el nombre del bucket en .env?');
                    }

                    // Subir el archivo
                    try {
                        $bucket->upload(
                            fopen($file->getRealPath(), 'r'),
                            [
                                'name' => $fileName,
                                'predefinedAcl' => null,
                            ]
                        );
                    } catch (\Exception $e) {
                        throw new \Exception('Error al subir el archivo a Google Cloud Storage');
                    }

                    // Generar una URL acortada (solo la base sin parámetros de firma)
                    $shortUrl = "https://storage.googleapis.com/{$bucketName}/" . urlencode($fileName);
                    if (strlen($shortUrl) > 255) {
                        // Si aún es demasiado larga, usar un hash corto como identificador
                        $shortUrl = "https://storage.googleapis.com/{$bucketName}/" . substr(hash('sha256', $fileName), 0, 20);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error uploading file to GCS', ['error' => $e->getMessage()]);
                throw new \Exception('Error al subir el archivo. Inténtalo nuevamente.');
            }

            // **CREAR REPOSICIÓN CON DATOS NORMALIZADOS**
            $reposicion = Reposicion::create([
                'fecha_reposicion' => Carbon::now(),
                'total_reposicion' => $existingRequests->sum('amount'),
                'status' => 'pending',
                'project' => $projects->count() > 1 ? $projects->join(',') : $projects->first(),
                'attachment_url' => $shortUrl,
                'attachment_name' => $fileName,
            ]);

            // **ACTUALIZACIÓN ATÓMICA DE REQUESTS**
            Request::whereIn('unique_id', $requestIds)->update([
                'reposicion_id' => $reposicion->id,
                'status' => 'in_reposition',
                'updated_at' => Carbon::now()
            ]);

            DB::commit();

            // Cargar relaciones para respuesta
            $reposicion->load('requests.account');

            return response()->json([
                'message' => 'Reposición creada exitosamente',
                'data' => $reposicion
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating reposicion', [
                'message' => $e->getMessage(),
                'user' => $user->email ?? 'unknown',
                'request_ids' => $requestIds ?? []
            ]);

            return response()->json([
                'message' => 'No se pudo crear la reposición',
                'error' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    public function update(HttpRequest $request, $id)
    {
        try {
            DB::beginTransaction();

            $reposicion = Reposicion::findOrFail($id);

            $validated = $request->validate([
                'status' => 'sometimes|in:pending,paid,rejected,review',
                'month' => 'sometimes|string',
                'when' => 'sometimes|in:rol,liquidación,decimo_tercero,decimo_cuarto,utilidades',
                'note' => 'sometimes|string',
            ]);

            $requests = Request::where('reposicion_id', (string) $reposicion->id)->get(); // ✅ Agregado casting

            // Si se está actualizando el estado
            if (isset($validated['status']) && $validated['status'] !== $reposicion->status) {
                $requestStatus = match ($validated['status']) {
                    'paid' => 'paid',
                    'rejected' => 'rejected',
                    'pending' => 'pending',
                    'review' => 'review',
                    default => 'pending'
                };

                // Actualizar el estado de todas las solicitudes asociadas
                foreach ($requests as $req) {
                    $req->update(['status' => $requestStatus]);
                }
            }

            // Actualizar el campo 'when' si está presente
            if (isset($validated['when'])) {
                Request::where('reposicion_id', (string) $reposicion->id)->update(['when' => $validated['when']]);
            }
            // Actualizar el campo 'month' si está presente y NO es prestamo
            if (isset($validated['month'])) {
                Request::where('reposicion_id', (string) $reposicion->id)
                    ->whereRaw("LEFT(unique_id, 1) != 'P'")
                    ->update(['month' => $validated['month']]);
            }

            $user = $this->authService->getUser($request);
            Request::where('reposicion_id', (string) $reposicion->id) // ✅ Agregado casting
                ->update(['updated_by' => $user->name]);

            $reposicion->update($validated);
            $reposicion = $reposicion->fresh();

            DB::commit();

            return response()->json([
                'message' => 'Reposición actualizada exitosamente',
                'data' => $reposicion
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating reposicion:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al actualizar la reposición',
                'error' => $e->getMessage()
            ], 422);
        }
    }


    public function show($id, HttpRequest $request)
    {
        $reposicion = Reposicion::findOrFail($id);
        $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());

        return response()->json($reposicion);
    }

    public function file($id)
    {
        try {
            $reposicion = Reposicion::findOrFail($id);

            if (!$reposicion->attachment_name) {
                return response()->json(['message' => 'No se encontró archivo adjunto para esta reposición'], 404);
            }

            // Configurar el cliente de Google Cloud Storage
            $base64Key = env('GOOGLE_CLOUD_KEY_BASE64');
            if (!$base64Key) {
                throw new \Exception('La clave de Google Cloud no está definida en el archivo .env.');
            }

            $credentials = json_decode(base64_decode($base64Key), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Error al decodificar las credenciales de Google Cloud: ' . json_last_error_msg());
            }

            $storage = new StorageClient([
                'keyFile' => $credentials
            ]);

            $bucketName = env('GOOGLE_CLOUD_BUCKET');
            $bucket = $storage->bucket($bucketName);
            if (!$bucket->exists()) {
                throw new \Exception("El bucket '$bucketName' no existe o no es accesible");
            }

            // Obtener el objeto del archivo usando attachment_name y generar la URL firmada
            $object = $bucket->object($reposicion->attachment_name);
            if (!$object->exists()) {
                return response()->json(['message' => 'El archivo no existe en Google Cloud Storage'], 404);
            }

            $fileUrl = $object->signedUrl(new \DateTime('+10 years'));

            // Devolver la metadata con la URL generada
            $metadata = [
                'file_url' => $fileUrl,
                'file_name' => $reposicion->attachment_name,
            ];

            return response()->json($metadata);
        } catch (\Exception $e) {
            Log::error('Failed to get file URL', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Error al recuperar la URL del archivo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $reposicion = Reposicion::findOrFail($id);

            // Eliminar el archivo de Google Cloud Storage si existe
            if ($reposicion->attachment_name) {
                $bucket = $this->storage->bucket($this->bucketName);
                $object = $bucket->object($reposicion->attachment_name);
                if ($object->exists()) {
                    $object->delete();
                }
            }

            // Usar la relación requests para actualizar las solicitudes
            Request::whereIn('reposicion_id', $reposicion->id)
                ->update(['status' => 'deleted']);

            $reposicion->delete();

            DB::commit();

            return response()->json([
                'message' => 'Reposición eliminada correctamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al eliminar la reposición',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
