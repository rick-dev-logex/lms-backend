<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: sans-serif;
            font-size: 12px;
            color: #333;
            margin: 0;
            padding: 0;
        }

        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }

        .document-header h1 {
            margin: 0;
            font-size: 18px;
        }

        .document-header h2 {
            margin: 4px 0 10px;
            font-size: 16px;
            border-radius: 5px;
        }

        .logo {
            position: absolute;
            top: 10px;
            right: 10px;
            max-width: 150px;
            max-height: 60px;
        }

        .estado {
            position: absolute;
            top: 10px;
            left: 10px;
            padding: 4px 8px;
            background: #4CAF50;
            color: #fff;
            font-weight: bold;
            border-radius: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th,
        td {
            border: 1px solid #666;
            padding: 4px;
            font-size: 10px;
        }

        th {
            background: #eee;
        }

        .totals table {
            float: right;
            width: 40%;
        }

        .info-box {
            border: 1px solid #666;
            border-radius: 5px;
            padding: 8px;
            margin-bottom: 20px;
        }
        .left_info-box {
            border: 1px solid #666;
            padding: 8px;
            margin-top: -118px;
            margin-bottom: 20px;
            border-radius: 5px;
            max-width: 55%;
        }

        @page {
            margin: 0.5cm;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="document-header text-center clearfix">
            @if ($logoData)
                <img src="{{ $logoData }}" class="logo" alt="{{ $empresaKey }} logo">
            @endif
            <h1>R.U.C.: {{ $invoice->ruc_emisor }}</h1>
            <h2>FACTURA</h2>
            @if (in_array($invoice->estado, ['contabilizado']) || $invoice->estado_latinium === 'contabilizado')
                <div class="estado">CONTABILIZADA</div>
            @endif
        </div>

        {{-- Autorización / Fechas --}}
        <div class="info-box">
            <p><strong>NÚMERO DE AUTORIZACIÓN:</strong> {{ $invoice->clave_acceso }}</p>
            <p><strong>FECHA Y HORA AUTORIZACIÓN:</strong> {{ $invoice->fecha_autorizacion->format('Y-m-d H:i:s') }}</p>
            <p><strong>CLAVE DE ACCESO:</strong> {{ $invoice->clave_acceso }}</p>
        </div>

        {{-- Empresa emisora --}}
        <div class="info-box">
            <p><strong>Razón Social:</strong> {{ $invoice->razon_social_emisor }}</p>
            <p><strong>RUC:</strong> {{ $invoice->ruc_emisor }}</p>
            <p><strong>Dirección Matriz:</strong> {{ $invoice->dir_matriz }}</p>
            <p><strong>Sucursal:</strong> {{ $invoice->dir_establecimiento }}</p>
            <p><strong>Obligado a Contabilidad:</strong> {{ $invoice->obligado_contabilidad }}</p>
        </div>

        {{-- Datos del comprobante --}}
        <table>
            <tr>
                <th>No.</th>
                <td>{{ $invoice->estab }}-{{ $invoice->pto_emi }}-{{ $invoice->secuencial }}</td>
            </tr>
            <tr>
                <th>Ambiente</th>
                <td>{{ $invoice->ambiente }}</td>
            </tr>
            <tr>
                <th>Emisión</th>
                <td>{{ $invoice->tipo_emision }}</td>
            </tr>
            <tr>
                <th>Fecha Emisión</th>
                <td>{{ $invoice->fecha_emision->format('d/m/Y') }}</td>
            </tr>
        </table>

        {{-- Detalles --}}
        <h4>DETALLE</h4>
        <table>
            <thead>
                <tr>
                    <th>Cod.Princ.</th>
                    <th>Cant.</th>
                    <th>Descripción</th>
                    <th>P.Unit.</th>
                    <th>Desc.</th>
                    <th>Valor Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($detalles as $d)
                    <tr>
                        <td>{{ $d->codigo_principal }}</td>
                        <td class="text-center">{{ number_format($d->cantidad, 2) }}</td>
                        <td>{{ $d->descripcion }}</td>
                        <td class="text-right">{{ number_format($d->precio_unitario, 2) }}</td>
                        <td class="text-right">{{ number_format($d->descuento ?? 0, 2) }}</td>
                        <td class="text-right">{{ number_format($d->precio_total_sin_impuesto, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center">No hay detalles registrados</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Totales --}}
        <div class="totals clearfix">
            <table>
                <tr>
                    <td>SUBTOTAL 12%</td>
                    <td class="text-right">${{ number_format($subtotal12, 2) }}</td>
                </tr>
                <tr>
                    <td>SUBTOTAL 0%</td>
                    <td class="text-right">${{ number_format($subtotal0, 2) }}</td>
                </tr>
                <tr>
                    <td>IVA 12%</td>
                    <td class="text-right">${{ number_format($iva, 2) }}</td>
                </tr>
                <tr>
                    <td><strong>VALOR TOTAL</strong></td>
                    <td class="text-right"><strong>${{ number_format($total, 2) }}</strong></td>
                </tr>
            </table>
        </div>

        {{-- Información adicional / notas --}}
        @if (count($infoAdicional))
            <div class="left_info-box">
                <h2>Información Adicional</h2>
                @foreach ($infoAdicional as $key => $val)
                    <p><strong>{{ $val }}:</strong> {{ $key }}</p>
                @endforeach
            </div>
        @endif

        {{-- Footer --}}
        <div class="text-center" style="margin-top:20px; font-size:9px; color:#666;">
            <p>DOCUMENTO GENERADO AUTOMÁTICAMENTE POR EL SISTEMA LMS</p>
            <p>Fecha de Generación: {{ now()->format('d/m/Y H:i:s') }}</p>
        </div>
    </div>
</body>

</html>
