<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ThirdPartyApp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ThirdPartyAppController extends Controller
{
    // Obtener todas las aplicaciones registradas
    public function index()
    {
        return response()->json(ThirdPartyApp::all());
    }

    // Registrar una nueva aplicación
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:third_party_apps,name'
        ]);

        $app = ThirdPartyApp::create([
            'name' => $request->name,
            'app_key' => Str::random(32)
        ]);

        return response()->json($app, 201);
    }

    // Mostrar una aplicación específica
    public function show($id)
    {
        $app = ThirdPartyApp::find($id);

        if (!$app) {
            return response()->json(['error' => 'Not Found'], 404);
        }

        return response()->json($app);
    }

    // Actualizar una aplicación (solo el nombre)
    public function update(Request $request, $id)
    {
        $app = ThirdPartyApp::find($id);

        if (!$app) {
            return response()->json(['error' => 'Not Found'], 404);
        }

        $request->validate([
            'name' => 'required|string|unique:third_party_apps,name,' . $id
        ]);

        $app->update(['name' => $request->name]);

        return response()->json($app);
    }

    // Eliminar una aplicación
    public function destroy($id)
    {
        $app = ThirdPartyApp::find($id);

        if (!$app) {
            return response()->json(['error' => 'Not Found'], 404);
        }

        $app->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
