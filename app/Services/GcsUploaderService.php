<?php

namespace App\Services;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GcsUploaderService
{
    private StorageClient $storage;
    private string $bucketName;

    public function __construct()
    {
        $bucket = config('gcs.bucket');
        $keyBase64 = config('gcs.credentials_base64');

        if (empty($bucket) || empty($keyBase64)) {
            throw new RuntimeException('La configuración de Google Cloud es inválida.');
        }

        $credentials = json_decode(base64_decode($keyBase64), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Error al decodificar las credenciales GCS: ' . json_last_error_msg());
        }

        $this->storage = new StorageClient([
            'keyFile' => $credentials,
        ]);

        $this->bucketName = $bucket;
    }

    public function upload(string $path, string $content): string
    {
        $bucket = $this->storage->bucket($this->bucketName);

        if (!$bucket->exists()) {
            throw new RuntimeException("El bucket '{$this->bucketName}' no existe.");
        }

        $bucket->upload($content, [
            'name' => $path,
        ]);

        return "https://storage.googleapis.com/{$this->bucketName}/" . rawurlencode($path);
    }
}
