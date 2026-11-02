<?php

namespace App\Mail;

use App\Models\Salon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Envoyé au gérant quand l'administrateur valide sa demande.
class PartnerRequestApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Salon $salon) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nadcel : '.$this->salon->name.' est en ligne');
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.partner.approved',
            with: ['salon' => $this->salon, 'url' => config('app.frontend_url')],
        );
    }
}
