<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
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
}
