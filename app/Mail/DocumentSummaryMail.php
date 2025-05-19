<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class DocumentSummaryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $stats;
    public $documents;

    /**
     * Create a new message instance.
     *
     * @param array $stats
     * @param Collection $documents
     */
    public function __construct(array $stats, Collection $documents)
    {
        $this->stats = $stats;
        $this->documents = $documents;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Resumen Diario de Documentos SRI - ' . $this->stats['date'])
            ->markdown('emails.documents.summary');
    }
}
