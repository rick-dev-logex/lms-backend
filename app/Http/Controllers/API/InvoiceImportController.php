<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\InvoiceImportService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Exception;

class InvoiceImportController extends Controller
{
    protected InvoiceImportService $importService;

    public function __construct(InvoiceImportService $importService)
    {
        $this->importService = $importService;
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'xml_files' => 'required|array',
            'xml_files.*' => 'file|mimes:xml,text/plain,text/xml,application/xml|max:512',
        ]);

        $imported = [];
        $errors = [];

        foreach ($request->file('xml_files') as $file) {
            try {
                $content = file_get_contents($file->getRealPath());
                $originalName = $file->getClientOriginalName();

                $factura = $this->importService->importFromXml($content, $originalName);
                if ($factura) {
                    $imported[] = $factura->id;
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'imported' => $imported,
            'errors' => $errors
        ]);
    }
}
