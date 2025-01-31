<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/preview-email', function () {
    return view('emails.solicitud', [
        'nombre' => 'Juan Pérez',
        'tipo' => 'in_reposition', // Puede ser 'asignacion', 'aprobacion' o 'actualizacion'
        'solicitud_id' => 'G-123',
        'status' => 'Reposición',
        'note' => null,
    ]);
});
