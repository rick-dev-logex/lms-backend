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
            ->priority(1)
            ->withSymfonyMessage(function ($message) {
                $headers = $message->getHeaders();
                $headers->addTextHeader('X-Priority', '1');
                $headers->addTextHeader('X-MSMail-Priority', 'Highest');
                $headers->addTextHeader('Importance', 'Highest');
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
