<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\UpdateLoanRequest;
use App\Models\Loan;
use App\Models\Request;
use App\Models\Reposicion;
use Carbon\Carbon;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LoanController extends Controller
{
    private $storage;
    private $bucketName;

    public function __construct()
    {
        $base64Key = env('GOOGLE_CLOUD_KEY_BASE64');
        if (!$base64Key) {
            Log::error('La variable GOOGLE_CLOUD_KEY_BASE64 no está definida en el .env');
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

        $query = Loan::with(['account:id,name'])
            ->whereIn('project', array_map('strval', $assignedProjectIds));

        if (request('type')) $query->where('type', request('type'));
        if (request('status')) $query->where('status', request('status'));

        $loans = $query->get();
        $projects = DB::connection('sistema_onix')
            ->table('onix_proyectos')
            ->whereIn('id', $assignedProjectIds)
            ->pluck('name', 'id');

        $data = $loans->map(fn($loan) => array_merge($loan->toArray(), [
            'project_name' => $projects[$loan->project] ?? 'Unknown',
        ]));

        return response()->json(['data' => $data]);
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $user = auth()->user();
            $assignedProjectIds = $user->assignedProjects ? $user->assignedProjects->projects : [];
            $projectUuid = DB::connection('sistema_onix')
                ->table('onix_proyectos')
                ->whereIn('id', $assignedProjectIds)
                ->where('name', $request->project)
                ->value('id');

            if (!$projectUuid) {
                return response()->json(['message' => 'Proyecto no asignado o inexistente'], 422);
            }

            $file = $request->file('attachment');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $bucket = $this->storage->bucket($this->bucketName);
            $object = $bucket->upload(fopen($file->getRealPath(), 'r'), ['name' => $fileName]);
            $fileUrl = $object->signedUrl(new \DateTime('+10 years'));

            $loan = Loan::create([
                'loan_date' => now(),
                'type' => $request->type,
                'account_id' => $request->account_id,
                'amount' => $request->amount,
                'project' => $projectUuid,
                'file_path' => $fileUrl,
                'note' => $request->note,
                'installments' => $request->installments,
                'responsible_id' => $request->type === 'nomina' ? $request->responsible_id : null,
                'vehicle_id' => $request->type === 'proveedor' ? $request->vehicle_id : null,
                'status' => 'pending',
            ]);

            $amountPerInstallment = $request->amount / $request->installments;
            $requestIds = [];

            foreach ($request->installment_dates as $index => $date) {
                $prefix = 'P-';
                $lastId = Request::where('unique_id', 'like', 'P-%')->max('id') + 1 ?? 1;
                $uniqueId = sprintf('%s%05d', $prefix, $lastId);

                $newRequest = Request::create([
                    'unique_id' => $uniqueId,
                    'type' => 'discount',
                    'personnel_type' => $request->type,
                    'status' => 'in_reposition',
                    'request_date' => Carbon::createFromFormat('Y-m', $date)->startOfMonth(),
                    'invoice_number' => $request->invoice_number,
                    'account_id' => $request->account_id,
                    'amount' => round($amountPerInstallment, 2),
                    'project' => $projectUuid,
                    'responsible_id' => $loan->responsible_id,
                    'transport_id' => $loan->vehicle_id,
                    'note' => "Cuota " . ($index + 1) . " de préstamo ID: {$loan->id}",
                ]);

                $requestIds[] = $newRequest->unique_id;
            }

            $reposicion = Reposicion::create([
                'fecha_reposicion' => now(),
                'total_reposicion' => $request->amount,
                'status' => 'pending',
                'project' => $projectUuid,
                'detail' => $requestIds,
                'attachment_url' => $fileUrl,
                'attachment_name' => $fileName,
                'note' => $request->note,
            ]);

            $loan->load('account');
            $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());

            return response()->json([
                'data' => ['loan' => $loan, 'reposicion' => $reposicion],
                'message' => 'Préstamo creado exitosamente',
            ], 201);
        });
    }

    public function show(Loan $loan): JsonResponse
    {
        $loan->load(['account', 'responsible', 'vehicle', 'requests']);
        return response()->json(['data' => $loan]);
    }

    public function update(UpdateLoanRequest $request, Loan $loan): JsonResponse
    {
        return DB::transaction(function () use ($request, $loan) {
            if ($request->status && $request->status !== $loan->status) {
                $requestStatus = in_array($request->status, ['pending', 'paid', 'rejected', 'review']) ? $request->status : 'pending';

                if ($requestStatus === 'paid') {
                    $calculatedTotal = $loan->requests()->sum('amount');
                    if (abs($calculatedTotal - $loan->amount) > 0.01) {
                        return response()->json(['message' => 'Total de cuotas no coincide con el monto'], 422);
                    }
                }

                $loan->requests()->update(['status' => $requestStatus]);
            }

            if ($request->installment_dates) {
                $requests = $loan->requests()->get();
                if ($requests->count() !== count($request->installment_dates)) {
                    return response()->json(['message' => 'Número de fechas no coincide con cuotas'], 422);
                }

                foreach ($requests as $index => $req) {
                    $req->update([
                        'request_date' => Carbon::createFromFormat('Y-m', $request->installment_dates[$index])->startOfMonth(),
                    ]);
                }
            }

            $loan->update($request->validated());
            $loan->load('requests');

            return response()->json([
                'data' => $loan,
                'message' => 'Préstamo actualizado exitosamente',
            ]);
        });
    }

    public function destroy(Loan $loan): JsonResponse
    {
        return DB::transaction(function () use ($loan) {
            if ($loan->file_path) {
                $bucket = $this->storage->bucket($this->bucketName);
                $object = $bucket->object(basename($loan->file_path));
                if ($object->exists()) $object->delete();
            }

            $reposicion = Reposicion::whereJsonContains('detail', $loan->requests->pluck('unique_id')->first())->first();
            if ($reposicion) {
                if ($reposicion->attachment_name) {
                    $bucket = $this->storage->bucket($this->bucketName);
                    $object = $bucket->object($reposicion->attachment_name);
                    if ($object->exists()) $object->delete();
                }
                $reposicion->delete();
            }

            $loan->requests()->delete();
            $loan->delete();

            return response()->json(['message' => 'Préstamo eliminado exitosamente']);
        });
    }

    public function file(Loan $loan): JsonResponse
    {
        if (!$loan->file_path) {
            return response()->json(['message' => 'No hay archivo adjunto'], 404);
        }

        return response()->json([
            'data' => [
                'file_url' => $loan->file_path,
                'file_name' => basename($loan->file_path),
            ],
        ]);
    }
}
