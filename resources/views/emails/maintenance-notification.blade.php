<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mantenimiento del Sistema LMS</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
            font-size: 16px;
            line-height: 1.5;
            display: grid;
            place-items: center;
        }

        .container {
            max-width: 700px;
            margin: 0 auto !important;
            background: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .logo {
            max-width: 150px;
            margin-bottom: 20px;
        }

        .content {
            text-align: left;
            font-size: 16px;
            color: #333;
        }

        p {
            text-align: left;
            text-wrap: pretty;
        }

        .footer {
            margin-top: 60px;
            font-size: 14px;
            color: #666;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            margin-inline: auto;
            text-align: center !important;
            line-height: 0;
            border-top: 1px solid #00000018;
            padding-top: 15px;
        }

        a {
            color: #e13532;
            text-decoration: none;
            font-weight: 600;
        }

        a:hover {
            color: #e13532;
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="container">
        <img src="https://api.lms.logex.com.ec/images/logo.png" alt="Logo de LogeX" class="logo">
        <div class="content">
            <p style="font-size: 20px;">¡Hola, {{ $user->name }}!</p>
            <p>
                El sistema <strong>LMS</strong> estará fuera de servicio el día <strong>{{ $date }}</strong> desde las
                <strong>{{ $startTime }}</strong> hasta las <strong>{{ $endTime }}</strong> por mantenimiento programado.
            </p>
            <p>
                Durante ese periodo no podrás acceder al sistema. Se mostrará una pantalla informativa para evitar la pérdida de información.
            </p>
            <p>
                Si el mantenimiento concluye antes de lo previsto, el acceso será restablecido de inmediato.
            </p>
        </div>

        <div class="footer">
            <p style="font-size: 12px;">Este es un correo electrónico automático, por favor no respondas a este mensaje.</p>
            <p style="font-size: 12px;">LMS | Sistema de Gestión de <span style="font-weight:900; font-style: italic;"><span style="color:#e13532">Log</span>eX</span>. © {{ date('Y') }}</p>
        </div>
    </div>
</body>

</html>