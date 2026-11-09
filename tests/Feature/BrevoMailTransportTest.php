<?php

namespace Tests\Feature;

use App\Mail\Transport\BrevoApiTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class BrevoMailTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'brevo',
            'services.brevo.key' => 'cle-de-test',
            'mail.from' => ['address' => 'contact@nadcel.test', 'name' => 'Nadcel'],
        ]);
    }

    public function test_mail_is_sent_through_the_brevo_https_api(): void
    {
        Http::fake([BrevoApiTransport::ENDPOINT => Http::response(['messageId' => '<abc@brevo>'], 201)]);

        Mail::html('<p>Bonjour</p>', fn ($message) => $message->to('awa@exemple.sn', 'Awa')->subject('Votre rendez-vous'));

        Http::assertSent(function (Request $request) {
            return $request->url() === BrevoApiTransport::ENDPOINT
                && $request->hasHeader('api-key', 'cle-de-test')
                && $request['sender'] === ['email' => 'contact@nadcel.test', 'name' => 'Nadcel']
                && $request['to'] === [['email' => 'awa@exemple.sn', 'name' => 'Awa']]
                && $request['subject'] === 'Votre rendez-vous'
                && str_contains($request['htmlContent'], 'Bonjour');
        });
    }

    public function test_a_refusal_from_brevo_is_reported_without_the_api_key(): void
    {
        Http::fake([BrevoApiTransport::ENDPOINT => Http::response(['message' => 'sender not valid'], 400)]);

        try {
            Mail::html('<p>Test</p>', fn ($message) => $message->to('awa@exemple.sn')->subject('Test'));
            $this->fail('Une erreur Brevo doit être signalée.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('sender not valid', $e->getMessage());
            $this->assertStringNotContainsString('cle-de-test', $e->getMessage());
        }
    }
}
