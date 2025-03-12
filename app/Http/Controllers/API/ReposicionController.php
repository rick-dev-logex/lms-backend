<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Reposicion;
use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
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
            $query = Reposicion::query();

            // Aplicar filtros
            if ($request->filled('search')) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('project', 'like', "%{$searchTerm}%")
                        ->orWhere('total_reposicion', 'like', "%{$searchTerm}%")
                        ->orWhere('note', 'like', "%{$searchTerm}%");
                });
            }

            if ($request->filled('project')) {
                $query->byProject($request->project);
            }

            if ($request->filled('status')) {
                $query->byStatus($request->status);
            }

            if ($request->filled('month')) {
                $query->byMonth($request->month);
            }

            // Ordenamiento
            $sortField = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $allowedSortFields = ['created_at', 'fecha_reposicion', 'total_reposicion', 'project', 'status'];

            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortOrder);
            }

            // Paginación
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            // Obtener todos los unique_ids de las solicitudes
            $allRequestIds = collect($paginator->items())
                ->pluck('detail')
                ->flatten()
                ->unique()
                ->values()
                ->toArray();

            // Cargar las solicitudes con sus relaciones básicas
            $requests = Request::whereIn('unique_id', $allRequestIds)
                ->with('account:id,name')
                ->get()
                ->keyBy('unique_id');

            // Obtener IDs únicos para responsables y transportes
            $responsibleIds = $requests->pluck('responsible_id')->filter()->unique();
            $transportIds = $requests->pluck('transport_id')->filter()->unique();

            // Cargar datos de responsables
            $responsibles = $responsibleIds->isNotEmpty()
                ? DB::connection('sistema_onix')
                ->table('onix_personal')
                ->whereIn('id', $responsibleIds)
                ->select('id', 'nombre_completo')
                ->get()
                ->keyBy('id')
                : collect();

            // Cargar datos de transportes
            $transports = $transportIds->isNotEmpty()
                ? DB::connection('sistema_onix')
                ->table('onix_vehiculos')
                ->whereIn('id', $transportIds)
                ->select('id', 'name')
                ->get()
                ->keyBy('id')
                : collect();

            // Mapear los datos incluyendo toda la información relacionada
            $data = collect($paginator->items())->map(function ($reposicion) use ($requests, $responsibles, $transports) {
                // Mapear las solicitudes con sus relaciones
                $reposicionRequests = collect($reposicion->detail)->map(function ($requestId) use ($requests, $responsibles, $transports) {
                    $request = $requests->get($requestId);
                    if ($request) {
                        // Agregar información del responsable si existe
                        if ($request->responsible_id && $responsibles->has($request->responsible_id)) {
                            $request->responsible = [
                                'id' => $request->responsible_id,
                                'nombre_completo' => $responsibles->get($request->responsible_id)->nombre_completo
                            ];
                        }

                        // Agregar información del transporte si existe
                        if ($request->transport_id && $transports->has($request->transport_id)) {
                            $request->transport = [
                                'id' => $request->transport_id,
                                'name' => $transports->get($request->transport_id)->name
                            ];
                        }
                    }
                    return $request;
                })->filter();

                $reposicion->requests = $reposicionRequests->values();
                return $reposicion;
            });

            return response()->json([
                'data' => $data,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'has_more' => $paginator->hasMorePages(),
                ]
            ]);
        } catch (\Exception $e) {
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

            // Validar la entrada
            $validated = $request->validate([
                'request_ids' => 'required|array',
                'request_ids.*' => 'exists:requests,unique_id',
                'attachment' => 'required|file'
            ]);

            // Obtener las solicitudes usando el array directamente
            $requests = Request::whereIn('unique_id', $validated['request_ids'])->get();

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
                    Log::info('Valor de GOOGLE_CLOUD_KEY_BASE64: ' . $base64Key); // Depurar

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
                'detail' => $validated['request_ids'],
                'attachment_url' => $fileUrl ?? null,
                'attachment_name' => $fileName ?? null
            ]);

            // Actualizar el estado de las solicitudes relacionadas
            Request::whereIn('unique_id', $validated['request_ids'])
                ->update(['status' => 'in_reposition']);

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
                // Actualizar estado de las solicitudes según el estado de la reposición
                $requestStatus = match ($validated['status']) {
                    'paid' => 'paid',
                    'rejected' => 'rejected',
                    'pending' => 'pending',
                    'review' => 'review',
                    default => 'pending'
                };

                // Actualizar todas las solicitudes asociadas
                Request::whereIn('unique_id', $reposicion->detail)
                    ->update([
                        'status' => $requestStatus,
                        'note' => $validated['note'] ?? null
                    ]);

                // Verificación adicional para aprobación
                if ($validated['status'] === 'paid') {
                    $calculatedTotal = $reposicion->calculateTotal();
                    if ($calculatedTotal != $reposicion->total_reposicion) {
                        throw new \Exception('Total mismatch between requests and reposicion');
                    }
                }
            }

            $reposicion->update($validated);
            $reposicion = $reposicion->fresh();

            DB::commit();

            return response()->json([
                'message' => 'Reposición updated successfully',
                'data' => $reposicion
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error updating reposición',
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
