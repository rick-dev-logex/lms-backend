<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAreaRequest;
use App\Http\Requests\UpdateAreaRequest;
use App\Models\Area;
use Illuminate\Http\JsonResponse;

class AreaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Area::all()]);
    }

    public function store(StoreAreaRequest $request): JsonResponse
    {
        $area = Area::create($request->validated());
        return response()->json([
            'data' => $area,
            'message' => 'Area created successfully',
        ], 201);
    }

    public function show(Area $area): JsonResponse
    {
        return response()->json(['data' => $area]);
    }

    public function update(UpdateAreaRequest $request, Area $area): JsonResponse
    {
        $area->update($request->validated());
        return response()->json([
            'data' => $area,
            'message' => 'Area updated successfully',
        ]);
    }

    public function destroy(Area $area): JsonResponse
    {
        $area->delete();
        return response()->json(['message' => 'Area deleted successfully']);
    }
}
