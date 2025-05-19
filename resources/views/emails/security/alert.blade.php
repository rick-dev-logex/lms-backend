@component('mail::message')
# Alerta de Seguridad

Se ha detectado una posible actividad sospechosa en el sistema:

**Tipo de alerta:** {{ ucfirst($alertType) }}

**Mensaje:** {{ $alertMessage }}

@component('mail::table')
| Información | Valor |
|-------------|-------|
| Fecha y hora | {{ now()->format('d/m/Y H:i:s') }} |
| Servidor | {{ gethostname() }} |
@endcomponent

Es recomendable revisar los registros de actividad del sistema para obtener más detalles.

@component('mail::button', ['url' => config('app.url')])
Ir al Sistema
@endcomponent

Saludos,<br>
{{ config('app.name') }}
@endcomponent