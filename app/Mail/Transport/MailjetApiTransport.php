<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

// Envoi des e-mails par l'API HTTPS de Mailjet (Send API v3.1) : l'offre
// Hobby de Railway bloque les ports SMTP sortants (25, 465, 587).
// Authentification HTTP Basic : clé API publique + clé secrète.
class MailjetApiTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.mailjet.com/v3.1/send';

    public function __construct(private readonly string $apiKey, private readonly string $secretKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? null;
        if ($from === null) {
            throw new TransportException('Mailjet : adresse d\'expéditeur manquante (MAIL_FROM_ADDRESS).');
        }

        $replyTo = $email->getReplyTo()[0] ?? null;
        $payload = array_filter([
            'From'     => $this->address($from),
            'To'       => $this->addresses($email->getTo()),
            'Cc'       => $this->addresses($email->getCc()),
            'Bcc'      => $this->addresses($email->getBcc()),
            'ReplyTo'  => $replyTo ? $this->address($replyTo) : null,
            'Subject'  => (string) $email->getSubject(),
            'HTMLPart' => $email->getHtmlBody() !== null ? (string) $email->getHtmlBody() : null,
            'TextPart' => $email->getTextBody() !== null ? (string) $email->getTextBody() : null,
        ], fn ($value) => $value !== null && $value !== []);

        $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
            ->acceptJson()
            ->timeout(15)
            ->post(self::ENDPOINT, ['Messages' => [$payload]]);

        // Mailjet renvoie un statut par message : on vérifie les deux.
        if ($response->failed() || $response->json('Messages.0.Status') !== 'success') {
            // Le corps donne la raison (expéditeur non validé, clés invalides…) ;
            // les clés elles-mêmes n'apparaissent jamais dans le journal.
            throw new TransportException(sprintf('Mailjet a refusé l\'e-mail (HTTP %d) : %s', $response->status(), $response->body()));
        }

        if ($messageId = $response->json('Messages.0.To.0.MessageUUID')) {
            $message->setMessageId((string) $messageId);
        }
    }

    /** @param Address[] $addresses */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $address) => $this->address($address), $addresses);
    }

    private function address(Address $address): array
    {
        return array_filter(['Email' => $address->getAddress(), 'Name' => $address->getName() ?: null]);
    }

    public function __toString(): string
    {
        return 'mailjet+api://default';
    }
}
