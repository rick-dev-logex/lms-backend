<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ReportGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class ReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportGeneratorService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function generate(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:compras,iva,ret',
            'period' => 'required|string|regex:/^\d{4}-\d{2}$/' // Formato YYYY-MM
        ]);

        try {
            $type = $request->input('type');
            $period = $request->input('period');

            // Generar el reporte
            $filePath = $this->reportService->generateReport($type, $period);

            // Obtener nombre del archivo
            $fileName = basename($filePath);

            // Retornar el archivo para descargar
            return Response::download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"'
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error("Error al generar reporte", [
                'type' => $request->type,
                'period' => $request->period,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }
}
