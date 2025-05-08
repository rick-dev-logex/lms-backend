<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use App\Models\SriDocument;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DocumentUploadService
{
    public function upload(array $data, string $xmlContent, string $pdfContent): SriDocument
    {
        // Generar nombres únicos
        $clave = $data['CLAVE_ACCESO'];
        $slug = Str::slug($clave);
        $xmlName = "{$slug}.xml";
        $pdfName = "{$slug}.pdf";

        // Definir paths en el bucket
        $xmlPath = "XML/{$xmlName}";
        $pdfPath = "PDF/{$pdfName}";

        // Subir archivos al bucket GCS
        Storage::disk('gcs')->put($xmlPath, $xmlContent);
        Storage::disk('gcs')->put($pdfPath, $pdfContent);

        // Guardar en base de datos
        return SriDocument::updateOrCreate(
            ['clave_acceso' => $clave],
            [
                'ruc_emisor' => $data['RUC_EMISOR'],
                'razon_social_emisor' => $data['RAZON_SOCIAL_EMISOR'],
                'tipo_comprobante' => $data['TIPO_COMPROBANTE'],
                'serie_comprobante' => $data['SERIE_COMPROBANTE'],
                'nombre_xml' => $xmlName,
                'nombre_pdf' => $pdfName,
                'gcs_path_xml' => $xmlPath,
                'gcs_path_pdf' => $pdfPath,
                'fecha_autorizacion' => Carbon::parse($data['FECHA_AUTORIZACION']),
                'fecha_emision' => Carbon::parse($data['FECHA_EMISION']),
            ]
        );
    }
}
