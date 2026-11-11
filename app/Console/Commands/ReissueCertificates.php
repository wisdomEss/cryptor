<?php

namespace App\Console\Commands;

use App\Models\KeyPair;
use App\Services\Crypto\CertificateAuthority;
use Illuminate\Console\Command;

class ReissueCertificates extends Command
{
    protected $signature = 'cryptor:reissue-certificates {--dry-run : Affiche ce qui serait fait sans rien modifier}';

    protected $description = 'Ré-émet, sous l\'AC interne, les certificats auto-signés des clés existantes (même clé, même sujet)';

    public function handle(CertificateAuthority $ca): int
    {
        $ca->ensure();
        $dryRun = (bool) $this->option('dry-run');
        $done = 0;

        KeyPair::where('is_revoked', false)->whereNotNull('certificate_data')->each(function (KeyPair $key) use ($ca, $dryRun, &$done) {
            if ($ca->isIssuedByCa($key->certificate_data)) {
                return;
            }

            $days = $key->valid_to && $key->valid_to->isFuture()
                ? max(1, (int) now()->diffInDays($key->valid_to, false))
                : (int) config('cryptor.certificate.default_days');

            $this->line(sprintf('#%d %s (%d jours)%s', $key->id, $key->key_name, $days, $dryRun ? ' [simulation]' : ''));

            if ($dryRun) {
                return;
            }

            $old = openssl_x509_parse($key->certificate_data);
            $subject = $this->subjectFrom($old['subject'] ?? [], $key->key_name);
            $issued = $ca->issue($key->private_key, $subject, $days);

            // Même paire de clés : les anciennes signatures restent liées à la clé enregistrée.
            $key->update([
                'certificate_data' => $issued['certificate'],
                'certificate_issuer' => $issued['issuer'],
                'valid_from' => $issued['valid_from'],
                'valid_to' => $issued['valid_to'],
            ]);
            $done++;
        });

        $this->info($dryRun ? 'Simulation terminée.' : "{$done} certificat(s) ré-émis.");

        return self::SUCCESS;
    }

    /** Reprend le sujet de l'ancien certificat (C, O, OU, CN, emailAddress). */
    private function subjectFrom(array $subject, string $fallbackName): array
    {
        $map = ['C' => 'countryName', 'O' => 'organizationName', 'OU' => 'organizationalUnitName', 'CN' => 'commonName', 'emailAddress' => 'emailAddress'];
        $dn = [];

        foreach ($map as $short => $long) {
            if (! empty($subject[$short])) {
                $dn[$long] = $subject[$short];
            }
        }

        return $dn + ['commonName' => $fallbackName];
    }
}
