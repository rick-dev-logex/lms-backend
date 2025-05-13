<?php

namespace App\Http\Controllers\API;

use App\Helpers\PdfHelper;
use App\Http\Controllers\Controller;
use App\Services\DocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleXMLElement;

class DocumentGenerationController extends Controller
{
    protected DocumentUploadService $uploadService;

    public function __construct(DocumentUploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    public function generate(Request $request)
    {
        $rows = $request->input('data');

        if (empty($rows)) {
            return response()->json(['message' => 'Faltan datos para procesar'], 400);
        }

        $created = [];

        foreach ($rows as $item) {
            $clave = $item['CLAVE_ACCESO'] ?? Str::uuid()->toString();

            // Generar XML
            $xml = new SimpleXMLElement('<comprobante></comprobante>');
            foreach ($item as $key => $value) {
                $xml->addChild(Str::slug($key, '_'), htmlspecialchars($value));
            }
            $xmlContent = $xml->asXML();

            // Generar PDF
            $html = PdfHelper::generateHtmlFromItem($item);
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
            $pdfContent = $pdf->output();

            // Usar DocumentUploadService para subir al bucket y guardar en la base de datos
            $document = $this->uploadService->upload(
                $item, // Metadata
                $xmlContent, // Contenido del XML
                $pdfContent // Contenido del PDF
            );

            $created[] = $document;
        }

        return response()->json([
            'message' => 'Documentos generados y subidos correctamente.',
            'count' => count($created),
            'data' => $created,
        ]);
    }
}
