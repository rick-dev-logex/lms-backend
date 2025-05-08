<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SriDocument;
use App\Services\DocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SriDocumentController extends Controller
{
    protected DocumentUploadService $service;

    public function __construct(DocumentUploadService $service)
    {
        $this->service = $service;
    }

    public function upload(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'documents' => 'required|array',
            'documents.*.metadata' => 'required|array',
            'documents.*.xml' => 'required|string',
            'documents.*.pdf' => 'required|string', // base64 del PDF o texto si es mock
        ])->validate();

        $created = [];

        foreach ($validated['documents'] as $doc) {
            $created[] = $this->service->upload(
                $doc['metadata'],
                $doc['xml'],
                base64_decode($doc['pdf']) // Si ya está en texto plano, puedes omitir `base64_decode`
            );
        }

        return response()->json([
            'message' => 'Documentos subidos correctamente.',
            'count' => count($created),
            'data' => $created,
        ]);
    }
    public function index()
    {
        return SriDocument::latest()->get();
    }
}
