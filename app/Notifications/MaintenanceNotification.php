<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MaintenanceNotification extends Notification
{
    protected string $startTime;
    protected string $endTime;
    protected string $date;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $date, string $startTime, string $endTime)
    {
        $this->date = $date;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    // public function toMail($notifiable)
    // {
    //     return (new MailMessage)
    //         ->subject('Mantenimiento programado del sistema LMS')
    //         ->greeting('Estimado usuario,')
    //         ->line("El sistema LMS estará fuera de servicio el día {$this->date} desde las {$this->startTime} hasta las {$this->endTime} por mantenimiento programado en las bases de datos.")
    //         ->line('Durante ese periodo no podrá acceder al sistema. Se mostrará una pantalla informativa para evitar la pérdida de información.')
    //         ->line('Si el mantenimiento concluye antes de lo previsto, el acceso será restablecido de inmediato.')
    //         ->salutation('Saludos cordiales,');
    // }
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Mantenimiento programado del sistema LMS')
            ->view('emails.maintenance-notification', [
                'user' => $notifiable,
                'date' => $this->date,
                'startTime' => $this->startTime,
                'endTime' => $this->endTime,
            ])
            ->withSymfonyMessage(function ($message) {
                $headers = $message->getHeaders();
                $headers->addTextHeader('X-Priority', '1');
                $headers->addTextHeader('X-MSMail-Priority', 'High');
                $headers->addTextHeader('Importance', 'High');
            });
    }


    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
