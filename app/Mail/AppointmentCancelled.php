<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentCancelled extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param bool $initiatedByClient true = client a annulé (destinataire = gérant), false = gérant a annulé (destinataire = client)
     */
    public function __construct(
        public Appointment $appointment,
        public bool $initiatedByClient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->appointment->cancelled_late ? 'Annulation tardive d\'un rendez-vous' : 'Rendez-vous annulé');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.appointment.cancelled');
    }
}
