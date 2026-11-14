<?php

namespace Tests\Feature;

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    public function test_it_sends_a_test_message_and_never_prints_the_password(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'login-smtp',
            'mail.mailers.smtp.password' => 'cle-smtp-ultra-secrete',
        ]);
        Mail::fake();

        $this->artisan('cryptor:mail-test', ['to' => 'destinataire@example.org'])
            ->expectsOutputToContain('smtp-relay.brevo.com:587')
            ->expectsOutputToContain('Mot de passe  : renseigné')
            ->doesntExpectOutputToContain('cle-smtp-ultra-secrete')
            ->expectsOutputToContain('Message accepté')
            ->assertSuccessful();
    }

    public function test_it_reports_missing_credentials(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null]);
        Mail::fake();

        $this->artisan('cryptor:mail-test', ['to' => 'destinataire@example.org'])
            ->expectsOutputToContain('Identifiant   : VIDE')
            ->expectsOutputToContain('Mot de passe  : VIDE')
            ->assertSuccessful();
    }

    public function test_it_warns_when_the_mailer_does_not_really_send(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();

        $this->artisan('cryptor:mail-test', ['to' => 'destinataire@example.org'])
            ->expectsOutputToContain('MAIL_MAILER=log')
            ->assertSuccessful();
    }

    public function test_it_rejects_an_invalid_address(): void
    {
        Mail::fake();

        $this->artisan('cryptor:mail-test', ['to' => 'pas-une-adresse'])
            ->expectsOutputToContain('pas une adresse e-mail valide')
            ->assertFailed();
    }

    public function test_it_shows_the_failure_reason_to_the_administrator(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('Authentication failed: 535 invalid credentials'));

        $this->artisan('cryptor:mail-test', ['to' => 'destinataire@example.org'])
            ->expectsOutputToContain('Échec de l\'envoi : Authentication failed: 535')
            ->assertFailed();
    }

    public function test_it_shows_the_last_server_reply_but_never_the_client_commands(): void
    {
        config(['mail.default' => 'smtp']);

        $symfony = new \Symfony\Component\Mailer\SentMessage(
            new RawMessage('x'),
            new Envelope(new Address('a@example.org'), [new Address('b@example.org')])
        );
        $symfony->appendDebug("< 220 smtp.exemple ESMTP\r\n> AUTH PLAIN SECRET-BASE64-CREDENTIALS\r\n< 235 Authentication succeeded\r\n< 250 2.0.0 OK queued as brevo-id-42\r\n");

        Mail::shouldReceive('raw')->andReturn(new SentMessage($symfony));

        $this->artisan('cryptor:mail-test', ['to' => 'destinataire@example.org'])
            ->expectsOutputToContain('Réponse du serveur : 250 2.0.0 OK queued as brevo-id-42')
            ->doesntExpectOutputToContain('SECRET-BASE64-CREDENTIALS')
            ->assertSuccessful();
    }
}
