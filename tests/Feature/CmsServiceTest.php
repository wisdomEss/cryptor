<?php

namespace Tests\Feature;

use App\Services\Crypto\CmsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CmsServiceTest extends TestCase
{
    private CmsService $cms;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cms = new CmsService;
        $this->file = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($this->file, 'contenu à signer');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /** @return array{0: string, 1: string} [certificat, clé privée] */
    private function credentials(string $type = 'rsa'): array
    {
        $config = $type === 'ecdsa'
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];

        $key = openssl_pkey_new($config + ['digest_alg' => 'sha256']);
        $csr = openssl_csr_new(['commonName' => 'Alice'], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 30), $cert);
        openssl_pkey_export($key, $private);

        return [$cert, $private];
    }

    #[DataProvider('algorithms')]
    public function test_sign_and_verify_round_trip(string $type): void
    {
        [$cert, $private] = $this->credentials($type);

        $cms = $this->cms->sign($this->file, $cert, $private);

        $this->assertStringStartsWith('-----BEGIN CMS-----', $cms);

        $result = $this->cms->verify($this->file, $cms);

        $this->assertTrue($result['valid']);
        $this->assertCount(1, $result['signers']);
        $this->assertSame(
            openssl_pkey_get_details(openssl_pkey_get_public($cert))['key'],
            $this->cms->publicKeyFromCertificate($result['signers'][0])
        );
    }

    public static function algorithms(): array
    {
        return ['rsa' => ['rsa'], 'ecdsa' => ['ecdsa']];
    }

    public function test_modified_document_is_rejected_but_signer_is_still_identified(): void
    {
        [$cert, $private] = $this->credentials();
        $cms = $this->cms->sign($this->file, $cert, $private);

        file_put_contents($this->file, 'contenu FALSIFIE');
        $result = $this->cms->verify($this->file, $cms);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['signers']);
    }

    public function test_der_encoding_is_verified_too(): void
    {
        [$cert, $private] = $this->credentials();
        $der = $this->cms->pemToDer($this->cms->sign($this->file, $cert, $private));

        $this->assertSame("\x30", $der[0]);
        $this->assertTrue($this->cms->looksLikeCms($der));
        $this->assertTrue($this->cms->verify($this->file, $der)['valid']);
    }

    public function test_verify_content_works_on_in_memory_data(): void
    {
        [$cert, $private] = $this->credentials();
        $cms = $this->cms->sign($this->file, $cert, $private);

        $this->assertTrue($this->cms->verifyContent('contenu à signer', $cms)['valid']);
        $this->assertFalse($this->cms->verifyContent('autre contenu', $cms)['valid']);
    }

    public function test_garbage_is_not_a_cms_and_never_validates(): void
    {
        $this->assertFalse($this->cms->looksLikeCms('{"signature":"abc"}'));
        $this->assertFalse($this->cms->looksLikeCms("\x30\x45\x02\x20raw-ecdsa-signature"));
        $this->assertFalse($this->cms->verify($this->file, 'pas un cms')['valid']);
        $this->assertFalse($this->cms->verify($this->file, "\x30\x82\x01\x00garbage")['valid']);
    }

    public function test_signing_with_mismatched_key_fails(): void
    {
        [$cert] = $this->credentials();
        [, $otherPrivate] = $this->credentials();

        $this->expectException(\RuntimeException::class);

        $this->cms->sign($this->file, $cert, $otherPrivate);
    }

    public function test_output_is_verifiable_by_the_openssl_cli(): void
    {
        if (trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('Binaire openssl indisponible.');
        }

        [$cert, $private] = $this->credentials('ecdsa');
        $p7s = tempnam(sys_get_temp_dir(), 'p7s');
        file_put_contents($p7s, $this->cms->pemToDer($this->cms->sign($this->file, $cert, $private)));

        exec(sprintf('openssl cms -verify -inform DER -in %s -content %s -noverify -binary -out /dev/null 2>&1', escapeshellarg($p7s), escapeshellarg($this->file)), $out, $code);
        @unlink($p7s);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('successful', implode("\n", $out));
    }
}
