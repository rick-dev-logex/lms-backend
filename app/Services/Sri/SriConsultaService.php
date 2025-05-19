<?php

namespace App\Services\Sri;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class SriConsultaService
{
    // URLs de los servicios del SRI
    protected $urlConsultaRuc = 'https://srienlinea.sri.gob.ec/sri-catastro-sujeto-servicio-internet/rest/ConsolidadoContribuyente/obtenerPorNumerosRuc';
    protected $urlValidarComprobante = 'https://srienlinea.sri.gob.ec/comprobantes-electronicos-internet/rest/validezComprobantes/validarComprobante';
    protected $urlConsultaComprobante = 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline';

    // Tiempo de caché (en minutos)
    protected $tiempoCacheRuc = 1440; // 24 horas
    protected $tiempoCacheComprobante = 10080; // 7 días

    /**
     * Consulta información de un contribuyente por su RUC
     *
     * @param string $ruc RUC del contribuyente
     * @return array Información del contribuyente
     */
    public function consultarContribuyente(string $ruc): array
    {
        try {
            // Validar formato de RUC
            if (!$this->validarFormatoRuc($ruc)) {
                return [
                    'success' => false,
                    'message' => 'El formato del RUC no es válido'
                ];
            }

            // Verificar si existe en caché
            $cacheKey = "contribuyente_ruc_{$ruc}";
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            // Realizar la consulta al SRI
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->get($this->urlConsultaRuc, [
                    'ruc' => $ruc
                ]);

            if ($response->failed()) {
                Log::warning('Error al consultar RUC en el SRI', [
                    'ruc' => $ruc,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'message' => 'Error al consultar el RUC en el SRI: ' . $response->status()
                ];
            }

            $data = $response->json();

            // Verificar si la respuesta contiene datos válidos
            if (empty($data)) {
                return [
                    'success' => false,
                    'message' => 'No se encontraron datos para el RUC proporcionado'
                ];
            }

            // Formatear la respuesta
            $resultado = [
                'success' => true,
                'ruc' => $ruc,
                'razonSocial' => $data['nombreCompleto'] ?? '',
                'nombreComercial' => $data['nombreFantasia'] ?? '',
                'estado' => $data['estadoContribuyente'] ?? '',
                'claseSujeto' => $data['claseContribuyente'] ?? '',
                'tipoContribuyente' => $data['personaSociedad'] ?? '',
                'obligadoContabilidad' => $data['obligadoLlevarContabilidad'] ?? 'NO',
                'actividadEconomica' => $data['actividadEconomicaPrincipal'] ?? '',
                'fechaInscripcion' => $data['fechaInscripcionRuc'] ?? '',
                'fechaInicioActividades' => $data['fechaInicioActividades'] ?? '',
                'fechaActualizacion' => $data['fechaActualizacion'] ?? '',
                'direccionMatriz' => $data['direccionMatriz'] ?? '',
                'telefonos' => $data['telefonoMatriz'] ?? '',
                'email' => $data['emailMatriz'] ?? '',
                'datosCompletos' => $data // Guardar todos los datos para referencia
            ];

            // Guardar en caché
            Cache::put($cacheKey, $resultado, now()->addMinutes($this->tiempoCacheRuc));

            return $resultado;
        } catch (Exception $e) {
            Log::error('Error al consultar contribuyente', [
                'ruc' => $ruc,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al consultar información del contribuyente: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Consulta información de un comprobante electrónico por su clave de acceso
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
            $response = Http::timeout(20)
                ->withHeaders([
                    'Content-Type' => 'text/xml;charset=UTF-8',
                    'SOAPAction' => ''
                ])
                ->withBody($soapEnvelope, 'text/xml')
                ->post($this->urlConsultaComprobante);

            if ($response->failed()) {
                Log::warning('Error al consultar comprobante en el SRI', [
                    'claveAcceso' => $claveAcceso,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500) // Limitar la longitud del log
                ]);

                return [
                    'success' => false,
                    'message' => 'Error al consultar el comprobante en el SRI: ' . $response->status()
                ];
            }

            // Procesar la respuesta XML
            $resultado = $this->procesarRespuestaComprobante($response->body(), $claveAcceso);

            // Si es exitoso, guardar en caché
            if ($resultado['success']) {
                Cache::put($cacheKey, $resultado, now()->addMinutes($this->tiempoCacheComprobante));
            }

            return $resultado;
        } catch (Exception $e) {
            Log::error('Error al consultar comprobante', [
                'claveAcceso' => $claveAcceso,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al consultar información del comprobante: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Valida un comprobante electrónico
     *
     * @param string $claveAcceso Clave de acceso del comprobante
     * @return array Resultado de la validación
     */
    public function validarComprobante(string $claveAcceso): array
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
            $cacheKey = "validacion_clave_{$claveAcceso}";
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            // Realizar la consulta al SRI
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($this->urlValidarComprobante, [
                    'claveAccesoComprobante' => $claveAcceso
                ]);

            if ($response->failed()) {
                Log::warning('Error al validar comprobante en el SRI', [
                    'claveAcceso' => $claveAcceso,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'message' => 'Error al validar el comprobante en el SRI: ' . $response->status()
                ];
            }

            $data = $response->json();

            // Formatear la respuesta
            $resultado = [
                'success' => true,
                'claveAcceso' => $claveAcceso,
                'valid' => $data['valid'] ?? false,
                'estado' => $data['estado'] ?? '',
                'message' => $data['mensaje'] ?? '',
                'datos' => $data
            ];

            // Guardar en caché por menos tiempo (comprobantes pueden cambiar de estado)
            Cache::put($cacheKey, $resultado, now()->addMinutes(60)); // 1 hora

            return $resultado;
        } catch (Exception $e) {
            Log::error('Error al validar comprobante', [
                'claveAcceso' => $claveAcceso,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al validar comprobante: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Procesa la respuesta XML de consulta de comprobante
     *
     * @param string $xml Respuesta XML del servicio SOAP
     * @param string $claveAcceso Clave de acceso consultada
     * @return array Datos procesados del comprobante
     */
    protected function procesarRespuestaComprobante(string $xmlString, string $claveAcceso): array
    {
        try {
            // Log completo de la respuesta para diagnóstico
            Log::debug('Respuesta completa del SRI', [
                'claveAcceso' => $claveAcceso,
                'respuestaXML' => $xmlString
            ]);

            // Limpiar caracteres problemáticos que puedan afectar al XML
            $xmlString = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $xmlString);

            // Verificar si la respuesta está truncada
            if (strpos($xmlString, '</soap:Envelope>') === false) {
                Log::warning('Respuesta XML truncada', ['claveAcceso' => $claveAcceso]);
                return [
                    'success' => false,
                    'message' => 'Respuesta incompleta del servidor SRI'
                ];
            }

            // Procesar el XML
            $xml = new \SimpleXMLElement($xmlString);

            // Registrar namespaces
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
                    'message' => 'El comprobante no tiene autorizaciones'
                ];
            }

            $autorizacion = $respuesta->autorizaciones->autorizacion;

            // Verificar estado de autorización
            $estado = (string)$autorizacion->estado;
            if ($estado !== 'AUTORIZADO') {
                return [
                    'success' => false,
                    'estado' => $estado,
                    'message' => isset($autorizacion->mensajes->mensaje)
                        ? (string)$autorizacion->mensajes->mensaje->mensaje
                        : "El comprobante no está autorizado: {$estado}"
                ];
            }

            // Procesar el comprobante XML (viene como CDATA dentro de la respuesta)
            $comprobanteXml = null;
            $comprobanteData = [];

            if (isset($autorizacion->comprobante)) {
                try {
                    $comprobanteStr = (string)$autorizacion->comprobante;
                    $comprobanteXml = new \SimpleXMLElement($comprobanteStr);

                    // Extraer datos básicos del comprobante según su tipo
                    $comprobanteData = $this->extraerDatosComprobante($comprobanteXml);
                } catch (Exception $e) {
                    Log::warning('Error al procesar comprobante XML', [
                        'claveAcceso' => $claveAcceso,
                        'error' => $e->getMessage()
                    ]);

                    // Intentar extraer información básica del comprobante
                    $comprobanteData = [
                        'raw' => substr((string)$autorizacion->comprobante, 0, 1000) . '...'
                    ];
                }
            }

            // Construir respuesta completa
            $resultado = [
                'success' => true,
                'claveAcceso' => $claveAcceso,
                'estado' => $estado,
                'numeroAutorizacion' => (string)$autorizacion->numeroAutorizacion,
                'fechaAutorizacion' => (string)$autorizacion->fechaAutorizacion,
                'ambiente' => (string)$autorizacion->ambiente,
                'comprobante' => $comprobanteData,
                'xml' => [
                    'autorizacion' => $this->arrayFromXmlElement($autorizacion)
                ]
            ];

            return $resultado;
        } catch (Exception $e) {
            Log::error('Error al procesar respuesta XML de comprobante', [
                'claveAcceso' => $claveAcceso,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'xmlSample' => substr($xmlString, 0, 500) // Mostrar parte del XML para depuración
            ]);

            return [
                'success' => false,
                'message' => 'Error al procesar respuesta XML: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extrae datos estructurados de un comprobante XML
     *
     * @param \SimpleXMLElement $xml XML del comprobante
     * @return array Datos del comprobante
     */
    protected function extraerDatosComprobante(\SimpleXMLElement $xml): array
    {
        $tipoDocumento = $xml->getName();
        $resultado = [
            'tipoDocumento' => $tipoDocumento
        ];

        // Datos generales (info tributaria)
        if (isset($xml->infoTributaria)) {
            $resultado['infoTributaria'] = [
                'razonSocial' => (string)$xml->infoTributaria->razonSocial,
                'nombreComercial' => (string)$xml->infoTributaria->nombreComercial,
                'ruc' => (string)$xml->infoTributaria->ruc,
                'claveAcceso' => (string)$xml->infoTributaria->claveAcceso,
                'codDoc' => (string)$xml->infoTributaria->codDoc,
                'estab' => (string)$xml->infoTributaria->estab,
                'ptoEmi' => (string)$xml->infoTributaria->ptoEmi,
                'secuencial' => (string)$xml->infoTributaria->secuencial,
                'dirMatriz' => (string)$xml->infoTributaria->dirMatriz
            ];
        }

        // Datos específicos según tipo de documento
        switch ($tipoDocumento) {
            case 'factura':
                if (isset($xml->infoFactura)) {
                    $resultado['infoFactura'] = [
                        'fechaEmision' => (string)$xml->infoFactura->fechaEmision,
                        'razonSocialComprador' => (string)$xml->infoFactura->razonSocialComprador,
                        'identificacionComprador' => (string)$xml->infoFactura->identificacionComprador,
                        'totalSinImpuestos' => (float)$xml->infoFactura->totalSinImpuestos,
                        'totalDescuento' => (float)($xml->infoFactura->totalDescuento ?? 0),
                        'importeTotal' => (float)$xml->infoFactura->importeTotal
                    ];

                    // Extraer impuestos
                    if (isset($xml->infoFactura->totalConImpuestos->totalImpuesto)) {
                        $impuestos = [];
                        foreach ($xml->infoFactura->totalConImpuestos->totalImpuesto as $imp) {
                            $impuestos[] = [
                                'codigo' => (string)$imp->codigo,
                                'codigoPorcentaje' => (string)$imp->codigoPorcentaje,
                                'baseImponible' => (float)$imp->baseImponible,
                                'valor' => (float)$imp->valor
                            ];
                        }
                        $resultado['impuestos'] = $impuestos;
                    }

                    // Extraer datos básicos de detalles
                    if (isset($xml->detalles->detalle)) {
                        $detalles = [];
                        foreach ($xml->detalles->detalle as $det) {
                            $detalles[] = [
                                'descripcion' => (string)$det->descripcion,
                                'cantidad' => (float)$det->cantidad,
                                'precioUnitario' => (float)$det->precioUnitario,
                                'descuento' => (float)($det->descuento ?? 0),
                                'precioTotalSinImpuesto' => (float)$det->precioTotalSinImpuesto
                            ];
                        }
                        $resultado['detalles'] = $detalles;
                    }
                }
                break;

            case 'comprobanteRetencion':
                if (isset($xml->infoCompRetencion)) {
                    $resultado['infoCompRetencion'] = [
                        'fechaEmision' => (string)$xml->infoCompRetencion->fechaEmision,
                        'razonSocialSujetoRetenido' => (string)$xml->infoCompRetencion->razonSocialSujetoRetenido,
                        'identificacionSujetoRetenido' => (string)$xml->infoCompRetencion->identificacionSujetoRetenido,
                        'periodoFiscal' => (string)$xml->infoCompRetencion->periodoFiscal
                    ];

                    // Extraer impuestos retenidos
                    if (isset($xml->impuestos->impuesto)) {
                        $impuestos = [];
                        foreach ($xml->impuestos->impuesto as $imp) {
                            $impuestos[] = [
                                'codigo' => (string)$imp->codigo,
                                'codigoRetencion' => (string)$imp->codigoRetencion,
                                'baseImponible' => (float)$imp->baseImponible,
                                'porcentajeRetener' => (float)$imp->porcentajeRetener,
                                'valorRetenido' => (float)$imp->valorRetenido
                            ];
                        }
                        $resultado['impuestos'] = $impuestos;
                    }
                }
                break;

            // Otros tipos de documentos podrían manejarse aquí
            default:
                // Intentar extraer información básica común
                $resultado['datosGenerales'] = $this->arrayFromXmlElement($xml);
                break;
        }

        return $resultado;
    }

    /**
     * Convierte un SimpleXMLElement en un array
     *
     * @param \SimpleXMLElement $xml
     * @return array
     */
    protected function arrayFromXmlElement(\SimpleXMLElement $xml): array
    {
        $json = json_encode($xml);
        return json_decode($json, true);
    }

    /**
     * Valida el formato de un RUC ecuatoriano
     *
     * @param string $ruc Número de RUC
     * @return bool True si el formato es válido
     */
    public function validarFormatoRuc(string $ruc): bool
    {
        // Verificar longitud (13 dígitos para RUC)
        if (strlen($ruc) !== 13) {
            return false;
        }

        // Verificar que solo contenga dígitos
        if (!ctype_digit($ruc)) {
            return false;
        }

        // Validar dígito verificador según el tipo de RUC
        $tipoRuc = substr($ruc, 2, 1);

        // RUC persona natural
        if (in_array($tipoRuc, ['0', '1', '2', '3', '4', '5'])) {
            return $this->validarRucPersonaNatural($ruc);
        }

        // RUC sociedad
        if ($tipoRuc === '9') {
            return $this->validarRucSociedad($ruc);
        }

        // RUC entidad pública
        if ($tipoRuc === '6') {
            return $this->validarRucEntidadPublica($ruc);
        }

        return false;
    }

    /**
     * Valida el RUC de una persona natural
     *
     * @param string $ruc RUC a validar
     * @return bool True si es válido
     */
    protected function validarRucPersonaNatural(string $ruc): bool
    {
        $coeficientes = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $cedula = substr($ruc, 0, 9);
        $digitoVerificador = (int) substr($ruc, 9, 1);

        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $valor = (int) $cedula[$i] * $coeficientes[$i];
            $suma += ($valor >= 10) ? $valor - 9 : $valor;
        }

        $resultado = 10 - ($suma % 10);
        if ($resultado === 10) {
            $resultado = 0;
        }

        // Verificar el dígito verificador y que los 3 últimos dígitos sean 001
        return ($resultado === $digitoVerificador) && (substr($ruc, 10, 3) === '001');
    }

    /**
     * Valida el RUC de una sociedad
     *
     * @param string $ruc RUC a validar
     * @return bool True si es válido
     */
    protected function validarRucSociedad(string $ruc): bool
    {
        $coeficientes = [4, 3, 2, 7, 6, 5, 4, 3, 2];
        $rucSinDigito = substr($ruc, 0, 9);
        $digitoVerificador = (int) substr($ruc, 9, 1);

        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $suma += (int) $rucSinDigito[$i] * $coeficientes[$i];
        }

        $resultado = 11 - ($suma % 11);
        if ($resultado === 11) {
            $resultado = 0;
        }

        // Verificar el dígito verificador y que los 3 últimos dígitos sean 001
        return ($resultado === $digitoVerificador) && (substr($ruc, 10, 3) === '001');
    }

    /**
     * Valida el RUC de una entidad pública
     *
     * @param string $ruc RUC a validar
     * @return bool True si es válido
     */
    protected function validarRucEntidadPublica(string $ruc): bool
    {
        $coeficientes = [3, 2, 7, 6, 5, 4, 3, 2];
        $rucSinDigito = substr($ruc, 0, 8);
        $digitoVerificador = (int) substr($ruc, 8, 1);

        $suma = 0;
        for ($i = 0; $i < 8; $i++) {
            $suma += (int) $rucSinDigito[$i] * $coeficientes[$i];
        }

        $resultado = 11 - ($suma % 11);
        if ($resultado === 11) {
            $resultado = 0;
        }

        // Verificar el dígito verificador y que los últimos 4 dígitos sean 0001
        return ($resultado === $digitoVerificador) && (substr($ruc, 9, 4) === '0001');
    }

    /**
     * Obtiene información del XML de factura electrónica a partir de su clave de acceso
     *
     * @param string $claveAcceso Clave de acceso de la factura
     * @return array Información extraída y validada
     */
    public function obtenerDatosDesdeClaveAcceso(string $claveAcceso): array
    {
        try {
            // Validar longitud de la clave de acceso
            if (strlen($claveAcceso) !== 49) {
                return [
                    'success' => false,
                    'message' => 'La clave de acceso debe tener 49 dígitos'
                ];
            }

            // Extraer información codificada en la clave de acceso
            $fechaEmision = substr($claveAcceso, 0, 8); // DDMMAAAA
            $tipoComprobante = substr($claveAcceso, 8, 2);
            $rucEmisor = substr($claveAcceso, 10, 13);
            $ambiente = substr($claveAcceso, 23, 1); // 1=Pruebas, 2=Producción
            $serie = substr($claveAcceso, 24, 6); // 3 dígitos establecimiento + 3 dígitos punto emisión
            $secuencial = substr($claveAcceso, 30, 9);
            $codigoNumerico = substr($claveAcceso, 39, 8);
            $tipoEmision = substr($claveAcceso, 47, 1); // 1=Normal, 2=Indisponibilidad SRI
            $digitoVerificador = substr($claveAcceso, 48, 1);

            // Formatear fecha de emisión
            $fechaFormateada = '';
            if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $fechaEmision, $matches)) {
                $fechaFormateada = "{$matches[3]}-{$matches[2]}-{$matches[1]}"; // YYYY-MM-DD
            }

            // Datos extraídos directamente de la clave
            $datosBasicos = [
                'claveAcceso' => $claveAcceso,
                'fechaEmision' => $fechaFormateada,
                'tipoComprobante' => $tipoComprobante,
                'rucEmisor' => $rucEmisor,
                'ambiente' => $ambiente === '2' ? 'PRODUCCIÓN' : 'PRUEBAS',
                'serie' => $serie,
                'secuencial' => $secuencial,
                'establecimiento' => substr($serie, 0, 3),
                'puntoEmision' => substr($serie, 3, 3),
                'tipoEmision' => $tipoEmision === '1' ? 'NORMAL' : 'CONTINGENCIA',
                'digitoVerificador' => $digitoVerificador
            ];

            // Obtener información del emisor
            $infoEmisor = $this->consultarContribuyente($rucEmisor);

            // Consultar datos completos del comprobante en SRI
            $infoComprobante = $this->consultarComprobante($claveAcceso);

            // Juntar toda la información
            return [
                'success' => true,
                'datosBasicos' => $datosBasicos,
                'emisor' => $infoEmisor['success'] ? $infoEmisor : null,
                'comprobante' => $infoComprobante['success'] ? $infoComprobante : null
            ];
        } catch (Exception $e) {
            Log::error('Error al obtener datos desde clave de acceso', [
                'claveAcceso' => $claveAcceso,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al procesar la clave de acceso: ' . $e->getMessage()
            ];
        }
    }
}
