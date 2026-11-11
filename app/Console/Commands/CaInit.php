<?php

namespace App\Console\Commands;

use App\Services\Crypto\CertificateAuthority;
use Illuminate\Console\Command;

class CaInit extends Command
{
    protected $signature = 'cryptor:ca-init {--force : Régénère l\'AC (les certificats existants ne seront plus de confiance)}';

    protected $description = 'Crée l\'autorité de certification interne (inutile en pratique : elle se crée seule au premier usage)';

    public function handle(CertificateAuthority $ca): int
    {
        if ($ca->exists() && ! $this->option('force')) {
            $this->info('L\'autorité de certification existe déjà.');
        } elseif ($ca->exists()) {
            if (! $this->confirm('Les certificats déjà émis ne seront plus de confiance (voir cryptor:reissue-certificates). Continuer ?')) {
                return self::FAILURE;
            }

            $ca->generate();
            $this->warn('Nouvelle autorité de certification générée.');
        } else {
            $ca->generate();
            $this->info('Autorité de certification créée.');
        }

        $this->line('Nom         : '.config('cryptor.ca.name'));
        $this->line('Empreinte   : '.$ca->fingerprint());
        $this->line('Certificat  : '.$ca->certificatePath());

        return self::SUCCESS;
    }
}
