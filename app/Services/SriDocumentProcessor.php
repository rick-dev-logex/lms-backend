<?php

namespace App\Services\Sri;

use App\Models\SriDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class SriDocumentProcessor
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

    public function process(array $rows): array
    {
        $createdDocuments = [];
        $errors = [];

        // Filtrar registros ya existentes por clave de acceso
        $claves = array_column($rows, 'CLAVE_ACCESO');
        $existentes = SriDocument::whereIn('clave_acceso', $claves)
            ->pluck('clave_acceso')
            ->toArray();

        $nuevos = array_filter($rows, fn($r) => !in_array($r['CLAVE_ACCESO'] ?? '', $existentes));

        // Actualización de documentos existentes
        foreach ($rows as $row) {
            if (!isset($row['CLAVE_ACCESO']) || !in_array($row['CLAVE_ACCESO'], $existentes)) {
                continue;
            }

            try {
                $doc = SriDocument::where('clave_acceso', $row['CLAVE_ACCESO'])->first();

                if ($doc) {
                    $doc->update([
                        'valor_sin_impuestos' => $row['VALOR_SIN_IMPUESTOS'] ?? $doc->valor_sin_impuestos,
                        'iva' => $row['IVA'] ?? $doc->iva,
                        'importe_total' => $row['IMPORTE_TOTAL'] ?? $doc->importe_total,
                        'identificacion_receptor' => $row['IDENTIFICACION_RECEPTOR'] ?? $doc->identificacion_receptor,
                    ]);

                    $createdDocuments[] = $doc;
                }
            } catch (Exception $e) {
                $errors[] = [
                    'clave' => $row['CLAVE_ACCESO'] ?? 'desconocida',
                    'error' => 'Error al actualizar: ' . $e->getMessage()
                ];

                Log::error('Error al actualizar documento SRI', [
                    'clave' => $row['CLAVE_ACCESO'] ?? 'desconocida',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($nuevos)) {
            return [
                'created' => $createdDocuments,
                'errors' => $errors
            ];
        }

        foreach ($nuevos as $row) {
            try {
                if (!isset($row['CLAVE_ACCESO'])) {
                    $errors[] = [
                        'error' => 'Falta clave de acceso en el registro'
                    ];
                    continue;
                }

                // Generar nombres de archivo
                $fileName = $row['CLAVE_ACCESO'];
                $xmlName = "{$fileName}.xml";
                $pdfName = "{$fileName}.pdf";

                // Crear el documento con los nuevos nombres de columnas
                $doc = SriDocument::create([
                    'clave_acceso' => $row['CLAVE_ACCESO'],
                    'ruc_emisor' => $row['RUC_EMISOR'] ?? '',
                    'razon_social_emisor' => $row['RAZON_SOCIAL_EMISOR'] ?? '',
                    'tipo_comprobante' => $row['TIPO_COMPROBANTE'] ?? '',
                    'serie_comprobante' => $row['SERIE_COMPROBANTE'] ?? '',
                    'nombre_xml' => $xmlName,
                    'nombre_pdf' => $pdfName,
                    // Usando los nuevos nombres de columnas
                    'xml_path_identifier' => "XML/{$xmlName}",
                    'pdf_path_identifier' => "PDF/{$pdfName}",
                    'fecha_autorizacion' => $this->parseDate($row['FECHA_AUTORIZACION'] ?? null),
                    'fecha_emision' => $this->parseDate($row['FECHA_EMISION'] ?? null),
                    'valor_sin_impuestos' => $row['VALOR_SIN_IMPUESTOS'] ?? null,
                    'iva' => $row['IVA'] ?? null,
                    'importe_total' => $row['IMPORTE_TOTAL'] ?? null,
                    'identificacion_receptor' => $row['IDENTIFICACION_RECEPTOR'] ?? null,
                ]);

                $createdDocuments[] = $doc;
            } catch (Exception $e) {
                $errors[] = [
                    'clave' => $row['CLAVE_ACCESO'] ?? 'desconocida',
                    'error' => $e->getMessage()
                ];

                Log::error('Error al procesar documento SRI', [
                    'clave' => $row['CLAVE_ACCESO'] ?? 'desconocida',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'created' => $createdDocuments,
            'errors' => $errors
        ];
    }

    protected function parseDate(?string $date): ?string
    {
        if (!$date) return null;

        try {
            // Soporta: "24/04/2025 10:25:12"
            return Carbon::createFromFormat('d/m/Y H:i:s', $date)->toDateTimeString();
        } catch (Exception) {
            try {
                return Carbon::createFromFormat('d/m/Y', $date)->toDateTimeString();
            } catch (Exception) {
                return null;
            }
        }
    }

    protected function findRowByClave(string $clave, array $rows): ?array
    {
        foreach ($rows as $r) {
            if (($r['CLAVE_ACCESO'] ?? null) === $clave) return $r;
        }
        return null;
    }
}
