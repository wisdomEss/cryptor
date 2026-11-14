<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTest extends Command
{
    protected $signature = 'cryptor:mail-test {to : Adresse du destinataire du message de test}';

    protected $description = 'Envoie un message de test pour vérifier la configuration e-mail (MAIL_*) du serveur';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("« {$to} » n'est pas une adresse e-mail valide.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $from = config('mail.from.address');

        // Les paramètres utiles au diagnostic, jamais le mot de passe.
        $this->line('Transport     : '.$mailer);
        if ($mailer === 'smtp') {
            $this->line('Serveur       : '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
            $this->line('Identifiant   : '.(config('mail.mailers.smtp.username') ? 'renseigné' : 'VIDE'));
            $this->line('Mot de passe  : '.(config('mail.mailers.smtp.password') ? 'renseigné' : 'VIDE'));
        }
        $this->line('Expéditeur    : '.$from);

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER={$mailer} : le message sera seulement écrit dans les logs (storage/logs/laravel.log), pas envoyé. Définissez MAIL_MAILER=smtp puis relancez « php artisan config:cache ».");
        }

        try {
            Mail::raw('Ce message confirme que l\'envoi d\'e-mails de CRYPTOR fonctionne.', function ($message) use ($to) {
                $message->to($to)->subject('Test CRYPTOR');
            });
        } catch (\Throwable $e) {
            // Sortie console réservée à l'administrateur : le détail aide à corriger la configuration.
            $this->error('Échec de l\'envoi : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Message accepté par le serveur d'envoi pour {$to}. Vérifiez la boîte de réception (et les spams).");

        return self::SUCCESS;
    }
}
