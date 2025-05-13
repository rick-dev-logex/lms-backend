<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura {{ $secuencial }}</title>
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
        }

        .company-info {
            float: left;
            width: 50%;
        }

        .document-info {
            float: right;
            width: 45%;
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
            padding: 8px;
            text-align: left;
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
            font-size: 10px;
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
            font-size: 10px;
            text-align: center;
            color: #666;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header clearfix">
            <div class="company-info">
                <h2>{{ $razonSocial }}</h2>
                <p>RUC: {{ $ruc }}</p>
                <p>Dirección Matriz: {{ $dirMatriz }}</p>
                <p>Ambiente: {{ $ambiente == '1' ? 'PRUEBAS' : 'PRODUCCIÓN' }}</p>
                <p>Emisión: {{ $tipoEmision == '1' ? 'NORMAL' : 'CONTINGENCIA' }}</p>
            </div>
            <div class="document-info">
                <h3>{{ strtoupper($tipoComprobante) }}</h3>
                <p>No. {{ $estab }}-{{ $ptoEmi }}-{{ $secuencial }}</p>
                <p>Fecha Emisión: {{ $fechaEmision }}</p>
                <p>Fecha Autorización: {{ $fechaAutorizacion }}</p>
            </div>
        </div>

        <div class="barcode">
            {{ $claveAcceso }}
        </div>

        <div class="info-box">
            <h4>Información del Cliente</h4>
            <p>Razón Social / Nombres y Apellidos: {{ $razonSocialComprador }}</p>
            <p>Identificación: {{ $identificacionComprador }}</p>
            <p>Dirección: {{ $direccionComprador }}</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Descripción</th>
                    <th>Cantidad</th>
                    <th>Precio Unitario</th>
                    <th>Descuento</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($detalles as $detalle)
                <tr>
                    <td>001</td>
                    <td>{{ $detalle['descripcion'] }}</td>
                    <td class="text-center">{{ number_format($detalle['cantidad'], 2) }}</td>
                    <td class="text-right">${{ number_format($detalle['precioUnitario'], 2) }}</td>
                    <td class="text-right">${{ number_format($detalle['descuento'], 2) }}</td>
                    <td class="text-right">${{ number_format($detalle['precioTotal'], 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="clearfix">
            <div class="info-box" style="float: left; width: 45%;">
                <h4>Información Adicional</h4>
                @foreach($infoAdicional as $key => $value)
                <p><strong>{{ $key }}:</strong> {{ $value }}</p>
                @endforeach
            </div>

            <div class="totals">
                <table>
                    <tr>
                        <td>SUBTOTAL 12%</td>
                        <td class="text-right">${{ number_format($iva > 0 ? $subtotal : 0, 2) }}</td>
                    </tr>
                    <tr>
                        <td>SUBTOTAL 0%</td>
                        <td class="text-right">${{ number_format($iva == 0 ? $subtotal : 0, 2) }}</td>
                    </tr>
                    <tr>
                        <td>SUBTOTAL No objeto de IVA</td>
                        <td class="text-right">$0.00</td>
                    </tr>
                    <tr>
                        <td>SUBTOTAL Exento de IVA</td>
                        <td class="text-right">$0.00</td>
                    </tr>
                    <tr>
                        <td>SUBTOTAL Sin Impuestos</td>
                        <td class="text-right">${{ number_format($subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td>DESCUENTO</td>
                        <td class="text-right">$0.00</td>
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
        </div>

        <div class="footer">
            <p>DOCUMENTO GENERADO AUTOMÁTICAMENTE POR EL SISTEMA LMS</p>
            <p>Clave de Acceso: {{ $claveAcceso }}</p>
        </div>
    </div>
</body>

</html>