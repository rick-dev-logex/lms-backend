<!-- resources/views/emails/reset-password.blade.php -->
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            font-family: Arial, sans-serif;
            color: #333333;
        }

        .header {
            background-color: #4a90e2;
            padding: 20px;
            text-align: center;
        }

        .header img {
            max-width: 200px;
        }

        .content {
            padding: 20px;
            background-color: #ffffff;
        }

        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #4a90e2;
            color: #ffffff;
            text-decoration: none;
            border-radius: 4px;
            margin: 20px 0;
        }

        .footer {
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #666666;
        }
    </style>
</head>

<body>
    <div class="email-container">
        <div class="header">
            <img src="{{ asset('images/logo.png') }}" alt="Logo">
        </div>

        <div class="content">
            <h2>Hola {{ $user->name }},</h2>

            <p>Has solicitado restablecer tu contraseña. Haz clic en el botón de abajo para crear una nueva contraseña:</p>

            <div style="text-align: center;">
                <a href="{{ $url }}" class="button">Restablecer Contraseña</a>
            </div>

            <p>Este enlace expirará en {{ $count }} minutos.</p>

            <p>Si no has solicitado cambiar tu contraseña, puedes ignorar este correo.</p>

            <p>Si tienes problemas para hacer clic en el botón, copia y pega este enlace en tu navegador:</p>
            <p style="word-break: break-all;">{{ $url }}</p>
        </div>

        <div class="footer">
            <p>© {{ date('Y') }} LogeX. Todos los derechos reservados.</p>
            <p>Este es un correo automático, por favor no respondas a este mensaje.</p>
        </div>
    </div>
</body>

</html>