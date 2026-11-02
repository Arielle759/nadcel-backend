<?php

namespace App\Mail;

use App\Models\Salon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Alerte envoyée aux administrateurs : une nouvelle demande attend une décision.
class PartnerRequestSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Salon $salon) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nouvelle demande de partenariat : '.$this->salon->name);
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.partner.submitted',
            with: ['salon' => $this->salon, 'url' => config('app.frontend_url')],
        );
    }
}
