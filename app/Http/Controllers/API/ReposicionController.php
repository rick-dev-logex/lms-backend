<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Reposicion;
use App\Models\Request;
use App\Models\User;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function PHPUnit\Framework\isArray;

class ReposicionController extends Controller
{
    private $storage;
    private $bucketName;

    public function __construct()
    {
        $this->storage = new StorageClient([
            'keyFilePath' => env('GOOGLE_CLOUD_KEY_FILE')
        ]);
        $this->bucketName = env('GOOGLE_CLOUD_STORAGE_BUCKET');
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

            // Procesar proyectos asignados correctamente
            $assignedProjectIds = [];
            if ($user && isset($user->assignedProjects)) {
                // Si es una relación con un objeto, extraer la propiedad 'projects'
                if (is_object($user->assignedProjects) && isset($user->assignedProjects->projects)) {
                    $projectsValue = $user->assignedProjects->projects;

                    // Si 'projects' es una cadena JSON, decodificarla
                    if (is_string($projectsValue)) {
                        $assignedProjectIds = json_decode($projectsValue, true) ?: [];
                    }
                    // Si ya es un array, usarlo directamente
                    else if (is_array($projectsValue)) {
                        $assignedProjectIds = $projectsValue;
                    }
                }
                // Si es un array directo o una colección, usarlo
                else if (is_array($user->assignedProjects)) {
                    $assignedProjectIds = $user->assignedProjects;
                }
            }

            // Asegurar que tenemos un array plano
            if (!empty($assignedProjectIds)) {
                // Convertir todos los IDs a string para consistencia
                $assignedProjectIds = array_map('strval', $assignedProjectIds);
            }

            $query = Reposicion::query();

            $period = $request->input('period', 'last_3_months');

            // Fetch project names for the user's assigned UUIDs
            $projectNames = [];
            if (!empty($assignedProjectIds)) {
                $projectNames = DB::connection('sistema_onix')
                    ->table('onix_proyectos')
                    ->whereIn('id', $assignedProjectIds)
                    ->pluck('name')
                    ->toArray();
                $query->whereIn('project', $projectNames);
            }

            if ($period === 'last_3_months') {
                $query->where('created_at', '>=', now()->subMonths(3));
            }
            if ($request->filled('project')) {
                $query->where('project', $request->project);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('month')) {
                $query->where('month', $request->month);
            }

            $reposiciones = $query->orderByDesc('id')->get();

            $reposiciones->each(function ($reposicion) {
                $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());
            });

            // Transform data
            $projects = !empty($assignedProjectIds) ? DB::connection('sistema_onix')
                ->table('onix_proyectos')
                ->whereIn('id', $assignedProjectIds)
                ->select('id', 'name')
                ->get()
                ->mapWithKeys(function ($project) {
                    return [$project->id => $project->name];
                })->all() : [];
            $data = $reposiciones->map(function ($reposicion) use ($projects) {
                $repoData = $reposicion->toArray();
                $repoData['project_name'] = $reposicion->project; // Already a name
                return $repoData;
            })->values()->all();

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error in ReposicionController@index:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error al procesar la solicitud',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Otros métodos del controlador permanecen sin cambios...

    public function store(HttpRequest $request)
    {
        try {
            if (!env('GOOGLE_CLOUD_KEY_BASE64')) {
                $dotenv = \Dotenv\Dotenv::createImmutable(base_path());
                $dotenv->load();
            }
            DB::beginTransaction();

            // Obtener request_ids
            Log::info('Request Payload:', $request->all());
            $requestIds = $request->input('request_ids', $request->input('request_ids', []));
            Log::info('Extracted request_ids:', ['request_ids' => $requestIds]);

            if (empty($requestIds)) {
                throw ValidationException::withMessages(['request_ids' => ['Los request_ids son requeridos.']]);
            }

            // Asegurar que RequestIds es un array
            $requestIds = is_array($requestIds) ? $requestIds : [$requestIds];

            // Fetch de requests existentes
            $existingRequests = Request::whereIn('unique_id', $requestIds)->get();

            // Validación manual
            if (!$requestIds || !is_array($requestIds)) {
                throw ValidationException::withMessages([
                    'request_ids' => ['The request_ids field is required and must be an array.'],
                ]);
            }
            if ($existingRequests->count() !== count($requestIds)) {
                throw ValidationException::withMessages([
                    'request_ids' => ['One or more request_ids do not exist in the requests table.'],
                ]);
            }
            if (!$request->hasFile('attachment')) {
                throw ValidationException::withMessages([
                    'attachment' => ['The attachment field is required.'],
                ]);
            }

            // Obtener las solicitudes
            $requests = $existingRequests;

            if ($requests->isEmpty()) {
                throw new \Exception('No requests found with the provided IDs');
            }

            $project = $requests->first()->project;

            if ($requests->pluck('project')->unique()->count() > 1) {
                throw new \Exception('All requests must belong to the same project');
            }

            // Procesar y subir el archivo a Google Cloud Storage
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
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
                    $object = $bucket->upload(
                        fopen($file->getRealPath(), 'r'),
                        [
                            'name' => $fileName,
                            'predefinedAcl' => null,
                        ]
                    );
                } catch (\Exception $e) {
                    throw new \Exception('Error al subir el archivo a Google Cloud Storage');
                }

                // Obtener la URL del archivo
                $fileUrl = $object->signedUrl(new \DateTime('+ 10 years'));
            }

            // Crear la reposición
            $reposicion = Reposicion::create([
                'fecha_reposicion' => Carbon::now(),
                'total_reposicion' => $requests->sum('amount'),
                'status' => 'pending',
                'project' => $project,
                'detail' => $requestIds,
                'attachment_url' => $fileUrl ?? null,
                'attachment_name' => $fileName ?? null
            ]);

            $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());

            // Actualizar el estado de las solicitudes relacionadas
            Request::whereIn('unique_id', $requestIds)
                ->update(['reposicion_id' => $reposicion->id, 'status' => 'in_reposition']);

            DB::commit();

            // Cargar las solicitudes para la respuesta
            $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());

            return response()->json([
                'message' => 'Reposición created successfully',
                'data' => $reposicion
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error creating reposición',
                'error' => $e->getMessage()
            ], 422);
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

            // Si se está actualizando el estado
            if (isset($validated['status']) && $validated['status'] !== $reposicion->status) {
                $requestStatus = match ($validated['status']) {
                    'paid' => 'paid',
                    'rejected' => 'rejected',
                    'pending' => 'pending',
                    'review' => 'review',
                    default => 'pending'
                };

                // Verificación adicional para aprobación
                if ($requestStatus === 'paid') {
                    $calculatedTotal = $reposicion->calculateTotal();
                    if ($calculatedTotal != $reposicion->total_reposicion) {
                        throw new \Exception('Total mismatch between requests and reposicion');
                    }
                }

                // Actualizar el estado de todas las solicitudes asociadas
                if (is_array($reposicion->detail) && !empty($reposicion->detail)) {
                    Request::whereIn('unique_id', $reposicion->detail)
                        ->update([
                            'status' => $requestStatus
                        ]);
                }
            }

            // Actualizar el campo 'when' si está presente
            if (isset($validated['when']) && is_array($reposicion->detail) && !empty($reposicion->detail)) {
                Request::whereIn('unique_id', $reposicion->detail)
                    ->update([
                        'when' => $validated['when']
                    ]);
            }

            // Actualizar el campo 'month' si está presente
            if (isset($validated['month']) && is_array($reposicion->detail) && !empty($reposicion->detail)) {
                Request::whereIn('unique_id', $reposicion->detail)
                    ->update([
                        'month' => $validated['month']
                    ]);
            }

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

            if (!$reposicion->attachment_url || !$reposicion->attachment_name) {
                return response()->json(['message' => 'No se encontró archivo adjunto para esta reposición'], 404);
            }

            // Devolver directamente la URL almacenada sin consultar GCS
            $metadata = [
                'file_url'     => $reposicion->attachment_url,
                'file_name'    => $reposicion->attachment_name,
            ];

            return response()->json($metadata);
        } catch (\Exception $e) {
            Log::error('Failed to get file URL', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Error al recuperar la URL del archivo',
                'error'   => $e->getMessage()
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
            Request::whereIn('unique_id', $reposicion->detail)
                ->update(['status' => 'pending']);

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
