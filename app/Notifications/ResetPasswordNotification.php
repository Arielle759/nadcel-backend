<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function resetUrl(object $notifiable): string
    {
        return config('app.frontend_url').'/reinitialiser-mot-de-passe?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe Nadcel')
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Vous avez demandé à changer le mot de passe de votre compte Nadcel.')
            ->action('Choisir un nouveau mot de passe', $this->resetUrl($notifiable))
            ->line("Ce lien est valable {$minutes} minutes et ne peut servir qu'une fois.")
            ->line("Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.")
            ->salutation("L'équipe Nadcel");
    }
}
