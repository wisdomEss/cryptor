<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Autorité d'horodatage RFC 3161 locale pour les tests : certificats créés par PHP,
 * réponses fabriquées par `openssl ts -reply`. Aucun accès réseau.
 */
class FakeTimestampServer
{
    public string $dir;

    public string $caFile;

    public int $requests = 0;

    public static function available(): bool
    {
        return trim((string) shell_exec('command -v openssl')) !== '';
    }

    public function __construct()
    {
        $this->dir = sys_get_temp_dir().'/fake-tsa-'.uniqid();
        mkdir($this->dir);

        $cnf = <<<'CNF'
[ tsa ]
default_tsa = tsa_config
[ tsa_config ]
dir = .
serial = ./tsa.serial
crypto_device = builtin
signer_cert = ./tsa.crt
certs = ./ca.crt
signer_key = ./tsa.key
signer_digest = sha256
default_policy = 1.2.3.4.1
digests = sha256
accuracy = secs:1
ordering = yes
tsa_name = yes
ess_cert_id_alg = sha256
[ ext_ca ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
[ ext_tsa ]
basicConstraints = CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = critical,timeStamping
[ req ]
distinguished_name = dn
prompt = no
[ dn ]
CN = x
CNF;
        file_put_contents("{$this->dir}/tsa.cnf", $cnf);
        file_put_contents("{$this->dir}/tsa.serial", "01\n");

        $options = ['config' => "{$this->dir}/tsa.cnf"];
        $ec = $options + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];

        $caKey = openssl_pkey_new($ec);
        $caCsr = openssl_csr_new(['commonName' => 'Test TSA CA'], $caKey, $options);
        $caCert = openssl_csr_sign($caCsr, null, $caKey, 30, $options + ['x509_extensions' => 'ext_ca']);

        $tsaKey = openssl_pkey_new($ec);
        $tsaCsr = openssl_csr_new(['commonName' => 'Test TSA'], $tsaKey, $options);
        $tsaCert = openssl_csr_sign($tsaCsr, $caCert, $caKey, 30, $options + ['x509_extensions' => 'ext_tsa'], 2);

        openssl_x509_export($caCert, $caPem);
        openssl_x509_export($tsaCert, $tsaPem);
        openssl_pkey_export($tsaKey, $tsaKeyPem, null, $options);

        $this->caFile = "{$this->dir}/ca.crt";
        file_put_contents($this->caFile, $caPem);
        file_put_contents("{$this->dir}/tsa.crt", $tsaPem);
        file_put_contents("{$this->dir}/tsa.key", $tsaKeyPem);
    }

    /** Réponse TimeStampResp de la TSA à une requête DER. */
    public function reply(string $queryDer): string
    {
        file_put_contents("{$this->dir}/q.tsq", $queryDer);
        @unlink("{$this->dir}/r.tsr");

        exec(sprintf('cd %s && openssl ts -reply -queryfile q.tsq -config tsa.cnf -section tsa_config -out r.tsr 2>&1', escapeshellarg($this->dir)), $out, $code);

        if ($code !== 0 || ! is_file("{$this->dir}/r.tsr")) {
            throw new \RuntimeException('Fake TSA failed: '.implode("\n", $out));
        }

        return (string) file_get_contents("{$this->dir}/r.tsr");
    }

    /** Fait répondre toute requête HTTP vers la TSA par ce serveur local. */
    public function fakeHttp(?callable $transform = null): void
    {
        Http::fake(function (Request $request) use ($transform) {
            $this->requests++;
            $reply = $this->reply($request->body());

            return Http::response($transform ? $transform($reply, $request->body()) : $reply, 200, ['Content-Type' => 'application/timestamp-reply']);
        });
    }

    public function __destruct()
    {
        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }
}
