<?php

namespace App\Services\Sri;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use SimpleXMLElement;
use Exception;

class SriRucService
{
    // Constantes de SRI
    protected $urlConsultaRuc  = 'https://srienlinea.sri.gob.ec/sri-catastro-sujeto-servicio-internet/rest/ConsolidadoContribuyente/obtenerPorNumerosRuc';

    // Tiempo de expiración de cache en minutos
    protected $cacheMinutes = 1440; // 24 horas

    /**
     * Consulta la información de un contribuyente por su RUC
     *
     * @param string $ruc Número de RUC a consultar
     * @return array Información del contribuyente
     */
    /**
     * Consulta la información de un contribuyente por su RUC
     *
     * @param string $ruc Número de RUC a consultar
     * @return array Información del contribuyente
     */
    public function getContribuyenteInfo(string $ruc): array
    {
        try {
            // Validar formato de RUC
            if (!$this->validarFormatoRuc($ruc)) {
                Log::warning('Formato de RUC inválido', ['ruc' => $ruc]);
                return [
                    'success' => false,
                    'message' => 'Formato de RUC inválido'
                ];
            }

            // Verificar si tenemos la info en cache
            $cacheKey = "contribuyente_ruc_{$ruc}";
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            // IMPORTANTE: Construir la URL correctamente con el parámetro ruc
            $url = "{$this->urlConsultaRuc}?ruc={$ruc}";

            // Para depuración
            Log::debug('Intentando consultar RUC con URL', ['url' => $url]);

            // Realizar consulta GET pero con la URL completa ya formada
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36'
                ])
                ->get($url); // Usar la URL ya formada con el parámetro

            // Para depuración
            Log::debug('Respuesta de SRI para RUC', [
                'ruc' => $ruc,
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if (!$response->successful()) {
                Log::warning('Error al consultar RUC en SRI', [
                    'ruc' => $ruc,
                    'url' => $url,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);

                return [
                    'success' => false,
                    'message' => 'Error al consultar RUC en SRI: ' . $response->status()
                ];
            }

            $data = $response->json();

            // Si la respuesta es un array, tomar el primer elemento
            if (is_array($data) && !empty($data) && !isset($data['nombreCompleto'])) {
                $data = $data[0];
            }

            // Verificar si la consulta fue exitosa
            if (empty($data)) {
                Log::warning('RUC no encontrado en SRI', ['ruc' => $ruc]);
                return [
                    'success' => false,
                    'message' => 'RUC no encontrado en SRI'
                ];
            }

            // Formatear resultado
            $result = [
                'success' => true,
                'ruc' => $ruc,
                'razonSocial' => $data['razonSocial'] ?? $data['nombreCompleto'] ?? '',
                'nombreComercial' => $data['nombreFantasia'] ?? $data['nombreComercial'] ?? $data['razonSocial'] ?? '',
                'estado' => $data['estadoContribuyenteRuc'] ?? $data['estadoContribuyente'] ?? '',
                'clase' => $data['regimen'] ?? $data['claseContribuyente'] ?? '',
                'tipoContribuyente' => $data['tipoContribuyente'] ?? $data['personaSociedad'] ?? '',
                'fechaInicio' => isset($data['informacionFechasContribuyente'])
                    ? ($data['informacionFechasContribuyente']['fechaInicioActividades'] ?? '')
                    : ($data['fechaInicioActividades'] ?? ''),
                'actividadEconomica' => $data['actividadEconomicaPrincipal'] ?? '',
                'direccion' => $data['direccionMatriz'] ?? '',
                'obligadoContabilidad' => $data['obligadoLlevarContabilidad'] ?? 'NO',
                'datosCompletos' => $data
            ];

            // Guardar en cache
            Cache::put($cacheKey, $result, now()->addMinutes($this->cacheMinutes));

            return $result;
        } catch (Exception $e) {
            Log::error('Error al consultar información de contribuyente', [
                'ruc' => $ruc,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Error al consultar información de contribuyente: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Valida el formato de un número de RUC ecuatoriano
     *
     * @param string $ruc Número de RUC a validar
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

        // Validar dígito verificador para RUC de persona natural
        if (in_array(substr($ruc, 2, 1), ['0', '1', '2', '3', '4', '5'])) {
            return $this->validarRucPersonaNatural($ruc);
        }

        // Validar RUC de sociedad
        if (substr($ruc, 2, 1) === '9') {
            return $this->validarRucSociedad($ruc);
        }

        // Validar RUC de entidad pública
        if (substr($ruc, 2, 1) === '6') {
            return $this->validarRucEntidadPublica($ruc);
        }

        return false;
    }

    /**
     * Valida el RUC de una persona natural
     *
     * @param string $ruc Número de RUC a validar
     * @return bool True si el RUC es válido
     */
    protected function validarRucPersonaNatural(string $ruc): bool
    {
        $coeficientes = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $cedula = substr($ruc, 0, 9);
        $verificador = (int)substr($ruc, 9, 1);

        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $valor = (int)$cedula[$i] * $coeficientes[$i];
            $suma += ($valor >= 10) ? $valor - 9 : $valor;
        }

        $digitoVerificador = ($suma % 10 === 0) ? 0 : 10 - ($suma % 10);

        // Verificar dígito verificador y últimos dígitos
        return ($digitoVerificador === $verificador) && (substr($ruc, 10, 3) === '001');
    }

    /**
     * Valida el RUC de una sociedad
     *
     * @param string $ruc Número de RUC a validar
     * @return bool True si el RUC es válido
     */
    protected function validarRucSociedad(string $ruc): bool
    {
        $coeficientes = [4, 3, 2, 7, 6, 5, 4, 3, 2];
        $establecimiento = substr($ruc, 0, 9);
        $verificador = (int)substr($ruc, 9, 1);

        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $suma += (int)$establecimiento[$i] * $coeficientes[$i];
        }

        $residuo = $suma % 11;
        $digitoVerificador = ($residuo === 0) ? 0 : 11 - $residuo;

        // Verificar dígito verificador y últimos dígitos
        return ($digitoVerificador === $verificador) && (substr($ruc, 10, 3) === '001');
    }

    /**
     * Valida el RUC de una entidad pública
     *
     * @param string $ruc Número de RUC a validar
     * @return bool True si el RUC es válido
     */
    protected function validarRucEntidadPublica(string $ruc): bool
    {
        $coeficientes = [3, 2, 7, 6, 5, 4, 3, 2];
        $establecimiento = substr($ruc, 0, 8);
        $verificador = (int)substr($ruc, 8, 1);

        $suma = 0;
        for ($i = 0; $i < 8; $i++) {
            $suma += (int)$establecimiento[$i] * $coeficientes[$i];
        }

        $residuo = $suma % 11;
        $digitoVerificador = ($residuo === 0) ? 0 : 11 - $residuo;

        // Verificar dígito verificador y últimos dígitos
        return ($digitoVerificador === $verificador) && (substr($ruc, 9, 4) === '0001');
    }

    /**
     * Valida una autorización de comprobante electrónico
     *
     * @param string $autorizacion Número de autorización a validar
     * @return bool True si la autorización tiene formato válido
     */
    public function validarAutorizacion(string $autorizacion): bool
    {
        // Autorización de comprobante electrónico debe tener 49 dígitos
        if (strlen($autorizacion) !== 49) {
            return false;
        }

        // Verificar que solo contenga dígitos
        if (!ctype_digit($autorizacion)) {
            return false;
        }

        // Extraer fecha
        $fecha = substr($autorizacion, 0, 8);
        if (!preg_match('/^\d{8}$/', $fecha)) {
            return false;
        }

        // Validar año
        $year = (int)substr($fecha, 0, 4);
        if ($year < 2000 || $year > date('Y') + 1) {
            return false;
        }

        // Validar mes
        $month = (int)substr($fecha, 4, 2);
        if ($month < 1 || $month > 12) {
            return false;
        }

        // Validar día
        $day = (int)substr($fecha, 6, 2);
        if ($day < 1 || $day > 31) {
            return false;
        }

        // Tipo de comprobante (2 dígitos posición 8-10)
        // Ruc (13 dígitos posición 10-23)
        // Serie (6 dígitos posición 24-30)
        // Secuencial (9 dígitos posición 30-39)
        // Código (8 dígitos posición 39-47)
        // Tipo emisión (1 dígito posición 47-48)
        // Dígito verificador (1 dígito posición 48-49)

        // Por ahora solo validamos formato general
        return true;
    }
}
