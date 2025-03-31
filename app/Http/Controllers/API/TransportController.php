<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransportRequest;
use App\Http\Requests\UpdateTransportRequest;
use App\Models\Transport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TransportController extends Controller
{
    public function index(): JsonResponse
    {
        if (request('action') === 'count') {
            $count = DB::connection('tms1')
                ->table('vehiculos')
                ->where('status', 'ACTIVO')
                ->count();
            return response()->json(['data' => $count]);
        }

        $query = Transport::select('id', 'name')->where('deleted', '0');
        if (request('fields')) {
            $query->select(explode(',', request('fields')));
        }

        return response()->json(['data' => $query->orderBy('name', 'asc')->get()]);
    }

    public function store(StoreTransportRequest $request): JsonResponse
    {
        $transport = Transport::create($request->validated());
        return response()->json([
            'data' => $transport,
            'message' => 'Transport created successfully',
        ], 201);
    }

    public function show(Transport $transport): JsonResponse
    {
        return response()->json(['data' => $transport]);
    }

    public function update(UpdateTransportRequest $request, Transport $transport): JsonResponse
    {
        $transport->update($request->validated());
        return response()->json([
            'data' => $transport,
            'message' => 'Transport updated successfully',
        ]);
    }

    public function destroy(Transport $transport): JsonResponse
    {
        $transport->delete();
        return response()->json(['message' => 'Transport deleted successfully']);
    }

    public function getTransportByAccountId($accountId): JsonResponse
    {
        $transports = Transport::where('account_id', $accountId)->get();
        return response()->json(['data' => $transports]);
    }
}
