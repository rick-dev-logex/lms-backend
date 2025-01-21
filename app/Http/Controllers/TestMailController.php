<?php

namespace App\Http\Controllers;

use App\Mail\TestEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestMailController extends Controller
{
    public function sendTestEmail()
    {
        try {
            // Mantener el logging de configuración
            Log::info('Mail Configuration', [
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'encryption' => config('mail.mailers.smtp.encryption'),
                'from_address' => config('mail.from.address'),
                'username' => config('mail.mailers.smtp.username'),
                'api_key_starts_with' => substr(config('mail.mailers.smtp.password'), 0, 5)
            ]);

            // Usar el Mailable
            Mail::to('ricardo.estrella@logex.ec')
                ->send(new TestEmail('Email de prueba para las notificaciones enviado automáticamente desde el sistema LMS de LogeX.'));

            return response()->json(['message' => 'Correo enviado exitosamente']);
        } catch (\Exception $e) {
            Log::error('Mail Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error al enviar el correo :(',
                'error' => $e->getMessage(),
                'debug_info' => [
                    'mail_host' => config('mail.mailers.smtp.host'),
                    'mail_port' => config('mail.mailers.smtp.port'),
                    'mail_encryption' => config('mail.mailers.smtp.encryption'),
                    'mail_from' => config('mail.from.address'),
                    'using_api_key' => config('mail.mailers.smtp.username') === 'apikey'
                ]
            ], 500);
        }
    }
}
