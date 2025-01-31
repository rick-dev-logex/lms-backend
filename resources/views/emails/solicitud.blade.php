<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificación de Solicitud</title>
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
            margin-top: 20px;
            font-size: 14px;
            color: #666;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            margin-inline: auto;
            text-align: center !important;
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

        .solicitud-id {
            font-weight: 600;
            color: #5d5d5d;
            text-decoration: underline;
            text-decoration-color: #e13532;
            text-decoration-thickness: 2px;
            text-underline-offset: 1px;
            font-style: italic;
        }
    </style>
</head>

<body>
    <div class="container">
        <img src="{{ asset('/images/logo.png') }}" alt="Logo de LogeX" class="logo">

        <p class="content">
        <p>¡Hola, {{ $nombre }}!</p>

        @if ($tipo == 'pending')
            <p>Se ha creado la solicitud <span class="solicitud-id">{{ $solicitud_id }}</span> de manera exitosa. Revisa
                los detalles en el sistema LMS siguiendo <a href="{{ config('app.frontend_url') }}/solicitudes">este
                    enlace</a>.</p>
        @elseif($tipo == 'paid')
            <p>La solicitud <span class="solicitud-id">{{ $solicitud_id }}</span> ha sido <strong
                    style="color: #008000;">pagada</strong>
                @if ($note !== null && $note !== '')
                    con el siguiente comentario:
                @else
                    .
                @endif
            </p>
            @if ($note)
                <p style="background: #f8f8f8; padding: 10px; border-radius: 5px;">{{ $note }}</p>
            @endif
        @elseif($tipo == 'rejected')
            <p>La solicitud <span class="solicitud-id">{{ $solicitud_id }}</span> ha sido <strong
                    style="color: #e13532;">rechazada</strong>.</p>
            @if ($note !== null && $note !== '')
                <p style="background: #f8f8f8; padding: 10px; border-radius: 5px; color: #e13532;">{{ $note }}
                </p>
            @endif
        @elseif($tipo == 'review')
            <p>La solicitud <span class="solicitud-id"></span>{{ $solicitud_id }}</span> ha cambiado a <strong
                    style="color: #2835ad;">{{ $status }}</strong>

                @if ($note !== null && $note !== '')
                        con la siguiente observación:
                    </p>
                    <p style="background: #f8f8f8; padding: 10px; border-radius: 5px; text-wrap: pretty;">{{ $note }}
                    </p>
                @else
                    .
                @endif
        @elseif($tipo == 'in_reposition')
            <p>La solicitud <span class="solicitud-id">{{ $solicitud_id }}</span> ha sido enviada a <strong
                    style="color: #009688;">{{ $status }}</strong>. Por favor, espera a que la solicitud sea
                revisada. </p>
        @endif
        <div class="footer">
            <p style="font-size: 12px; color: #666; margin-bottom: 0;">Este es un correo electrónico automático, por
                favor
                no respondas a este mensaje.</p>
            <p style="font-size: 12px; color: #666; margin: 0;">LMS - Sistema de Gestión de <span
                    style="font-weight:900; font-style: italic;"><span style="color:#e13532">Log</span>eX</span>. ©
                {{ date('Y') }}
            </p>
        </div>
    </div>
</body>

</html>