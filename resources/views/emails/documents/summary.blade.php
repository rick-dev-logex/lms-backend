@component('mail::message')
# Resumen Diario de Documentos SRI

**Fecha:** {{ $stats['date'] }}

## Estadísticas

@component('mail::table')
| Métrica | Valor |
|:--------|:------|
| Total de documentos | {{ $stats['count'] }} |
| Monto total | ${{ number_format($stats['totalAmount'], 2) }} |
| Proveedores | {{ count($stats['providers']) }} |
@endcomponent

## Principales proveedores

@component('mail::table')
| Proveedor | Documentos | Monto Total |
|:----------|:-----------|:------------|
@foreach($stats['providers'] as $provider => $data)
| {{ $provider }} | {{ $data['count'] }} | ${{ number_format($data['total'], 2) }} |
@endforeach
@endcomponent

## Últimos documentos

@component('mail::table')
| Emisor | Serie | Tipo | Monto |
|:-------|:------|:-----|:------|
@foreach($documents->take(10) as $doc)
| {{ $doc->razon_social_emisor }} | {{ $doc->serie_comprobante }} | {{ $doc->tipo_comprobante }} | ${{ number_format($doc->importe_total, 2) }} |
@endforeach
@endcomponent

@if($documents->count() > 10)
*Se muestran solo los 10 primeros documentos de un total de {{ $documents->count() }}.*
@endif

Puede acceder al sistema para ver detalles completos.

@component('mail::button', ['url' => config('app.url')])
Ver en el sistema
@endcomponent

Saludos,<br>
{{ config('app.name') }}
@endcomponent