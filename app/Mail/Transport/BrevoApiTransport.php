<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

// Envoi des e-mails par l'API HTTPS de Brevo plutôt que par SMTP : l'offre
// Hobby de Railway bloque les ports SMTP sortants (25, 465, 587).
// Documentation : POST https://api.brevo.com/v3/smtp/email, en-tête « api-key ».
class BrevoApiTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? null;
        if ($from === null) {
            throw new TransportException('Brevo : adresse d\'expéditeur manquante (MAIL_FROM_ADDRESS).');
        }

        $payload = array_filter([
            'sender'      => $this->address($from),
            'to'          => $this->addresses($email->getTo()),
            'cc'          => $this->addresses($email->getCc()),
            'bcc'         => $this->addresses($email->getBcc()),
            'replyTo'     => ($email->getReplyTo()[0] ?? null) ? $this->address($email->getReplyTo()[0]) : null,
            'subject'     => (string) $email->getSubject(),
            'htmlContent' => $email->getHtmlBody() !== null ? (string) $email->getHtmlBody() : null,
            'textContent' => $email->getTextBody() !== null ? (string) $email->getTextBody() : null,
        ], fn ($value) => $value !== null && $value !== []);

        $response = Http::withHeaders(['api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(15)
            ->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            // Le corps de la réponse donne la raison (expéditeur non vérifié,
            // clé invalide…) ; la clé elle-même n'apparaît jamais dans le journal.
            throw new TransportException(sprintf('Brevo a refusé l\'e-mail (HTTP %d) : %s', $response->status(), $response->body()));
        }

        if ($messageId = $response->json('messageId')) {
            $message->setMessageId($messageId);
        }
    }

    /** @param Address[] $addresses */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $address) => $this->address($address), $addresses);
    }

    private function address(Address $address): array
    {
        return array_filter(['email' => $address->getAddress(), 'name' => $address->getName() ?: null]);
    }

    public function __toString(): string
    {
        return 'brevo+api://default';
    }
}
