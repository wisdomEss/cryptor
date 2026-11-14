<?php

namespace App\Console\Commands;

use App\Models\SignatureVerification;
use Illuminate\Console\Command;

/**
 * Purge les vérifications **publiques** anciennes.
 *
 * `/api/public/verify` est ouvert à tous : sans purge, la table d'audit grossit
 * indéfiniment au rythme des appels anonymes. Les vérifications internes (signature,
 * validation, révocation) ne sont jamais supprimées : elles constituent la trace
 * des actions des utilisateurs.
 */
class PruneVerifications extends Command
{
    protected $signature = 'cryptor:prune-verifications
                            {--days= : Âge au-delà duquel une vérification publique est supprimée (défaut : config cryptor.public_verification.retention_days)}
                            {--dry-run : Compter sans supprimer}';

    protected $description = 'Supprime les vérifications publiques plus anciennes que la durée de conservation';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('cryptor.public_verification.retention_days'));

        if ($days <= 0) {
            $this->info('Conservation illimitée (retention_days = 0) : rien à faire.');

            return self::SUCCESS;
        }

        $before = now()->subDays($days);

        $query = SignatureVerification::query()
            ->where('is_public_verification', true)
            ->where('verified_at', '<', $before);

        if ($this->option('dry-run')) {
            $this->info("{$query->count()} vérification(s) publique(s) antérieure(s) au {$before->toDateString()} seraient supprimées.");

            return self::SUCCESS;
        }

        // Par lots : une suppression unique sur une table d'audit volumineuse
        // bloquerait trop longtemps.
        $deleted = 0;
        do {
            $batch = (clone $query)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("{$deleted} vérification(s) publique(s) supprimée(s) (antérieures au {$before->toDateString()}).");

        return self::SUCCESS;
    }
}
