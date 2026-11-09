<?php

namespace Tests\Feature;

use App\Mail\Transport\MailjetApiTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MailjetMailTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'mailjet',
            'services.mailjet.key' => 'cle-publique',
            'services.mailjet.secret' => 'cle-secrete',
            'mail.from' => ['address' => 'contact@nadcel.test', 'name' => 'Nadcel'],
        ]);
    }

    public function test_mail_is_sent_through_the_mailjet_https_api(): void
    {
        Http::fake([MailjetApiTransport::ENDPOINT => Http::response([
            'Messages' => [['Status' => 'success', 'To' => [['MessageUUID' => 'uuid-1']]]],
        ], 200)]);

        Mail::html('<p>Bonjour</p>', fn ($message) => $message->to('awa@exemple.sn', 'Awa')->subject('Votre rendez-vous'));

        Http::assertSent(function (Request $request) {
            $message = $request['Messages'][0];
            return $request->url() === MailjetApiTransport::ENDPOINT
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('cle-publique:cle-secrete'))
                && $message['From'] === ['Email' => 'contact@nadcel.test', 'Name' => 'Nadcel']
                && $message['To'] === [['Email' => 'awa@exemple.sn', 'Name' => 'Awa']]
                && $message['Subject'] === 'Votre rendez-vous'
                && str_contains($message['HTMLPart'], 'Bonjour');
        });
    }

    public function test_a_refusal_from_mailjet_is_reported_without_the_keys(): void
    {
        Http::fake([MailjetApiTransport::ENDPOINT => Http::response([
            'Messages' => [['Status' => 'error', 'Errors' => [['ErrorMessage' => 'sender not validated']]]],
        ], 400)]);

        try {
            Mail::html('<p>Test</p>', fn ($message) => $message->to('awa@exemple.sn')->subject('Test'));
            $this->fail('Une erreur Mailjet doit être signalée.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('sender not validated', $e->getMessage());
            $this->assertStringNotContainsString('cle-secrete', $e->getMessage());
        }
    }
}
