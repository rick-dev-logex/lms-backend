<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TxtUploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain'],
        ]);

        $file = $request->file('file');

        $path = $file->storeAs('txt_uploads', uniqid() . '.txt', 'local');

        $fullPath = storage_path('app/' . $path);

        // Leer y procesar el archivo
        $lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $fields = explode('|', $line); // depende de cómo venga el TXT, ajustamos

            // Aquí haces el procesamiento de cada línea:
            // Por ejemplo:
            // $ruc = $fields[0];
            // $fechaEmision = $fields[1];
            // $numeroFactura = $fields[2];
            // etc.

            // Guardar en base de datos, ejemplo:
            // Invoice::create([...]);
        }

        return response()->json([
            'message' => 'Archivo procesado correctamente',
        ]);
    }
}
