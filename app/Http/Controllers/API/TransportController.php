<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Transport;
use Illuminate\Http\Request;

class TransportController extends Controller
{
    public function index(Request $request)
    {
        if ($request->input('action') === 'count') {
            return response()->json(Transport::where('deleted', '0')->count());
        } else {
            $query = Transport::select('id', 'name')->where('deleted', '0');

            // Seleccionar campos específicos si se solicitan
            if ($request->filled('fields')) {
                $query->select(explode(',', $request->fields));
            }

            if ($request->filled('proyecto')) {
                $query->where('proyecto', $request->input('proyecto'));
            }

            return response()->json($query->orderBy('name', 'asc')->get());
        }
    }

    public function store(Request $request)
    {
        $transport = Transport::create($request->all());
        return response()->json($transport, 201);
    }

    public function show($id)
    {
        $transport = Transport::findOrFail($id);
        return response()->json($transport);
    }

    public function update(Request $request, $id)
    {
        $transport = Transport::findOrFail($id);
        $transport->update($request->all());
        return response()->json($transport, 200);
    }

    public function destroy($id)
    {
        $transport = Transport::findOrFail($id);
        $transport->delete();
        return response()->json(['message' => 'Transport deleted successfully'], 204);
    }

    public function getTransportByAccountId($accountId)
    {
        $transport = Transport::where('account_id', $accountId)->get();
        return response()->json($transport);
    }
}
