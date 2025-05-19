<?php

namespace App\Services;

use App\Models\SriDocument;
use App\Services\Sri\SriApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\DocumentSummaryMail;
use Carbon\Carbon;

class AutomationService
{
    protected $sriApiService;

    public function __construct(SriApiService $sriApiService)
    {
        $this->sriApiService = $sriApiService;
    }

    /**
     * Actualiza el estado de todos los documentos del SRI
     * @return array Estadísticas de la actualización
     */
    public function updateAllDocuments(): array
    {
        Log::info('Iniciando actualización automática de documentos SRI');

        $updated = 0;
        $failed = 0;
        $unprocessed = 0;

        // Obtener documentos a actualizar - por ejemplo, solo los del último mes
        $documents = SriDocument::where('fecha_emision', '>=', Carbon::now()->subDays(30))
            ->get();

        foreach ($documents as $document) {
            try {
                if (!$document->clave_acceso) {
                    $unprocessed++;
                    continue;
                }

                // Consultar información actualizada del SRI
                $sriInfo = $this->sriApiService->getDocumentInfo($document->clave_acceso);

                if (!empty($sriInfo)) {
                    // Actualizar información importante
                    $document->update([
                        'valor_sin_impuestos' => $sriInfo['totalSinImpuestos'] ?? $document->valor_sin_impuestos,
                        'iva' => $sriInfo['iva'] ?? $document->iva,
                        'importe_total' => $sriInfo['importeTotal'] ?? $document->importe_total,
                        'estado' => $sriInfo['estado'] ?? 'PENDIENTE',
                    ]);

                    $updated++;
                } else {
                    $unprocessed++;
                }
            } catch (\Exception $e) {
                Log::error('Error al actualizar documento SRI: ' . $e->getMessage(), [
                    'document_id' => $document->id,
                    'clave_acceso' => $document->clave_acceso
                ]);

                $failed++;
            }
        }

        $stats = [
            'total' => $documents->count(),
            'updated' => $updated,
            'failed' => $failed,
            'unprocessed' => $unprocessed,
            'timestamp' => Carbon::now()->toDateTimeString()
        ];

        Log::info('Actualización automática de documentos SRI completada', $stats);

        return $stats;
    }

    /**
     * Envía un resumen diario de documentos
     * @return bool
     */
    public function sendDailySummary(): bool
    {
        try {
            // Obtener documentos del día anterior
            $yesterday = Carbon::yesterday();
            $documents = SriDocument::whereDate('fecha_emision', $yesterday)
                ->orWhereDate('created_at', $yesterday)
                ->get();

            if ($documents->isEmpty()) {
                Log::info('No hay documentos para el resumen diario', [
                    'date' => $yesterday->toDateString()
                ]);

                return true;
            }

            // Calcular estadísticas
            $stats = [
                'count' => $documents->count(),
                'totalAmount' => $documents->sum('importe_total'),
                'date' => $yesterday->format('d/m/Y'),
                'providers' => $documents->groupBy('razon_social_emisor')
                    ->map(function ($docs) {
                        return [
                            'count' => $docs->count(),
                            'total' => $docs->sum('importe_total')
                        ];
                    })
            ];

            // Enviar correo
            Mail::to(config('mail.admin_address', 'admin@ejemplo.com'))
                ->send(new DocumentSummaryMail($stats, $documents));

            Log::info('Resumen diario enviado correctamente', [
                'date' => $yesterday->toDateString(),
                'count' => $documents->count()
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error al enviar resumen diario: ' . $e->getMessage());
            return false;
        }
    }
}
