<?php

namespace App\Mail;

use App\Models\Salon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Accusé de réception envoyé au demandeur dès l'envoi de sa demande.
class PartnerRequestReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Salon $salon) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nadcel : votre demande pour '.$this->salon->name.' est bien reçue');
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.partner.received',
            with: ['salon' => $this->salon, 'url' => config('app.frontend_url')],
        );
    }
}
