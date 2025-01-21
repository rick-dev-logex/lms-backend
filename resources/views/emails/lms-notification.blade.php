<!-- resources/views/emails/test.blade.php -->
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email de Prueba</title>
    <style>
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            font-family: Arial, sans-serif;
            color: #333333;
        }

        .header {
            /* background: #991b1b; */
            background: #dc2626;
            padding: 20px;
            text-align: center;
        }

        .header img {
            max-width: 200px;
        }

        .content {
            padding: 20px;
            background-color: #ffffff;
            border: 1px solid #dddddd;
        }

        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #4a90e2;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 4px;
            margin: 20px 0;
        }

        .footer {
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #666666;
            background-color: #f5f5f5;
        }
    </style>
</head>

<body style="margin: 0; padding: 0; background-color: #f5f5f5;">
    <div class="email-container">
        <div class="header">
            <img src="{{ $message->embed(public_path('images/logo_white.png')) }}" alt="Logo">
        </div>

        <div class="content">
            <h2>Hola {{ $user->name ?? 'John!' }},</h2>

            <p>{{ $content }}</p>
        </div>

        <div class="footer">
            <p>© {{ date('Y') }} LogeX. Todos los derechos reservados.</p>
            <p>Este es un correo automático, por favor no respondas a este mensaje.</p>
        </div>
    </div>
</body>

</html>