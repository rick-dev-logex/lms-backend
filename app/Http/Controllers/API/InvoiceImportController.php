<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\InvoiceImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Http\JsonResponse;
use Throwable;

class InvoiceImportController extends Controller
{
    protected InvoiceImportService $importService;

    /**
     * Mapeo de identificaciónComprador => source.
     */
    private array $sourceMap = [
        '0992301066001' => 'PREBAM',
        '1792162696001' => 'SERSUPPORT',
    ];

    public function __construct(InvoiceImportService $importService)
    {
        $this->importService = $importService;
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'xml_files'   => 'required|array',
            'xml_files.*' => 'file|mimes:xml,txt,application/xml|max:512',
        ]);

        $imported = [];
        $errors   = [];

        foreach ($request->file('xml_files') as $file) {
            try {
                $content      = File::get($file->getRealPath());
                $source       = $this->determineSource($content);
                $originalName = $file->getClientOriginalName();

                $invoice = $this->importService
                    ->importFromXml($content, $originalName, $source);

                if ($invoice) {
                    $imported[] = $invoice->id;
                }
            } catch (Throwable $e) {
                $errors[] = [
                    'file'  => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success'  => true,
            'imported' => $imported,
            'errors'   => $errors,
        ]);
    }

    private function determineSource(string $xmlContent): string
    {
        $xml = @simplexml_load_string($xmlContent);
        if (! $xml) {
            return 'DESCONOCIDA';
        }

        // Algunas facturas pueden venir directo en <factura> o bajo <comprobante>
        $factNode = $xml->comprobante ?? $xml;

        // Identificación siempre está en infoFactura->identificacionComprador
        $idComprador = (string) ($factNode->infoFactura->identificacionComprador ?? '');

        return $this->sourceMap[$idComprador] ?? 'DESCONOCIDA';
    }
}
