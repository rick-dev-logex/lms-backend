<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequestRequest;
use App\Http\Requests\UpdateRequestRequest;
use App\Http\Requests\ImportRequestsRequest;
use App\Http\Requests\UploadDiscountsRequest;
use App\Imports\RequestsImport;
use App\Models\Account;
use App\Models\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class RequestController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $assignedProjectIds = $user->assignedProjects ? $user->assignedProjects->projects : [];

        $query = Request::with(['account:id,name'])
            ->whereIn('project', array_map('strval', $assignedProjectIds));

        if (request('type')) $query->where('type', request('type'));
        if (request('status')) {
            if (request('action') === 'count') {
                $countQuery = clone $query;
                $month = request('month', now()->month);
                return response()->json([
                    'data' => $countQuery->where('status', request('status'))
                        ->whereMonth('created_at', $month)
                        ->whereYear('created_at', now()->year)
                        ->count()
                ]);
            }
            $query->where('status', request('status'));
        }

        if (request('period', 'last_3_months') === 'last_3_months') {
            $query->where('created_at', '>=', now()->subMonths(3));
        }

        $sortField = request('sort_by', 'created_at');
        $sortOrder = request('sort_order', 'desc');
        $allowedSortFields = ['unique_id', 'created_at', 'updated_at', 'amount', 'project', 'status'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortOrder);
        }

        $requests = $query->get();
        $responsibles = DB::connection('sistema_onix')
            ->table('onix_personal')
            ->whereIn('id', $requests->pluck('responsible_id')->filter()->unique())
            ->pluck('nombre_completo', 'id');
        $transports = DB::connection('sistema_onix')
            ->table('onix_vehiculos')
            ->whereIn('id', $requests->pluck('transport_id')->filter()->unique())
            ->pluck('name', 'id');
        $projects = DB::connection('sistema_onix')
            ->table('onix_proyectos')
            ->whereIn('id', $assignedProjectIds)
            ->pluck('name', 'id');

        $data = $requests->map(fn($request) => array_merge($request->toArray(), [
            'responsible_name' => $responsibles[$request->responsible_id] ?? null,
            'transport_name' => $transports[$request->transport_id] ?? null,
            'project_name' => $projects[$request->project] ?? 'Unknown',
        ]));

        return response()->json(['data' => $data]);
    }

    public function store(StoreRequestRequest $request): JsonResponse
    {
        $projectId = DB::connection('sistema_onix')
            ->table('onix_proyectos')
            ->where('name', $request->project)
            ->value('id');
        if (!$projectId) {
            return response()->json(['message' => 'Proyecto no encontrado'], 422);
        }

        $prefix = $request->type === 'expense' ? 'G-' : 'D-';
        $lastId = Request::where('type', $request->type)->max('id') + 1 ?? 1;
        $uniqueId = sprintf('%s%05d', $prefix, $lastId);

        $requestData = array_merge($request->validated(), [
            'project' => $projectId,
            'unique_id' => $uniqueId,
            'status' => 'pending',
        ]);

        $newRequest = Request::create($requestData);
        return response()->json([
            'data' => $newRequest,
            'message' => 'Request created successfully',
        ], 201);
    }

    public function show(Request $requestModel): JsonResponse
    {
        $requestModel->load(['account:id,name']);
        $responsible = $requestModel->responsible_id ? DB::connection('sistema_onix')
            ->table('onix_personal')
            ->where('id', $requestModel->responsible_id)
            ->select('id', 'nombre_completo')
            ->first() : null;
        $transport = $requestModel->transport_id ? DB::connection('sistema_onix')
            ->table('onix_vehiculos')
            ->where('id', $requestModel->transport_id)
            ->select('id', 'name')
            ->first() : null;

        $requestData = $requestModel->toArray();
        $requestData['responsible'] = $responsible;
        $requestData['transport'] = $transport;

        return response()->json(['data' => $requestData]);
    }

    public function update(UpdateRequestRequest $request, Request $requestModel): JsonResponse
    {
        if ($request->project) {
            $projectId = DB::connection('sistema_onix')
                ->table('onix_proyectos')
                ->where('name', $request->project)
                ->value('id');
            if (!$projectId) {
                return response()->json(['message' => 'Proyecto no encontrado'], 422);
            }
            $requestModel->project = $projectId;
        }

        $requestModel->update($request->validated());
        return response()->json([
            'data' => $requestModel,
            'message' => 'Request updated successfully',
        ]);
    }

    public function destroy(Request $requestModel): JsonResponse
    {
        $requestModel->delete();
        return response()->json(['message' => 'Request deleted successfully']);
    }

    public function import(ImportRequestsRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $import = new RequestsImport($request->context, auth()->user()->id);
            Excel::import($import, $request->file('file'));

            if (!empty($import->errors)) {
                return response()->json(['message' => 'Errores en la importación', 'errors' => $import->errors], 422);
            }

            return response()->json(['message' => 'Importación exitosa']);
        });
    }

    public function uploadDiscounts(UploadDiscountsRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $discounts = json_decode($request->data, true);
            $errors = [];
            $processedCount = 0;

            foreach ($discounts as $index => $discount) {
                try {
                    $personnelType = strtolower(trim(str_replace('ó', 'o', $discount['Tipo'])));
                    $personnelType = in_array($personnelType, ['nomina', 'nómina']) ? 'nomina' : 'transportista';

                    $projectId = DB::connection('sistema_onix')
                        ->table('onix_proyectos')
                        ->where('name', trim($discount['Proyecto']))
                        ->value('id');
                    if (!$projectId) {
                        throw new \Exception("Proyecto no encontrado: {$discount['Proyecto']}");
                    }

                    $accountId = Account::where('name', $discount['Cuenta'])->value('id');
                    if (!$accountId) {
                        throw new \Exception("Cuenta no encontrada: {$discount['Cuenta']}");
                    }

                    $responsibleId = $personnelType === 'nomina' ? DB::connection('sistema_onix')
                        ->table('onix_personal')
                        ->where('nombre_completo', $discount['Responsable'])
                        ->value('id') : null;
                    $transportId = $personnelType === 'transportista' ? DB::connection('sistema_onix')
                        ->table('onix_vehiculos')
                        ->where('name', $discount['Placa'])
                        ->value('id') : null;

                    if ($personnelType === 'nomina' && !$responsibleId) {
                        throw new \Exception("Responsable no encontrado: {$discount['Responsable']}");
                    }
                    if ($personnelType === 'transportista' && !$transportId) {
                        throw new \Exception("Vehículo no encontrado: {$discount['Placa']}");
                    }

                    $prefix = 'D-';
                    $lastId = Request::where('type', 'discount')->max('id') + 1 ?? 1;
                    $uniqueId = sprintf('%s%05d', $prefix, $lastId);

                    Request::create([
                        'type' => 'discount',
                        'personnel_type' => $personnelType,
                        'status' => 'pending',
                        'request_date' => date('Y-m-d', strtotime($discount['Fecha'])),
                        'invoice_number' => $discount['No. Factura'],
                        'account_id' => $accountId,
                        'amount' => floatval($discount['Valor']),
                        'project' => $projectId,
                        'responsible_id' => $responsibleId,
                        'transport_id' => $transportId,
                        'note' => $discount['Observación'],
                        'unique_id' => $uniqueId,
                    ]);

                    $processedCount++;
                } catch (\Exception $e) {
                    $errors[] = ['row' => $index + 2, 'data' => $discount, 'error' => $e->getMessage()];
                }
            }

            if (empty($errors)) {
                return response()->json([
                    'message' => 'Descuentos procesados exitosamente',
                    'data' => ['processed' => $processedCount],
                ], 201);
            }

            return response()->json(['message' => 'Errores al procesar descuentos', 'errors' => $errors], 422);
        });
    }
}
