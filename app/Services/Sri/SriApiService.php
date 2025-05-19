<?php

namespace App\Services\Sri;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class SriApiService
{
    protected $baseUrl = 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/';

    /**
     * Obtiene información detallada de un documento desde el SRI
     *
     * @param string $claveAcceso La clave de acceso del documento
     * @return array Información adicional del documento
     */
    public function getDocumentInfo(string $claveAcceso): array
    {
        try {
            // Construir el envelope SOAP para la consulta
            $soapEnvelope = '<?xml version="1.0" encoding="UTF-8"?>
                <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ec="http://ec.gob.sri.ws.autorizacion">
                   <soapenv:Header/>
                   <soapenv:Body>
                      <ec:autorizacionComprobante>
                         <claveAccesoComprobante>' . $claveAcceso . '</claveAccesoComprobante>
                      </ec:autorizacionComprobante>
                   </soapenv:Body>
                </soapenv:Envelope>';

            // Hacer la petición SOAP
            $response = Http::withHeaders([
                'Content-Type' => 'text/xml;charset=UTF-8',
                'SOAPAction' => ''
            ])->withBody($soapEnvelope, 'text/xml')
                ->post($this->baseUrl . 'AutorizacionComprobantesOffline');

            if (!$response->successful()) {
                Log::warning('Error al consultar API del SRI: HTTP ' . $response->status(), [
                    'clave_acceso' => $claveAcceso
                ]);
                return [];
            }

            // Procesar la respuesta XML
            return $this->parseResponseData($response->body(), $claveAcceso);
        } catch (\Exception $e) {
            Log::error('Error al consultar API del SRI', [
                'clave_acceso' => $claveAcceso,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Parsea la respuesta XML de la API del SRI
     *
     * @param string $responseXml La respuesta XML de la API
     * @param string $claveAcceso La clave de acceso consultada
     * @return array Datos estructurados del documento
     */
    protected function parseResponseData(string $responseXml, string $claveAcceso): array
    {
        try {
            // Convertir la respuesta a SimpleXMLElement
            $xml = new SimpleXMLElement($responseXml);

            // Registrar namespaces para XPath
            $namespaces = $xml->getNamespaces(true);

            // Extraer namespace por defecto
            $ns = $xml->getNamespaces()[''];

            // Registrar prefijos para el namespace SOAP
            $xml->registerXPathNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');
            $xml->registerXPathNamespace('ec', 'http://ec.gob.sri.ws.autorizacion');

            // Buscar el nodo de respuesta y autorizaciones
            $respuesta = $xml->xpath('//soapenv:Body/*[local-name()="autorizacionComprobanteResponse"]/*[local-name()="RespuestaAutorizacionComprobante"]');

            if (empty($respuesta)) {
                Log::warning('No se encontró respuesta en el XML del SRI', [
                    'clave_acceso' => $claveAcceso
                ]);
                return [];
            }

            $respuesta = $respuesta[0];
            $autorizaciones = $respuesta->autorizaciones->autorizacion ?? null;

            if (!$autorizaciones) {
                Log::warning('No se encontraron autorizaciones en la respuesta del SRI', [
                    'clave_acceso' => $claveAcceso
                ]);
                return [];
            }

            // Verificar el estado de la autorización
            $estado = (string)$autorizaciones->estado;
            if ($estado !== 'AUTORIZADO') {
                Log::warning('El comprobante no está autorizado en el SRI', [
                    'clave_acceso' => $claveAcceso,
                    'estado' => $estado
                ]);
                return [
                    'estado' => $estado,
                    'mensaje' => (string)$autorizaciones->mensajes->mensaje->mensaje ?? 'No autorizado'
                ];
            }

            // Extraer el comprobante en XML
            $comprobante = null;
            if (isset($autorizaciones->comprobante)) {
                try {
                    $comprobanteXml = new SimpleXMLElement((string)$autorizaciones->comprobante);
                    $comprobante = $comprobanteXml;
                } catch (\Exception $e) {
                    Log::warning('Error al parsear el comprobante del SRI', [
                        'clave_acceso' => $claveAcceso,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Extraer datos básicos de la autorización
            $result = [
                'estado' => $estado,
                'numeroAutorizacion' => (string)$autorizaciones->numeroAutorizacion,
                'fechaAutorizacion' => (string)$autorizaciones->fechaAutorizacion,
                'ambiente' => (string)$autorizaciones->ambiente,
            ];

            // Si tenemos el comprobante, extraer datos adicionales
            if ($comprobante) {
                // Extraer infoTributaria
                $infoTributaria = $comprobante->infoTributaria ?? null;
                if ($infoTributaria) {
                    $result['razonSocial'] = (string)$infoTributaria->razonSocial;
                    $result['nombreComercial'] = (string)$infoTributaria->nombreComercial;
                    $result['ruc'] = (string)$infoTributaria->ruc;
                    $result['dirMatriz'] = (string)$infoTributaria->dirMatriz;
                    $result['codDoc'] = (string)$infoTributaria->codDoc;
                }

                // Extraer infoFactura o similar según tipo de documento
                $infoDoc = $comprobante->infoFactura ?? $comprobante->infoNotaCredito ?? $comprobante->infoNotaDebito ?? null;
                if ($infoDoc) {
                    $result['fechaEmision'] = (string)$infoDoc->fechaEmision;
                    $result['razonSocialComprador'] = (string)$infoDoc->razonSocialComprador;
                    $result['identificacionComprador'] = (string)$infoDoc->identificacionComprador;

                    // Valores del documento
                    $result['totalSinImpuestos'] = (string)$infoDoc->totalSinImpuestos;
                    $result['totalDescuento'] = (string)($infoDoc->totalDescuento ?? 0);
                    $result['importeTotal'] = (string)$infoDoc->importeTotal;

                    // Dirección del comprador si existe
                    if (isset($infoDoc->direccionComprador)) {
                        $result['direccionComprador'] = (string)$infoDoc->direccionComprador;
                    }
                }

                // Extraer detalles
                if (isset($comprobante->detalles->detalle)) {
                    $detalles = [];
                    foreach ($comprobante->detalles->detalle as $detalle) {
                        $detalleItem = [
                            'codigoPrincipal' => (string)($detalle->codigoPrincipal ?? ''),
                            'descripcion' => (string)$detalle->descripcion,
                            'cantidad' => (float)$detalle->cantidad,
                            'precioUnitario' => (float)$detalle->precioUnitario,
                            'descuento' => (float)($detalle->descuento ?? 0),
                            'precioTotal' => (float)$detalle->precioTotalSinImpuesto
                        ];

                        // Extraer impuestos del detalle
                        if (isset($detalle->impuestos->impuesto)) {
                            $impuesto = $detalle->impuestos->impuesto;
                            $detalleItem['codigoIVA'] = (string)$impuesto->codigoPorcentaje;
                            $detalleItem['valorIVA'] = (float)$impuesto->valor;
                        }

                        $detalles[] = $detalleItem;
                    }
                    $result['detalles'] = $detalles;
                }

                // Extraer información adicional
                if (isset($comprobante->infoAdicional->campoAdicional)) {
                    $infoAdicional = [];
                    foreach ($comprobante->infoAdicional->campoAdicional as $campo) {
                        $nombre = (string)$campo['nombre'];
                        $valor = (string)$campo;
                        $infoAdicional[$nombre] = $valor;
                    }
                    $result['infoAdicional'] = $infoAdicional;
                }

                // Extraer impuestos
                if (isset($infoDoc->totalConImpuestos->totalImpuesto)) {
                    $impuestos = [];
                    foreach ($infoDoc->totalConImpuestos->totalImpuesto as $impuesto) {
                        $codigo = (string)$impuesto->codigo;
                        $codigoPorcentaje = (string)$impuesto->codigoPorcentaje;
                        $baseImponible = (float)$impuesto->baseImponible;
                        $valor = (float)$impuesto->valor;

                        // Categorizar según código de impuesto
                        if ($codigo == '2') { // IVA
                            if ($codigoPorcentaje == '0') { // 0%
                                $result['subtotal0'] = $baseImponible;
                            } elseif ($codigoPorcentaje == '2' || $codigoPorcentaje == '3') { // 12% o 14%
                                $result['subtotal12'] = $baseImponible;
                                $result['iva'] = $valor;
                            }
                        }

                        $impuestos[] = [
                            'codigo' => $codigo,
                            'codigoPorcentaje' => $codigoPorcentaje,
                            'baseImponible' => $baseImponible,
                            'valor' => $valor
                        ];
                    }
                    $result['impuestos'] = $impuestos;
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Error al procesar respuesta XML del SRI', [
                'clave_acceso' => $claveAcceso,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
}
