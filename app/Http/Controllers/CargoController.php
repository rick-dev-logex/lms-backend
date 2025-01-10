<?php

namespace App\Http\Controllers;

use App\Models\Cargo;
use App\Http\Resources\CargoResource;
use Illuminate\Http\Request;

class CargoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return CargoResource::collection(Cargo::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'cargo' => 'required|string',
        ]);

        $personal = Cargo::create($validated);
        return new CargoResource($personal);
    }

    /**
     * Display the specified resource.
     */
    public function show(Cargo $cargo)
    {
        return new CargoResource($cargo);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Cargo $cargo)
    {
        $validated = $request->validate([
            'cargo' => 'sometimes|string',
        ]);

        $cargo->update($validated);
        return new CargoResource($cargo);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Cargo $cargo)
    {
        $cargo->delete();
        return response()->noContent();
    }
}
