<?php

namespace App\Services\Sri;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class ComprobanteService
{
    // URLs de consulta del SRI
    protected $urlAutorizacionComprobante = 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline';
    protected $urlRecepcionComprobante = 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline';

    // Tiempo de caché para respuestas (1 día)
    protected $tiempoCacheComprobante = 1440;

    /**
     * Consulta un comprobante por su clave de acceso
     *
     * @param string $claveAcceso Clave de acceso del comprobante
     * @return array Información del comprobante
     */
    public function consultarComprobante(string $claveAcceso): array
    {
        try {
            // Validar formato de clave de acceso
            if (strlen($claveAcceso) !== 49 || !ctype_digit($claveAcceso)) {
                return [
                    'success' => false,
                    'message' => 'La clave de acceso debe tener 49 dígitos numéricos'
                ];
            }

            // Verificar si existe en caché
            $cacheKey = "comprobante_clave_{$claveAcceso}";
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            // Construir la solicitud SOAP
            $xmlRequest = $this->buildAutorizacionRequest($claveAcceso);

            // Realizar la consulta con cURL para tener mejor control
            $response = $this->executeSoapRequest($this->urlAutorizacionComprobante, $xmlRequest);

            if ($response['error']) {
                Log::warning('Error al consultar comprobante en el SRI', [
                    'claveAcceso' => $claveAcceso,
                    'error' => $response['error']
                ]);

                return [
                    'success' => false,
                    'message' => 'Error al consultar el comprobante: ' . $response['error']
                ];
            }

            // Procesar la respuesta XML
            $result = $this->procesarRespuestaAutorizacion($response['response'], $claveAcceso);

            // Guardar en caché si fue exitoso
            if ($result['success']) {
                Cache::put($cacheKey, $result, now()->addMinutes($this->tiempoCacheComprobante));
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Error al consultar comprobante', [
                'claveAcceso' => $claveAcceso,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al consultar el comprobante: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Construye la solicitud SOAP para autorización de comprobante
     *
     * @param string $claveAcceso Clave de acceso del comprobante
     * @return string XML de solicitud SOAP
     */
    protected function buildAutorizacionRequest(string $claveAcceso): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ec="http://ec.gob.sri.ws.autorizacion">
               <soapenv:Header/>
               <soapenv:Body>
                  <ec:autorizacionComprobante>
                     <claveAccesoComprobante>' . $claveAcceso . '</claveAccesoComprobante>
                  </ec:autorizacionComprobante>
               </soapenv:Body>
            </soapenv:Envelope>';
    }

    /**
     * Ejecuta una solicitud SOAP usando cURL
     *
     * @param string $url URL del servicio SOAP
     * @param string $xmlRequest Solicitud XML
     * @return array Respuesta con posible error
     */
    protected function executeSoapRequest(string $url, string $xmlRequest): array
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $xmlRequest,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml;charset=UTF-8',
                'SOAPAction: ""'
            ],
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        // Verificar errores de HTTP
        if ($httpCode !== 200) {
            Log::warning('Error HTTP al consultar comprobante', [
                'httpCode' => $httpCode,
                'response' => $response
            ]);

            $error = "HTTP Error: $httpCode";
        }

        return [
            'response' => $response,
            'error' => $error,
            'httpCode' => $httpCode
        ];
    }

    /**
     * Procesa la respuesta XML de autorización
     *
     * @param string $xmlResponse Respuesta XML del servicio SOAP
     * @param string $claveAcceso Clave de acceso consultada
     * @return array Información procesada del comprobante
     */
    protected function procesarRespuestaAutorizacion(string $xmlResponse, string $claveAcceso): array
    {
        try {
            $xml = new \SimpleXMLElement($xmlResponse);

            // Registrar namespaces para XPath
            $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
            $xml->registerXPathNamespace('ns2', 'http://ec.gob.sri.ws.autorizacion');

            // Extraer el nodo de respuesta
            $respuesta = $xml->xpath('//soap:Body/ns2:autorizacionComprobanteResponse/RespuestaAutorizacionComprobante');

            if (empty($respuesta)) {
                return [
                    'success' => false,
                    'message' => 'No se encontró información de autorización en la respuesta'
                ];
            }

            $respuesta = $respuesta[0];

            // Verificar si hay autorizaciones
            if (!isset($respuesta->autorizaciones->autorizacion)) {
                return [
                    'success' => false,
                    'message' => 'No se encontraron autorizaciones para el comprobante'
                ];
            }

            $autorizacion = $respuesta->autorizaciones->autorizacion;

            // Verificar estado de autorización
            $estado = (string)$autorizacion->estado;
            if ($estado !== 'AUTORIZADO') {
                $mensaje = isset($autorizacion->mensajes->mensaje->mensaje)
                    ? (string)$autorizacion->mensajes->mensaje->mensaje
                    : "El comprobante no está autorizado: {$estado}";

                return [
                    'success' => false,
                    'estado' => $estado,
                    'message' => $mensaje
                ];
            }

            // Datos básicos de la autorización
            $resultadoAutorizacion = [
                'estado' => $estado,
                'numeroAutorizacion' => (string)$autorizacion->numeroAutorizacion,
                'fechaAutorizacion' => (string)$autorizacion->fechaAutorizacion,
                'ambiente' => (string)$autorizacion->ambiente
            ];

            // Extraer y procesar el comprobante
            $comprobante = null;
            $datosComprobante = [];

            if (isset($autorizacion->comprobante)) {
                $xmlComprobante = $this->extraerXmlComprobante((string)$autorizacion->comprobante);
                if ($xmlComprobante) {
                    $datosComprobante = $this->procesarXmlComprobante($xmlComprobante);
                }
            }

            // Construir la respuesta completa
            return [
                'success' => true,
                'claveAcceso' => $claveAcceso,
                'autorizacion' => $resultadoAutorizacion,
                'comprobante' => $datosComprobante
            ];
        } catch (Exception $e) {
            Log::error('Error al procesar respuesta XML de autorización', [
                'claveAcceso' => $claveAcceso,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al procesar la respuesta XML: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extrae el XML del comprobante desde el CDATA en la respuesta
     *
     * @param string $comprobanteCdata Contenido CDATA del comprobante
     * @return \SimpleXMLElement|null Objeto XML del comprobante o null si hay error
     */
    protected function extraerXmlComprobante(string $comprobanteCdata): ?\SimpleXMLElement
    {
        try {
            return new \SimpleXMLElement($comprobanteCdata);
        } catch (Exception $e) {
            Log::warning('Error al extraer XML del comprobante', [
                'error' => $e->getMessage(),
                'comprobanteCdata' => substr($comprobanteCdata, 0, 200) . '...' // Solo para debug
            ]);

            return null;
        }
    }

    /**
     * Procesa el XML del comprobante para extraer información
     *
     * @param \SimpleXMLElement $xmlComprobante XML del comprobante
     * @return array Datos procesados del comprobante
     */
    protected function procesarXmlComprobante(\SimpleXMLElement $xmlComprobante): array
    {
        $resultado = [
            'tipoDocumento' => $xmlComprobante->getName()
        ];

        // Extraer info tributaria
        if (isset($xmlComprobante->infoTributaria)) {
            $resultado['infoTributaria'] = $this->elementToArray($xmlComprobante->infoTributaria);
        }

        // Extraer información según tipo de documento
        switch ($resultado['tipoDocumento']) {
            case 'factura':
                if (isset($xmlComprobante->infoFactura)) {
                    $resultado['infoFactura'] = $this->elementToArray($xmlComprobante->infoFactura);

                    // Procesar impuestos
                    if (isset($xmlComprobante->infoFactura->totalConImpuestos->totalImpuesto)) {
                        $resultado['impuestos'] = $this->procesarImpuestos(
                            $xmlComprobante->infoFactura->totalConImpuestos->totalImpuesto
                        );
                    }
                }

                // Procesar detalles
                if (isset($xmlComprobante->detalles->detalle)) {
                    $resultado['detalles'] = $this->procesarDetalles($xmlComprobante->detalles->detalle);
                }
                break;

            case 'notaCredito':
                if (isset($xmlComprobante->infoNotaCredito)) {
                    $resultado['infoNotaCredito'] = $this->elementToArray($xmlComprobante->infoNotaCredito);

                    // Procesar impuestos
                    if (isset($xmlComprobante->infoNotaCredito->totalConImpuestos->totalImpuesto)) {
                        $resultado['impuestos'] = $this->procesarImpuestos(
                            $xmlComprobante->infoNotaCredito->totalConImpuestos->totalImpuesto
                        );
                    }
                }

                // Procesar detalles
                if (isset($xmlComprobante->detalles->detalle)) {
                    $resultado['detalles'] = $this->procesarDetalles($xmlComprobante->detalles->detalle);
                }
                break;

            case 'notaDebito':
                if (isset($xmlComprobante->infoNotaDebito)) {
                    $resultado['infoNotaDebito'] = $this->elementToArray($xmlComprobante->infoNotaDebito);

                    // Procesar impuestos
                    if (isset($xmlComprobante->infoNotaDebito->impuestos->impuesto)) {
                        $resultado['impuestos'] = $this->procesarImpuestos(
                            $xmlComprobante->infoNotaDebito->impuestos->impuesto
                        );
                    }
                }

                // Procesar motivos
                if (isset($xmlComprobante->motivos->motivo)) {
                    $resultado['motivos'] = $this->procesarDetallesGenericos(
                        $xmlComprobante->motivos->motivo
                    );
                }
                break;

            case 'liquidacionCompra':
                if (isset($xmlComprobante->infoLiquidacionCompra)) {
                    $resultado['infoLiquidacionCompra'] = $this->elementToArray($xmlComprobante->infoLiquidacionCompra);

                    // Procesar impuestos
                    if (isset($xmlComprobante->infoLiquidacionCompra->totalConImpuestos->totalImpuesto)) {
                        $resultado['impuestos'] = $this->procesarImpuestos(
                            $xmlComprobante->infoLiquidacionCompra->totalConImpuestos->totalImpuesto
                        );
                    }
                }

                // Procesar detalles
                if (isset($xmlComprobante->detalles->detalle)) {
                    $resultado['detalles'] = $this->procesarDetalles($xmlComprobante->detalles->detalle);
                }
                break;
        }

        // Extraer información adicional
        if (isset($xmlComprobante->infoAdicional)) {
            $resultado['infoAdicional'] = $this->procesarInfoAdicional($xmlComprobante->infoAdicional);
        }

        // Extraer totales de IVA
        $resultado = array_merge($resultado, $this->calcularTotalesIVA($resultado));

        return $resultado;
    }

    /**
     * Convierte un elemento XML a un array
     *
     * @param \SimpleXMLElement $element Elemento XML
     * @return array Array asociativo con los datos
     */
    protected function elementToArray(\SimpleXMLElement $element): array
    {
        $result = [];

        foreach ($element as $key => $value) {
            $value = (string)$value;
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Procesa los impuestos del comprobante
     *
     * @param \SimpleXMLElement $impuestos Elementos de impuestos
     * @return array Impuestos procesados
     */
    protected function procesarImpuestos(\SimpleXMLElement $impuestos): array
    {
        $resultado = [];

        foreach ($impuestos as $impuesto) {
            $resultado[] = [
                'codigo' => (string)$impuesto->codigo,
                'codigoPorcentaje' => (string)$impuesto->codigoPorcentaje,
                'baseImponible' => (float)$impuesto->baseImponible,
                'valor' => (float)$impuesto->valor
            ];
        }

        return $resultado;
    }

    /**
     * Procesa los detalles del comprobante
     *
     * @param \SimpleXMLElement $detalles Elementos de detalle
     * @return array Detalles procesados
     */
    protected function procesarDetalles(\SimpleXMLElement $detalles): array
    {
        $resultado = [];

        foreach ($detalles as $detalle) {
            $item = [
                'descripcion' => (string)$detalle->descripcion,
                'cantidad' => (float)$detalle->cantidad,
                'precioUnitario' => (float)$detalle->precioUnitario,
                'descuento' => isset($detalle->descuento) ? (float)$detalle->descuento : 0,
                'precioTotal' => (float)$detalle->precioTotalSinImpuesto
            ];

            // Procesar impuestos del detalle
            if (isset($detalle->impuestos->impuesto)) {
                foreach ($detalle->impuestos->impuesto as $impuesto) {
                    if ((string)$impuesto->codigo === '2') { // 2 = IVA
                        $item['codigoIVA'] = (string)$impuesto->codigoPorcentaje;
                        $item['valorIVA'] = (float)$impuesto->valor;
                    }
                }
            }

            $resultado[] = $item;
        }

        return $resultado;
    }

    /**
     * Procesa elementos genéricos como motivos o detalles
     *
     * @param \SimpleXMLElement $elementos Elementos a procesar
     * @return array Elementos procesados
     */
    protected function procesarDetallesGenericos(\SimpleXMLElement $elementos): array
    {
        $resultado = [];

        foreach ($elementos as $elemento) {
            $item = $this->elementToArray($elemento);
            $resultado[] = $item;
        }

        return $resultado;
    }

    /**
     * Procesa la información adicional del comprobante
     *
     * @param \SimpleXMLElement $infoAdicional Elemento de información adicional
     * @return array Información adicional procesada
     */
    protected function procesarInfoAdicional(\SimpleXMLElement $infoAdicional): array
    {
        $resultado = [];

        if (isset($infoAdicional->campoAdicional)) {
            foreach ($infoAdicional->campoAdicional as $campo) {
                $nombre = (string)$campo['nombre'];
                $valor = (string)$campo;

                $resultado[$nombre] = $valor;
            }
        }

        return $resultado;
    }

    /**
     * Calcula los totales de IVA según los impuestos
     *
     * @param array $datosComprobante Datos del comprobante
     * @return array Totales de IVA
     */
    protected function calcularTotalesIVA(array $datosComprobante): array
    {
        $resultado = [
            'subtotal0' => 0,
            'subtotal12' => 0,
            'iva' => 0
        ];

        // Si no hay impuestos, retornar valores por defecto
        if (!isset($datosComprobante['impuestos'])) {
            return $resultado;
        }

        foreach ($datosComprobante['impuestos'] as $impuesto) {
            // 2 = IVA
            if ($impuesto['codigo'] === '2') {
                // 0 = 0%, 2 = 12%, 3 = 14%
                if ($impuesto['codigoPorcentaje'] === '0') {
                    $resultado['subtotal0'] += $impuesto['baseImponible'];
                } elseif (in_array($impuesto['codigoPorcentaje'], ['2', '3'])) {
                    $resultado['subtotal12'] += $impuesto['baseImponible'];
                    $resultado['iva'] += $impuesto['valor'];
                }
            }
        }

        return $resultado;
    }
}
