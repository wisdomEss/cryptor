<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class VerifyUser extends Command
{
    protected $signature = 'cryptor:verify-user {email? : Adresse du compte à confirmer} {--all : Confirme tous les comptes non confirmés}';

    protected $description = 'Confirme l\'adresse e-mail d\'un compte à la main (essai local sans serveur d\'e-mail, ou dépannage)';

    public function handle(): int
    {
        if ($this->option('all')) {
            $count = User::whereNull('email_verified_at')->update(['email_verified_at' => now()]);
            $this->info("{$count} compte(s) confirmé(s).");

            return self::SUCCESS;
        }

        $email = $this->argument('email');

        if (! $email) {
            $this->error('Indiquez une adresse e-mail, ou --all.');

            return self::FAILURE;
        }

        $user = User::where('email', mb_strtolower($email))->first();

        if (! $user) {
            $this->error("Aucun compte pour {$email}.");

            return self::FAILURE;
        }

        if ($user->hasVerifiedEmail()) {
            $this->info("{$user->email} est déjà confirmé.");

            return self::SUCCESS;
        }

        $user->markEmailAsVerified();
        $this->info("{$user->email} confirmé.");

        return self::SUCCESS;
    }
}
