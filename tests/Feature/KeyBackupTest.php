<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use App\Services\Crypto\CertificateAuthority;
use App\Services\Verification\SignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KeyBackupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSPHRASE = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** Clé créée par l'application (certificat émis par l'AC interne). */
    private function appKey(User $user, string $algorithm = 'ecdsa'): KeyPair
    {
        $this->actingAs($user)->post(route('keys.store'), ['key_name' => 'Ma clé', 'algorithm' => $algorithm, 'key_size' => 2048])
            ->assertRedirect(route('keys.index'));

        return KeyPair::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function export(KeyPair $key, array $overrides = [])
    {
        return $this->postJson(route('keys.export.backup', $key->id), $overrides + [
            'password' => 'password',
            'passphrase' => self::PASSPHRASE,
            'passphrase_confirmation' => self::PASSPHRASE,
        ]);
    }

    // ------------------------------------------------------------------ export

    public function test_backup_is_a_pkcs12_file_protected_by_the_passphrase(): void
    {
        $key = $this->appKey(User::factory()->create());

        $response = $this->export($key);

        $response->assertOk()->assertHeader('Content-Type', 'application/x-pkcs12');
        $this->assertStringContainsString('ma-cle.p12', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $bundle = [];
        $this->assertTrue(openssl_pkcs12_read($response->getContent(), $bundle, self::PASSPHRASE));
        $this->assertSame($key->private_key, $bundle['pkey']);
        $this->assertSame($key->certificate_data, $bundle['cert']);
        // Le certificat de l'AC est inclus : la chaîne est complète pour qui importe ailleurs.
        $this->assertSame(trim((new CertificateAuthority)->certificate()), trim($bundle['extracerts'][0]));

        $this->assertFalse(@openssl_pkcs12_read($response->getContent(), $bundle, 'mauvaise phrase secrete'));
    }

    public function test_backup_requires_the_account_password_and_a_strong_confirmed_passphrase(): void
    {
        $key = $this->appKey(User::factory()->create());

        $this->export($key, ['password' => 'faux'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->export($key, ['passphrase' => 'court', 'passphrase_confirmation' => 'court'])->assertStatus(422)->assertJsonValidationErrors('passphrase');
        $this->export($key, ['passphrase_confirmation' => 'autre phrase secrete'])->assertStatus(422)->assertJsonValidationErrors('passphrase');
    }

    public function test_backup_of_someone_elses_key_is_refused(): void
    {
        $key = $this->appKey(User::factory()->create());

        $this->actingAs(User::factory()->create());
        $this->export($key)->assertNotFound();
    }

    public function test_backup_endpoint_is_rate_limited(): void
    {
        $key = $this->appKey(User::factory()->create());

        for ($i = 0; $i < 5; $i++) {
            $this->export($key, ['password' => 'faux']);
        }

        $this->export($key)->assertStatus(429);
    }

    // ------------------------------------------------------------------ import

    private function backupFile(KeyPair $key, string $name = 'backup.p12'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->export($key)->getContent());
    }

    private function import(array $data)
    {
        return $this->post(route('keys.import'), $data + ['key_name' => 'Restaurée', 'passphrase' => self::PASSPHRASE]);
    }

    /** @return array<string, array{0: string}> */
    public static function algorithms(): array
    {
        return ['ecdsa' => ['ecdsa'], 'rsa' => ['rsa']];
    }

    #[DataProvider('algorithms')]
    public function test_a_backup_can_be_restored_for_a_user_who_lost_the_key(string $algorithm): void
    {
        $user = User::factory()->create();
        $original = $this->appKey($user, $algorithm);
        $file = $this->backupFile($original);
        $original->delete(); // la clé a disparu (base restaurée, APP_KEY perdue...)

        $response = $this->import(['file' => $file]);

        $restored = KeyPair::where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('keys.show', $restored->id));
        $this->assertSame('Restaurée', $restored->key_name);
        $this->assertSame($algorithm, $restored->algorithm_used);
        $this->assertSame($original->public_key, $restored->public_key);
        $this->assertSame($original->private_key, $restored->private_key);
        $this->assertSame($original->certificate_data, $restored->certificate_data);
        $this->assertTrue($restored->is_active);
        $this->assertFalse($restored->is_default);

        // Toujours émis par l'AC : la confiance est conservée.
        $this->assertSame('trusted', (new CertificateAuthority)->validateChain($restored->certificate_data)['status']);
    }

    public function test_a_restored_key_can_sign_documents(): void
    {
        $user = User::factory()->create();
        $original = $this->appKey($user);
        $file = $this->backupFile($original);
        $original->delete();
        $this->import(['file' => $file]);

        $this->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'après restauration'),
            'key_pair_id' => KeyPair::firstOrFail()->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertRedirect();

        $this->assertTrue((new SignatureVerifier)->verifySignature(Signature::firstOrFail()->load('keyPair'), 'après restauration'));
    }

    public function test_importing_a_key_you_already_have_is_rejected(): void
    {
        $user = User::factory()->create();
        $key = $this->appKey($user);

        $this->import(['file' => $this->backupFile($key)])->assertSessionHasErrors('file');

        $this->assertSame(1, KeyPair::where('user_id', $user->id)->count());
    }

    public function test_import_rejects_a_wrong_passphrase_and_garbage_files(): void
    {
        $user = User::factory()->create();
        $key = $this->appKey($user);
        $file = $this->backupFile($key);
        $key->delete();

        $this->import(['file' => $file, 'passphrase' => 'mauvaise phrase secrete'])->assertSessionHasErrors('passphrase');
        $this->import(['file' => UploadedFile::fake()->createWithContent('x.p12', 'pas un pkcs12')])->assertSessionHasErrors('passphrase');
        $this->import(['file' => UploadedFile::fake()->create('gros.p12', 200)])->assertSessionHasErrors('file');

        $this->assertSame(0, KeyPair::count());
    }

    public function test_import_rejects_an_expired_certificate(): void
    {
        $user = User::factory()->create();
        $key = $this->appKey($user);
        $file = $this->backupFile($key);
        $key->delete();

        $this->travel(2)->years();

        $this->import(['file' => $file])->assertSessionHasErrors('file');
    }

    public function test_import_rejects_unsupported_key_types(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $weak = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        $csr = openssl_csr_new(['commonName' => 'faible'], $weak);
        openssl_pkcs12_export(openssl_csr_sign($csr, null, $weak, 30), $p12, $weak, self::PASSPHRASE);

        $this->import(['file' => UploadedFile::fake()->createWithContent('weak.p12', $p12)])->assertSessionHasErrors('file');
    }

    public function test_an_external_self_signed_key_can_be_imported_and_is_reported_as_such(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $external = KeyPair::factory()->make(); // certificat auto-signé, non émis par l'AC
        openssl_pkcs12_export($external->certificate_data, $p12, $external->private_key, self::PASSPHRASE);

        $this->import(['file' => UploadedFile::fake()->createWithContent('ext.p12', $p12)])->assertSessionHasNoErrors();

        $imported = KeyPair::firstOrFail();
        $this->assertSame('self_signed', (new CertificateAuthority)->validateChain($imported->certificate_data)['status']);
    }

    // ------------------------------------------------------------------ update

    public function test_update_cannot_change_the_certificate_or_its_issuer(): void
    {
        $user = User::factory()->create();
        $key = $this->appKey($user);

        $this->put(route('keys.update', $key->id), [
            'key_name' => 'Renommée',
            'certificate_data' => 'FAUX',
            'certificate_issuer' => 'Faux émetteur',
        ])->assertRedirect(route('keys.show', $key->id));

        $fresh = $key->fresh();
        $this->assertSame('Renommée', $fresh->key_name);
        $this->assertSame($key->certificate_data, $fresh->certificate_data);
        $this->assertSame('CRYPTOR Internal CA', $fresh->certificate_issuer);
    }

    public function test_key_validity_cannot_exceed_its_certificate(): void
    {
        $user = User::factory()->create();
        $key = $this->appKey($user); // certificat de 365 jours

        $this->put(route('keys.update', $key->id), ['valid_to' => now()->addYears(5)->toDateString()])
            ->assertSessionHasErrors('valid_to');

        $this->put(route('keys.update', $key->id), ['valid_to' => now()->addDays(30)->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame(now()->addDays(30)->toDateString(), $key->fresh()->valid_to->toDateString());

        // Effacer la date ramène à celle du certificat (pas de clé « éternelle »).
        $this->put(route('keys.update', $key->id), ['valid_to' => ''])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(365, now()->diffInDays($key->fresh()->valid_to), 1);
    }

    public function test_a_revoked_key_cannot_be_modified(): void
    {
        $user = User::factory()->create();
        $key = $this->appKey($user);
        $key->update(['is_revoked' => true, 'is_active' => false, 'revoked_at' => now()]);

        $this->put(route('keys.update', $key->id), ['key_name' => 'Nouveau nom', 'is_active' => true])
            ->assertSessionHas('error');

        $fresh = $key->fresh();
        $this->assertSame('Ma clé', $fresh->key_name);
        $this->assertFalse($fresh->is_active);
    }
}
