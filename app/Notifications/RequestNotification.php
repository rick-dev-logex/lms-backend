<?php

namespace App\Notifications;

use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

class RequestNotification extends Notification
{
    use Queueable;

    public $request;
    public $action;
    public $additionalMessage;

    public function __construct(Request $request, string $action, ?string $additionalMessage = null)
    {
        $this->request = $request;
        $this->action = $action;
        $this->additionalMessage = $additionalMessage;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $statusMessages = [
            'created' => 'Una nueva solicitud ha sido creada',
            'approved' => 'Tu solicitud ha sido aprobada',
            'rejected' => 'Tu solicitud ha sido rechazada',
            'review' => 'Tu solicitud requiere revisión'
        ];

        $message = $statusMessages[$this->action] ?? "Estado de solicitud actualizado a {$this->action}";

        return (new MailMessage)
            ->subject("Actualización de Solicitud - {$this->request->unique_id}")
            ->view('emails.request-status', [
                'user' => $notifiable,
                'request' => $this->request,
                'action' => $this->action,
                'message' => $message,
                'additionalMessage' => $this->additionalMessage,
                'url' => config('app.frontend_url') . '/requests/' . $this->request->id
            ]);
    }

    public function toArray($notifiable)
    {
        return [
            'request_id' => $this->request->id,
            'unique_id' => $this->request->unique_id,
            'action' => $this->action,
            'message' => $this->additionalMessage,
            'type' => $this->request->type, // expense o discount
            'amount' => $this->request->amount,
            'created_at' => now()->toISOString()
        ];
    }
}
