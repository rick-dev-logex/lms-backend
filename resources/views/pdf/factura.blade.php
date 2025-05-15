<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tipoComprobante }} {{ $secuencial }}</title>
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

        .header {
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 20px;
            position: relative;
        }

        .company-info {
            float: left;
            width: 60%;
        }

        .document-info {
            float: right;
            width: 35%;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table,
        th,
        td {
            border: 1px solid #ccc;
        }

        th,
        td {
            padding: 6px;
            text-align: left;
            font-size: 10px;
        }

        th {
            background-color: #f2f2f2;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .totals {
            float: right;
            width: 40%;
        }

        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }

        .barcode {
            text-align: center;
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 9px;
            word-break: break-all;
        }

        .info-box {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        .footer {
            margin-top: 30px;
            font-size: 9px;
            text-align: center;
            color: #666;
        }

        .document-header {
            text-align: center;
            margin-bottom: 5px;
        }

        .document-header h2 {
            margin: 0;
            color: #444;
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
            top: 5px;
            right: 5px;
            padding: 2px 8px;
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
            border-radius: 5px;
            font-size: 10px;
        }

        @page {
            margin: 0.5cm;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="document-header">
            <h2>{{ strtoupper($tipoComprobante) }}</h2>
        </div>

        <div class="header clearfix">
            <div class="company-info">
                <h3>{{ isset($nombreComercial) && $nombreComercial !== $razonSocial ? $nombreComercial : '' }}</h3>
                <h3>{{ $razonSocial }}</h3>
                <p>RUC: {{ $ruc }}</p>
                <p>Dirección Matriz: {{ $dirMatriz }}</p>
                <p>
                    <strong>Ambiente:</strong> {{ $ambiente == '1' ? 'PRUEBAS' : 'PRODUCCIÓN' }} |
                    <strong>Emisión:</strong> {{ $tipoEmision == '1' ? 'NORMAL' : 'CONTINGENCIA' }}
                </p>
            </div>
            <div class="document-info">
                <p><strong>No.</strong> {{ $estab }}-{{ $ptoEmi }}-{{ $secuencial }}</p>
                <p><strong>Fecha Emisión:</strong> {{ $fechaEmision }}</p>
                <p><strong>Fecha Autorización:</strong> {{ $fechaAutorizacion }}</p>
                <p><strong>Clave de Acceso:</strong></p>
                <p style="font-size: 8px; word-break: break-all;">{{ $claveAcceso }}</p>
            </div>

            @if(isset($estado) && $estado == 'AUTORIZADO')
            <div class="estado">AUTORIZADO</div>
            @endif
        </div>

        <div class="info-box">
            <h4 style="margin-top: 0;">Información del Cliente</h4>
            <table style="border: none;">
                <tr>
                    <th style="width: 30%; border: none;">Razón Social:</th>
                    <td style="width: 70%; border: none;">{{ $razonSocialComprador }}</td>
                </tr>
                <tr>
                    <th style="border: none;">Identificación:</th>
                    <td style="border: none;">{{ $identificacionComprador }}</td>
                </tr>
                <tr>
                    <th style="border: none;">Dirección:</th>
                    <td style="border: none;">{{ $direccionComprador }}</td>
                </tr>
            </table>
        </div>

        <h4>Detalles del Comprobante</h4>
        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Descripción</th>
                    <th>Cant.</th>
                    <th>P. Unitario</th>
                    <th>Descuento</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @if(count($detalles) > 0)
                @foreach($detalles as $detalle)
                <tr>
                    <td>{{ $detalle['codigoPrincipal'] ?? '001' }}</td>
                    <td>{{ $detalle['descripcion'] }}</td>
                    <td class="text-center">{{ number_format($detalle['cantidad'], 2) }}</td>
                    <td class="text-right">${{ number_format($detalle['precioUnitario'], 2) }}</td>
                    <td class="text-right">${{ number_format($detalle['descuento'] ?? 0, 2) }}</td>
                    <td class="text-right">${{ number_format($detalle['precioTotal'], 2) }}</td>
                </tr>
                @endforeach
                @else
                <tr>
                    <td>001</td>
                    <td>Producto/Servicio según factura</td>
                    <td class="text-center">1.00</td>
                    <td class="text-right">${{ number_format($subtotal, 2) }}</td>
                    <td class="text-right">$0.00</td>
                    <td class="text-right">${{ number_format($subtotal, 2) }}</td>
                </tr>
                @endif
            </tbody>
        </table>

        <div class="clearfix">
            <div class="info-box" style="float: left; width: 45%;">
                <h4 style="margin-top: 0;">Información Adicional</h4>
                @if(is_array($infoAdicional) && count($infoAdicional) > 0)
                @foreach($infoAdicional as $key => $value)
                <p><strong>{{ $key }}:</strong> {{ $value }}</p>
                @endforeach
                @else
                <p><strong>Email:</strong> info@prebam.com</p>
                <p><strong>Dirección:</strong> Guayaquil, Ecuador</p>
                @endif

                @if(isset($numeroAutorizacion))
                <p><strong>Autorización SRI:</strong> {{ $numeroAutorizacion }}</p>
                @endif
            </div>

            <div class="totals">
                <table>
                    <tr>
                        <td><strong>SUBTOTAL 12%</strong></td>
                        <td class="text-right">${{ number_format($subtotal12 ?? ($iva > 0 ? $subtotal : 0), 2) }}</td>
                    </tr>
                    <tr>
                        <td><strong>SUBTOTAL 0%</strong></td>
                        <td class="text-right">${{ number_format($subtotal0 ?? ($iva == 0 ? $subtotal : 0), 2) }}</td>
                    </tr>
                    <tr>
                        <td><strong>SUBTOTAL No objeto de IVA</strong></td>
                        <td class="text-right">$0.00</td>
                    </tr>
                    <tr>
                        <td><strong>SUBTOTAL Exento de IVA</strong></td>
                        <td class="text-right">$0.00</td>
                    </tr>
                    <tr>
                        <td><strong>SUBTOTAL Sin Impuestos</strong></td>
                        <td class="text-right">${{ number_format($subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td><strong>DESCUENTO</strong></td>
                        <td class="text-right">${{ number_format($totalDescuento ?? 0, 2) }}</td>
                    </tr>
                    <tr>
                        <td><strong>IVA 12%</strong></td>
                        <td class="text-right">${{ number_format($iva, 2) }}</td>
                    </tr>
                    <tr>
                        <td><strong>VALOR TOTAL</strong></td>
                        <td class="text-right"><strong>${{ number_format($total, 2) }}</strong></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="footer">
            <p>DOCUMENTO GENERADO AUTOMÁTICAMENTE POR EL SISTEMA LMS</p>
            <p>Clave de Acceso: {{ $claveAcceso }}</p>
            <p>Fecha de Generación: {{ date('d/m/Y H:i:s') }}</p>
        </div>
    </div>
</body>

</html>