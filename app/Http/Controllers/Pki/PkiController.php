<?php

namespace App\Http\Controllers\Pki;

use App\Http\Controllers\Controller;
use App\Services\Crypto\CertificateAuthority;

/**
 * Informations publiques de l'infrastructure de clés (sans authentification) :
 * de quoi permettre à un tiers de valider un certificat ou une signature hors de CRYPTOR.
 */
class PkiController extends Controller
{
    public function __construct(private readonly CertificateAuthority $ca) {}

    /** Certificat de l'AC (PEM), à utiliser avec `openssl ... -CAfile`. */
    public function certificate()
    {
        return response($this->ca->certificate(), 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="cryptor-ca.crt"',
        ]);
    }

    /**
     * Liste des certificats révoqués, signée par l'AC.
     * Seuls le numéro de série et la date sont publiés (jamais le motif de révocation).
     */
    public function revocations()
    {
        return response()->json($this->ca->revocationList());
    }
}
