<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Request;
use App\Notifications\RequestNotification;
use App\Services\PersonnelService;
use Illuminate\Http\Request as HttpRequest;

class RequestController extends Controller
{
    private $personnelService;

    public function __construct(PersonnelService $personnelService)
    {
        $this->personnelService = $personnelService;
    }

    public function index(HttpRequest $request)
    {
        $query = Request::query();

        if ($request->input('action') === 'count') {
            $query = $query->where('status', $request->status);

            if ($request->filled('month')) {
                $query->whereMonth('created_at', $request->input('month'));
            }
            if ($request->filled('year')) {
                $query->whereYear('created_at', $request->input('year'));
            }

            return response()->json($query->count());
        }

        if ($request->filled('fields')) {
            $query->select(explode(',', $request->fields));
        }

        foreach ($request->all() as $key => $value) {
            if ($key === 'fields' || $key === 'action') {
                continue;
            }
            if ($key === 'month') {
                $query->whereMonth('created_at', $value);
            } elseif ($key === 'year') {
                $query->whereYear('created_at', $value);
            } else {
                $query->where($key, $value);
            }
        }

        $query = $query->get();

        return response()->json($query);
    }


    public function store(HttpRequest $request)
    {
        try {
            $baseRules = [
                'type' => 'required|in:expense,discount',
                'personnel_type' => 'required|in:nomina,transportista',
                'request_date' => 'required|date',
                'invoice_number' => 'required|string',
                'account_id' => 'required|exists:accounts,id',
                'amount' => 'required|numeric|min:0',
                'project' => 'required|string',
                'attachment' => 'required|file',
                'note' => 'required|string'
            ];

            if ($request->input('personnel_type') === 'nomina') {
                $baseRules['responsible_id'] = 'required|exists:sistema_onix.onix_personal,id';
            } else {
                $baseRules['transport_id'] = 'required|exists:sistema_onix.onix_vehiculos,id';
            }

            $validated = $request->validate($baseRules);

            // Generar el identificador único incremental con prefijo
            $prefix = $validated['type'] === 'expense' ? 'G-' : 'D-';
            $lastRecord = Request::where('type', $validated['type'])->orderBy('id', 'desc')->first();
            $nextId = $lastRecord ? ((int)str_replace($prefix, '', $lastRecord->unique_id) + 1) : 1;
            $unique_id = $prefix . $nextId;

            $requestData = [
                'type' => $validated['type'],
                'personnel_type' => $validated['personnel_type'],
                'project' => strtoupper($validated['project']),
                'request_date' => $validated['request_date'],
                'invoice_number' => $validated['invoice_number'],
                'account_id' => $validated['account_id'],
                'amount' => $validated['amount'],
                'note' => $validated['note'],
                'unique_id' => $unique_id,
                'attachment_path' => $request->file('attachment')->store('attachments')
            ];

            if (isset($validated['responsible_id'])) {
                $requestData['responsible_id'] = $validated['responsible_id'];
            }
            if (isset($validated['transport_id'])) {
                $requestData['transport_id'] = $validated['transport_id'];
            }

            $newRequest = Request::create($requestData);

            return response()->json([
                'message' => 'Request created successfully',
                'data' => $newRequest
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating request',
                'error' => $e->getMessage()
            ], 422);
        }
    }


    public function show(HttpRequest $request)
    {
        $request->has('status') ? $requests = Request::where('status', $request->status)->get() : $requests = Request::all();
        return response()->json($requests->load(['account', 'project', 'responsible', 'transport']));
    }

    public function update(HttpRequest $request, Request $requestRecord)
    {
        $validated = $request->validate([
            'status' => 'sometimes|in:pending,approved,rejected,review',
            'note' => 'sometimes|string',
        ]);

        $oldStatus = $requestRecord->status;
        $requestRecord->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $responsible = $requestRecord->responsible;

            if ($validated['status'] === 'approved') {
                $responsible->notify(new RequestNotification($requestRecord, 'approved'));
            } elseif ($validated['status'] === 'rejected') {
                $responsible->notify(new RequestNotification($requestRecord, 'rejected'));
            }
        }

        return response()->json($requestRecord);
    }

    public function destroy(Request $requestRecord)
    {
        $requestRecord->delete();
        return response()->json(['message' => 'Request deleted successfully.']);
    }
}
