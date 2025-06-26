<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use SoapClient;
use Throwable;

class SriAuthorizationService
{
    private SoapClient $client;

    public function __construct()
    {
        if (! extension_loaded('soap')) {
            throw new \Exception('La extensión SOAP de PHP no está habilitada.');
        }

        // Opciones para SoapClient
        $opts = [
            'cache_wsdl'        => WSDL_CACHE_MEMORY,
            'trace'             => true,           // habilita trazas para debug
            'exceptions'        => true,
            'connection_timeout' => 30,             // timeout de conexión (segundos)
            'stream_context'    => stream_context_create([
                'http' => [
                    'timeout' => 60,            // timeout de lectura (segundos)
                ],
            ]),
        ];

        try {
            $this->client = new \SoapClient(
                config('services.sri.autorizacion_wsdl'),
                $opts
            );
        } catch (\Throwable $e) {
            Log::error("Error iniciando SoapClient SRI", [
                'wsdl'    => config('services.sri.autorizacion_wsdl'),
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Devuelve el string XML de <comprobante>…</comprobante>
     * @throws \Exception
     */
    public function getComprobanteXml(string $claveAcceso): string
    {
        // Número máximo de intentos y pausa entre ellos (ms)
        $maxAttempts = 5;
        $sleepMillis = 1000;

        $resp = retry($maxAttempts, function () use ($claveAcceso) {
            try {
                return $this->client->autorizacionComprobante([
                    'claveAccesoComprobante' => $claveAcceso,
                ]);
            } catch (Throwable $e) {
                // logea cada intento fallido
                Log::warning("[sri-txt] Intento SOAP fallido para $claveAcceso: " . $e->getMessage());
                throw $e;
            }
        }, $sleepMillis);

        $aut = $resp
            ->RespuestaAutorizacionComprobante
            ->autorizaciones
            ->autorizacion;

        // Puede venir como array o como objeto único
        if (is_array($aut)) {
            $aut = array_shift($aut);
        }

        $xml = (string) ($aut->comprobante ?? '');
        if (! $xml) {
            throw new \Exception("No se pudo obtener el XML para clave {$claveAcceso}");
        }

        return $xml;
    }
}
