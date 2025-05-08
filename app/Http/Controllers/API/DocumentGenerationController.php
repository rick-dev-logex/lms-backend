<?php

namespace App\Http\Controllers\API;

use App\Helpers\PdfHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleXMLElement;

class DocumentGenerationController extends Controller
{
    public function generate(Request $request)
    {
        $folder = $request->input('folder');
        $rows = $request->input('data');

        if (!$folder || empty($rows)) {
            return response()->json(['message' => 'Faltan datos o carpeta'], 400);
        }

        $basePath = storage_path("app/comprobantes/{$folder}");
        $xmlDir = "{$basePath}/XML";
        $pdfDir = "{$basePath}/PDF";

        @mkdir($xmlDir, 0775, true);
        @mkdir($pdfDir, 0775, true);

        foreach ($rows as $item) {
            $clave = $item['CLAVE_ACCESO'] ?? Str::uuid();

            // XML
            $xml = new SimpleXMLElement('<comprobante></comprobante>');
            foreach ($item as $key => $value) {
                $xml->addChild(Str::slug($key, '_'), htmlspecialchars($value));
            }

            file_put_contents("{$xmlDir}/{$clave}.xml", $xml->asXML());

            // PDF: generar con DomPDF a partir de HTML dinámico (sin blade)
            $html = PdfHelper::generateHtmlFromItem($item);


            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
            file_put_contents("{$pdfDir}/{$clave}.pdf", $pdf->output());
        }

        return response()->json(['message' => 'OK']);
    }
}
