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
        // ini_set('default_socket_timeout', 120);
        try {
            $resp = $this->client->autorizacionComprobante([
                'claveAccesoComprobante' => $claveAcceso,
            ]);
        } catch (Throwable $e) {
            throw new \Exception("Error SOAP SRI: " . $e->getMessage());
        }

        $aut = $resp
            ->RespuestaAutorizacionComprobante
            ->autorizaciones
            ->autorizacion;

        // puede venir array o single
        if (is_array($aut)) {
            $aut = array_shift($aut);
        }

        $xml = (string) ($aut->comprobante ?? '');
        if (! $xml) {
            throw new \Exception("No se pudo obtener el XML para clave $claveAcceso");
        }

        return $xml;
    }
}
