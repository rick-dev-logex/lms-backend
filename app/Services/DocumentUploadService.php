<?php

namespace App\Services;

use App\Models\SriDocument;
use Carbon\Carbon;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class DocumentUploadService
{
    protected $storage;
    protected $bucket;

    public function __construct()
    {
        try {
            $base64Key = env('GOOGLE_CLOUD_INVOICE_KEY_BASE64');
            if (!$base64Key) {
                throw new \Exception('La clave de Google Cloud no está definida en el archivo .env.');
            }

            $credentials = json_decode(base64_decode($base64Key), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Error al decodificar las credenciales de Google Cloud: ' . json_last_error_msg());
            }

            Log::info('Credenciales decodificadas correctamente', ['client_email' => $credentials['client_email']]);

            $this->storage = new StorageClient([
                'projectId' => env('GOOGLE_CLOUD_PROJECT_ID', 'logex-alfresco-180118'),
                'keyFile' => $credentials,
            ]);

            $bucketName = env('GOOGLE_CLOUD_INVOICE_BUCKET', 'lms-facturacion');
            Log::info('Intentando acceder al bucket', ['bucket' => $bucketName]);

            $this->bucket = $this->storage->bucket($bucketName);

            if (!$this->bucket->exists()) {
                throw new \Exception("El bucket '$bucketName' no existe o no es accesible");
            }

            Log::info('Conexión al bucket establecida correctamente', ['bucket' => $bucketName]);
        } catch (\Exception $e) {
            Log::error('Error al inicializar Google Cloud Storage', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Error al conectar con Google Cloud Storage: ' . $e->getMessage());
        }
    }

    public function upload(array $data, string $xmlContent, string $pdfContent): SriDocument
    {
        try {
            // Generar nombres únicos
            $clave = $data['CLAVE_ACCESO'];
            $slug = Str::slug($clave);
            $xmlName = "{$slug}.xml";
            $pdfName = "{$slug}.pdf";

            // Definir paths en el bucket
            $xmlPath = "XML/{$xmlName}";
            $pdfPath = "PDF/{$pdfName}";

            Log::info('Subiendo archivo XML', ['path' => $xmlPath]);
            $this->bucket->upload($xmlContent, [
                'name' => $xmlPath,
            ]);

            Log::info('Subiendo archivo PDF', ['path' => $pdfPath]);
            $this->bucket->upload($pdfContent, [
                'name' => $pdfPath,
            ]);

            Log::info('Archivos subidos correctamente', ['xml' => $xmlPath, 'pdf' => $pdfPath]);

            // Parsear fechas en formato d/m/Y H:i:s
            $fechaAutorizacion = $this->parseDate($data['FECHA_AUTORIZACION'], 'FECHA_AUTORIZACION');
            $fechaEmision = $this->parseDate($data['FECHA_EMISION'], 'FECHA_EMISION');

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
                    'fecha_autorizacion' => $fechaAutorizacion,
                    'fecha_emision' => $fechaEmision,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Error al subir archivos a Google Cloud Storage', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Error al subir archivos: ' . $e->getMessage());
        }
    }

    /**
     * Parsea una fecha en formato d/m/Y H:i:s o d/m/Y, con manejo de errores.
     *
     * @param string $dateString
     * @param string $fieldName
     * @return Carbon|null
     * @throws \Exception
     */
    private function parseDate(string $dateString, string $fieldName): ?Carbon
    {
        try {
            // Intentar parsear como d/m/Y H:i:s
            if (preg_match('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/', $dateString)) {
                return Carbon::createFromFormat('d/m/Y H:i:s', $dateString);
            }
            // Intentar parsear como d/m/Y
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateString)) {
                return Carbon::createFromFormat('d/m/Y', $dateString);
            }
            // Agregar más formatos si es necesario
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dateString)) {
                return Carbon::createFromFormat('d-m-Y', $dateString);
            }
            // Registrar formato no reconocido
            Log::warning("Formato de fecha inválido para $fieldName", ['date' => $dateString]);
            return null;
        } catch (\Exception $e) {
            Log::error("Error al parsear fecha para $fieldName", [
                'date' => $dateString,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
