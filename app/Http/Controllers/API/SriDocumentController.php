<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SriDocument;
use App\Services\Sri\SriXmlGeneratorService;
use App\Services\Sri\SriPdfGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class SriDocumentController extends Controller
{
    protected $xmlGenerator;
    protected $pdfGenerator;

    public function __construct(
        SriXmlGeneratorService $xmlGenerator,
        SriPdfGeneratorService $pdfGenerator
    ) {
        $this->xmlGenerator = $xmlGenerator;
        $this->pdfGenerator = $pdfGenerator;
    }

    /**
     * Mostrar una lista de todos los documentos.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $documents = SriDocument::latest()->get();
        return response()->json($documents);
    }

    /**
     * Obtener un documento específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $document = SriDocument::findOrFail($id);
        return response()->json($document);
    }

    /**
     * Actualizar un documento específico.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $document = SriDocument::findOrFail($id);

        $validated = $request->validate([
            'valor_sin_impuestos' => 'nullable|numeric',
            'iva' => 'nullable|numeric',
            'importe_total' => 'nullable|numeric',
            'identificacion_receptor' => 'nullable|string|max:20',
        ]);

        $document->update($validated);

        return response()->json([
            'message' => 'Documento actualizado correctamente',
            'data' => $document
        ]);
    }

    /**
     * Actualizar múltiples documentos a la vez.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function batchUpdate(Request $request)
    {
        $data = $request->validate([
            'documents' => 'required|array',
            'documents.*.id' => 'required|exists:sri_documents,id',
            'documents.*.valor_sin_impuestos' => 'nullable|numeric',
            'documents.*.iva' => 'nullable|numeric',
            'documents.*.importe_total' => 'nullable|numeric',
            'documents.*.identificacion_receptor' => 'nullable|string|max:20',
        ]);

        $updated = [];
        $errors = [];

        foreach ($data['documents'] as $docData) {
            try {
                $document = SriDocument::find($docData['id']);

                if (!$document) {
                    $errors[] = [
                        'id' => $docData['id'],
                        'error' => 'Documento no encontrado'
                    ];
                    continue;
                }

                $updateData = [
                    'valor_sin_impuestos' => $docData['valor_sin_impuestos'] ?? $document->valor_sin_impuestos,
                    'iva' => $docData['iva'] ?? $document->iva,
                    'importe_total' => $docData['importe_total'] ?? $document->importe_total,
                    'identificacion_receptor' => $docData['identificacion_receptor'] ?? $document->identificacion_receptor,
                ];

                $document->update($updateData);
                $updated[] = $document;
            } catch (Exception $e) {
                Log::error('Error al actualizar documento', [
                    'id' => $docData['id'],
                    'error' => $e->getMessage()
                ]);

                $errors[] = [
                    'id' => $docData['id'],
                    'error' => 'Error al actualizar: ' . $e->getMessage()
                ];
            }
        }

        return response()->json([
            'message' => 'Documentos actualizados',
            'updated_count' => count($updated),
            'errors_count' => count($errors),
            'errors' => $errors
        ]);
    }

    /**
     * Eliminar un documento.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $document = SriDocument::findOrFail($id);
        $document->delete();

        return response()->json([
            'message' => 'Documento eliminado correctamente'
        ]);
    }

    /**
     * Genera y devuelve el XML para un documento específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function generateXml($id)
    {
        try {
            $document = SriDocument::findOrFail($id);

            // Preparar datos para generar el XML
            $data = [
                'CLAVE_ACCESO' => $document->clave_acceso,
                'RUC_EMISOR' => $document->ruc_emisor,
                'RAZON_SOCIAL_EMISOR' => $document->razon_social_emisor,
                'TIPO_COMPROBANTE' => $document->tipo_comprobante,
                'SERIE_COMPROBANTE' => $document->serie_comprobante,
                'FECHA_EMISION' => $document->fecha_emision ? Carbon::parse($document->fecha_emision)->format('d/m/Y') : null,
                'FECHA_AUTORIZACION' => $document->fecha_autorizacion ? Carbon::parse($document->fecha_autorizacion)->format('d/m/Y H:i:s') : null,
                'VALOR_SIN_IMPUESTOS' => $document->valor_sin_impuestos,
                'IVA' => $document->iva,
                'IMPORTE_TOTAL' => $document->importe_total,
                'IDENTIFICACION_RECEPTOR' => $document->identificacion_receptor,
            ];

            // Generar el XML
            $generatedXmls = $this->xmlGenerator->generate([$data]);

            if (empty($generatedXmls)) {
                return response()->json(['error' => 'No se pudo generar el XML'], 500);
            }

            $xmlContent = $generatedXmls[0]['contenido'];

            // Generar un nombre de archivo para la descarga
            $filename = $document->clave_acceso . '.xml';

            return response($xmlContent)
                ->header('Content-Type', 'application/xml')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        } catch (Exception $e) {
            Log::error('Error al generar XML', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Error al generar el XML: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Genera y devuelve el PDF para un documento específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function generatePdf($id)
    {
        try {
            $document = SriDocument::findOrFail($id);

            // Preparar datos para generar el PDF
            $data = [
                'CLAVE_ACCESO' => $document->clave_acceso,
                'RUC_EMISOR' => $document->ruc_emisor,
                'RAZON_SOCIAL_EMISOR' => $document->razon_social_emisor,
                'TIPO_COMPROBANTE' => $document->tipo_comprobante,
                'SERIE_COMPROBANTE' => $document->serie_comprobante,
                'FECHA_EMISION' => $document->fecha_emision ? Carbon::parse($document->fecha_emision)->format('d/m/Y') : null,
                'FECHA_AUTORIZACION' => $document->fecha_autorizacion ? Carbon::parse($document->fecha_autorizacion)->format('d/m/Y H:i:s') : null,
                'VALOR_SIN_IMPUESTOS' => $document->valor_sin_impuestos,
                'IVA' => $document->iva,
                'IMPORTE_TOTAL' => $document->importe_total,
                'IDENTIFICACION_RECEPTOR' => $document->identificacion_receptor,
            ];

            // Generar el PDF
            $pdfContent = $this->pdfGenerator->generateFromData($data);

            if (!$pdfContent) {
                return response()->json(['error' => 'No se pudo generar el PDF'], 500);
            }

            // Generar un nombre de archivo para la descarga
            $filename = $document->clave_acceso . '.pdf';

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        } catch (Exception $e) {
            Log::error('Error al generar PDF', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Error al generar el PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene estadísticas de los documentos (para el dashboard).
     *
     * @return \Illuminate\Http\Response
     */
    public function getStats()
    {
        try {
            // Total de documentos
            $totalDocuments = SriDocument::count();

            // Monto total 
            $totalAmount = SriDocument::sum('importe_total');

            // Conteo de proveedores únicos
            $providerCount = SriDocument::select('ruc_emisor')->distinct()->count();

            // Top proveedores
            $topProviders = SriDocument::select('razon_social_emisor as name')
                ->selectRaw('COUNT(*) as value')
                ->groupBy('razon_social_emisor')
                ->orderByDesc('value')
                ->limit(10)
                ->get();

            // Documentos por mes
            $byMonth = SriDocument::selectRaw('DATE_FORMAT(fecha_emision, "%b %Y") as name')
                ->selectRaw('SUM(importe_total) as amount')
                ->selectRaw('COUNT(*) as count')
                ->where('fecha_emision', '>=', Carbon::now()->subMonths(6))
                ->groupBy('name')
                ->orderBy('fecha_emision')
                ->get();

            return response()->json([
                'totalDocuments' => $totalDocuments,
                'totalAmount' => (float) $totalAmount,
                'providerCount' => $providerCount,
                'byProvider' => $topProviders,
                'byMonth' => $byMonth
            ]);
        } catch (Exception $e) {
            Log::error('Error al obtener estadísticas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }
}
