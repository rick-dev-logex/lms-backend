<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Request;
use App\Notifications\RequestNotification;
use App\Rules\ActivePersonnelRule;
use App\Rules\ProjectAccountRule;
use App\Services\PersonnelService;
use Illuminate\Http\Request as HttpRequest;

class RequestController extends Controller
{
    private $personnelService;

    public function __construct(PersonnelService $personnelService)
    {
        $this->personnelService = $personnelService;
    }

    public function store(HttpRequest $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:expense,discount',
            'personnel_type' => 'required|in:nomina,transportista',
            'request_date' => 'required|date' || date('Y-m-d'),
            'invoice_number' => 'required|string',
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0',
            'project' => ['required', new ProjectAccountRule($request)],
            'responsible_id' => $request->personnel_type === 'nomina' ? ['required', new ActivePersonnelRule($request, $this->personnelService)] : 'nullable',
            'transport_id' => $request->personnel_type === 'transportista' ? 'required|exists:vehiculos,id' : 'nullable',
            'attachment_path' => 'required|string',
            'note' => 'required|string'
        ]);

        $prefix = $validated['type'] === 'expense' ? 'G-' : 'D-';
        $validated['unique_id'] = $prefix . uniqid();
        $validated['status'] = 'pending';

        $request = Request::create($validated);

        return response()->json([
            'message' => 'Request created successfully',
            'data' => $request
        ], 201);
    }

    public function show(Request $request)
    {
        return response()->json($request->load(['account', 'project', 'responsible', 'transport']));
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
