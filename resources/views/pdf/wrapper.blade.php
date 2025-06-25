<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>{{ $fileName }}</title>
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
        }

        embed {
            width: 100%;
            height: 100%;
        }
    </style>
</head>

<body>
    <embed src="{{ url("api/invoices/{$invoice->id}/pdf") }}?download=0" type="application/pdf" />
</body>

</html>
