<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\Request;

class AreaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $areas = Area::get();
        return response()->json($areas);
    }

    public function store(Request $request)
    {
        $area = Area::create($request->all());
        return response()->json($area, 201);
    }

    public function show($id)
    {
        $area = Area::find($id);
        return response()->json($area);
    }

    public function update(Request $request, $id)
    {
        $area = Area::find($id);
        $area->update($request->all());
        return response()->json($area);
    }

    public function destroy($id)
    {
        $area = Area::find($id);
        $area->delete();
        return response()->json(['message' => 'Area deleted successfully']);
    }
}
