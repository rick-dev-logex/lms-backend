<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReposicionRequest;
use App\Http\Requests\UpdateReposicionRequest;
use App\Models\Reposicion;
use App\Models\Request;
use Carbon\Carbon;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReposicionController extends Controller
{
    private $storage;
    private $bucketName;

    public function __construct()
    {
        $base64Key = env('GOOGLE_CLOUD_KEY_BASE64');
        if (!$base64Key) {
            Log::error('La variable GOOGLE_CLOUD_KEY_BASE64 no está definida en el archivo .env');
            throw new \RuntimeException('Configuración de Google Cloud Storage no encontrada');
        }

        $credentials = json_decode(base64_decode($base64Key), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Error al decodificar GOOGLE_CLOUD_KEY_BASE64: ' . json_last_error_msg());
            throw new \RuntimeException('Credenciales de Google Cloud Storage inválidas');
        }

        $this->storage = new StorageClient(['keyFile' => $credentials]);
        $this->bucketName = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'lms-archivos');
    }

    public function index(): JsonResponse
    {
        $user = auth()->user();
        $assignedProjectIds = $user->assignedProjects ? $user->assignedProjects->projects : [];

        $query = Reposicion::whereIn('project', array_map('strval', $assignedProjectIds));

        if (request('period', 'last_3_months') === 'last_3_months') {
            $query->where('created_at', '>=', now()->subMonths(3));
        }
        if (request('project')) $query->where('project', request('project'));
        if (request('status')) $query->where('status', request('status'));
        if (request('month')) $query->where('month', request('month'));

        $reposiciones = $query->get();
        $reposiciones->each(fn($reposicion) => $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get()));

        $projects = DB::connection('sistema_onix')
            ->table('onix_proyectos')
            ->whereIn('id', $assignedProjectIds)
            ->pluck('name', 'id');

        $data = $reposiciones->map(fn($reposicion) => array_merge($reposicion->toArray(), [
            'project_name' => $projects[$reposicion->project] ?? 'Unknown',
        ]));

        return response()->json(['data' => $data]);
    }

    public function store(StoreReposicionRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $requests = Request::whereIn('unique_id', $request->request_ids)->get();
            if ($requests->pluck('project')->unique()->count() > 1) {
                return response()->json(['message' => 'All requests must belong to the same project'], 422);
            }

            $file = $request->file('attachment');
            $fileName = $file->getClientOriginalName();
            $bucket = $this->storage->bucket($this->bucketName);
            if (!$bucket->exists()) {
                Log::error("El bucket '{$this->bucketName}' no existe o no es accesible");
                return response()->json(['message' => 'Bucket de almacenamiento no disponible'], 500);
            }

            $object = $bucket->upload(fopen($file->getRealPath(), 'r'), ['name' => $fileName]);
            $fileUrl = $object->signedUrl(new \DateTime('+10 years'));

            $reposicion = Reposicion::create([
                'fecha_reposicion' => Carbon::now(),
                'total_reposicion' => $requests->sum('amount'),
                'status' => 'pending',
                'project' => $requests->first()->project,
                'detail' => $request->request_ids,
                'attachment_url' => $fileUrl,
                'attachment_name' => $fileName,
                'note' => $request->note,
            ]);

            Request::whereIn('unique_id', $request->request_ids)->update(['status' => 'in_reposition']);
            $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());

            return response()->json([
                'data' => $reposicion,
                'message' => 'Reposición created successfully',
            ], 201);
        });
    }

    public function show(Reposicion $reposicion): JsonResponse
    {
        $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());
        return response()->json(['data' => $reposicion]);
    }

    public function update(UpdateReposicionRequest $request, Reposicion $reposicion): JsonResponse
    {
        return DB::transaction(function () use ($request, $reposicion) {
            if ($request->status && $request->status !== $reposicion->status) {
                if ($request->status === 'paid') {
                    $calculatedTotal = $reposicion->requests()->sum('amount');
                    if (abs($calculatedTotal - $reposicion->total_reposicion) > 0.01) {
                        return response()->json(['message' => 'Total mismatch between requests and reposicion'], 422);
                    }
                }
                Request::whereIn('unique_id', $reposicion->detail)->update(['status' => $request->status]);
            }

            if ($request->when) {
                Request::whereIn('unique_id', $reposicion->detail)->update(['when' => $request->when]);
            }

            if ($request->month) {
                Request::whereIn('unique_id', $reposicion->detail)->update(['month' => $request->month]);
            }

            $reposicion->update($request->validated());
            return response()->json([
                'data' => $reposicion->fresh(),
                'message' => 'Reposición updated successfully',
            ]);
        });
    }

    public function destroy(Reposicion $reposicion): JsonResponse
    {
        return DB::transaction(function () use ($reposicion) {
            if ($reposicion->attachment_name) {
                $bucket = $this->storage->bucket($this->bucketName);
                $object = $bucket->object($reposicion->attachment_name);
                if ($object->exists()) {
                    $object->delete();
                } else {
                    Log::warning("Archivo '{$reposicion->attachment_name}' no encontrado en el bucket '{$this->bucketName}'");
                }
            }

            Request::whereIn('unique_id', $reposicion->detail)->update(['status' => 'pending']);
            $reposicion->delete();

            return response()->json(['message' => 'Reposición deleted successfully']);
        });
    }

    public function file(Reposicion $reposicion): JsonResponse
    {
        if (!$reposicion->attachment_url) {
            return response()->json(['message' => 'No attachment found'], 404);
        }

        return response()->json([
            'data' => [
                'file_url' => $reposicion->attachment_url,
                'file_name' => $reposicion->attachment_name,
            ],
        ]);
    }
}
