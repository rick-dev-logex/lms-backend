<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CajaChica;
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
                if (is_object($user->assignedProjects) && isset($user->assignedProjects->projects)) {
                    $projectsValue = $user->assignedProjects->projects;
                    if (is_string($projectsValue)) {
                        $assignedProjectIds = json_decode($projectsValue, true) ?: [];
                    } elseif (is_array($projectsValue)) {
                        $assignedProjectIds = $projectsValue;
                    }
                } elseif (is_array($user->assignedProjects)) {
                    $assignedProjectIds = $user->assignedProjects;
                }
            }

            $assignedProjectIds = !empty($assignedProjectIds)
                ? array_map('strval', $assignedProjectIds)
                : [];

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
                $query->where('project', $request->input('project'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }
            if ($request->filled('month')) {
                $query->where('month', $request->input('month'));
            }

            $reposiciones = $query->orderByDesc('id')->get();

            $reposiciones->each(function ($reposicion) {
                $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());
            });

            // Filtrar según el parámetro type
            $type = $request->input('type');
            if ($type === 'income') {
                // Mostrar solo ingresos (todas las solicitudes son I-XXXX)
                $reposiciones = $reposiciones->filter(function ($reposicion) {
                    $requestIds = $reposicion->requests->pluck('unique_id')->all();
                    return !empty($requestIds) && count($requestIds) === count(array_filter($requestIds, fn($id) => str_starts_with($id, 'I-')));
                });
            } else {
                // Excluir ingresos (no todas las solicitudes son I-XXXX)
                $reposiciones = $reposiciones->filter(function ($reposicion) {
                    $requestIds = $reposicion->requests->pluck('unique_id')->all();
                    return empty($requestIds) || count($requestIds) !== count(array_filter($requestIds, fn($id) => str_starts_with($id, 'I-')));
                });
            }

            // Transform data
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

    public function store(HttpRequest $request)
    {
        try {
            if (!env('GOOGLE_CLOUD_KEY_BASE64')) {
                $dotenv = \Dotenv\Dotenv::createImmutable(base_path());
                $dotenv->load();
            }
            DB::beginTransaction();

            // Obtener request_ids
            $requestIds = $request->input('request_ids', $request->input('request_ids', []));

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

            // Validar tamaño del archivo (límite de 20MB como ejemplo)
            $file = $request->file('attachment');
            $maxFileSize = 20 * 1024 * 1024; // 20MB en bytes
            if ($file->getSize() > $maxFileSize) {
                throw ValidationException::withMessages([
                    'attachment' => ['El archivo es demasiado grande. El tamaño máximo permitido es 20MB. Reduce el tamaño e intenta de nuevo.'],
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
            $fileName = null;
            $shortUrl = null;
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

                // Generar una URL acortada (solo la base sin parámetros de firma)
                $shortUrl = "https://storage.googleapis.com/{$bucketName}/" . urlencode($fileName);
                if (strlen($shortUrl) > 255) {
                    // Si aún es demasiado larga, usar un hash corto como identificador
                    $shortUrl = "https://storage.googleapis.com/{$bucketName}/" . substr(hash('sha256', $fileName), 0, 20);
                }
            }

            // Crear la reposición con la URL acortada
            $reposicion = Reposicion::create([
                'fecha_reposicion' => Carbon::now(),
                'total_reposicion' => $requests->sum('amount'),
                'status' => 'pending',
                'project' => $project,
                'detail' => $requestIds,
                'attachment_url' => $shortUrl ?? null, // URL acortada
                'attachment_name' => $fileName ?? null, // Nombre completo del archivo
            ]);

            $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());

            // Actualizar el estado de las solicitudes relacionadas
            Request::whereIn('unique_id', $requestIds)
                ->update(['reposicion_id' => $reposicion->id, 'status' => 'in_reposition']);

            // Para caja chica
            foreach ($requestIds as $uniqueId) {
                $codigo = "CAJA CHICA " . $reposicion->id . " " . $uniqueId;

                CajaChica::where('codigo', 'LIKE', "CAJA CHICA %{$uniqueId}")->update([
                    'codigo' => $codigo,
                    'estado' => 'EN REPOSICIÓN',
                ]);

                CajaChica::where('CODIGO', 'LIKE', "CAJA CHICA %{$uniqueId}")->update([
                    'CODIGO' => $codigo,
                    'ESTADO' => 'EN REPOSICIÓN',
                ]);
            }

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
                foreach ($reposicion->detail as $uniqueId) {
                    CajaChica::where('codigo', 'LIKE', "CAJA CHICA %{$uniqueId}")
                        ->update(['estado' => $requestStatus]);
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
