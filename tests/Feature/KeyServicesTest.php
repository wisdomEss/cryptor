<?php

namespace Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Models\KeyPair;
use App\Models\User;
use App\Services\Keys\KeyFingerprint;
use App\Services\Keys\KeyGenerator;
use App\Services\Keys\KeyManager;
use App\Services\Keys\SshKeyExporter;
use App\Support\ClientContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KeyServicesTest extends TestCase
{
    use RefreshDatabase;

    private function cli(string $binary): bool
    {
        return trim((string) shell_exec('command -v '.escapeshellarg($binary))) !== '';
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function keyTypes(): array
    {
        return ['rsa 2048' => ['rsa', 2048], 'rsa 4096' => ['rsa', 4096], 'ecdsa p-256' => ['ecdsa', 256]];
    }

    // ------------------------------------------------------------ KeyGenerator

    #[DataProvider('keyTypes')]
    public function test_generator_produces_usable_keys(string $algorithm, int $size): void
    {
        $pair = (new KeyGenerator)->generate($algorithm, $size);

        $this->assertSame($size, $pair['key_size']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $pair['public_key']);
        $this->assertNotFalse(openssl_pkey_get_private($pair['private_key']));
        $details = openssl_pkey_get_details(openssl_pkey_get_private($pair['private_key']));
        $this->assertSame($algorithm === 'rsa' ? OPENSSL_KEYTYPE_RSA : OPENSSL_KEYTYPE_EC, $details['type']);
        $this->assertSame($pair['public_key'], $details['key']);
    }

    public function test_generator_rejects_weak_sizes_and_unknown_algorithms(): void
    {
        $this->expectException(UserFacingException::class);
        (new KeyGenerator)->generate('rsa', 1024);
    }

    public function test_generator_rejects_an_unknown_algorithm(): void
    {
        $this->expectException(UserFacingException::class);
        (new KeyGenerator)->generate('dsa', 2048);
    }

    // ---------------------------------------------------------- KeyFingerprint

    #[DataProvider('keyTypes')]
    public function test_fingerprint_matches_the_openssl_der_sha256(string $algorithm, int $size): void
    {
        if (! $this->cli('openssl')) {
            $this->markTestSkipped('Binaire openssl indisponible.');
        }

        $pair = (new KeyGenerator)->generate($algorithm, $size);
        $file = tempnam(sys_get_temp_dir(), 'pub');
        file_put_contents($file, $pair['public_key']);

        $expected = trim((string) shell_exec(sprintf('openssl pkey -pubin -in %s -outform DER 2>/dev/null | sha256sum | cut -d" " -f1', escapeshellarg($file))));
        @unlink($file);

        $this->assertSame(64, strlen($expected));
        $this->assertSame(implode(':', str_split(strtoupper($expected), 2)), KeyFingerprint::of($pair['public_key']));
    }

    public function test_short_fingerprint_is_a_prefix_of_the_full_one_and_ignores_pem_layout(): void
    {
        $pem = (new KeyGenerator)->generate('ecdsa')['public_key'];
        $crlf = str_replace("\n", "\r\n", $pem);

        $this->assertMatchesRegularExpression('/^([0-9A-F]{2}:){7}[0-9A-F]{2}$/', KeyFingerprint::short($pem));
        $this->assertStringStartsWith(KeyFingerprint::short($pem), KeyFingerprint::of($pem));
        $this->assertSame(KeyFingerprint::of($pem), KeyFingerprint::of($crlf));
        $this->assertNotSame(KeyFingerprint::of($pem), KeyFingerprint::of((new KeyGenerator)->generate('ecdsa')['public_key']));
    }

    public function test_every_page_shows_the_same_fingerprint_for_a_key(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();
        $short = KeyFingerprint::short($key->public_key);

        $this->actingAs($user);
        $this->get(route('keys.index'))->assertInertia(fn ($p) => $p->where('keys.0.fingerprint', $short));
        $this->get(route('keys.show', $key->id))->assertInertia(fn ($p) => $p->where('keyData.fingerprint', KeyFingerprint::of($key->public_key)));
        $this->get(route('signatures.create'))->assertInertia(fn ($p) => $p->where('keys.0.fingerprint', $short));
    }

    // ------------------------------------------------------------ SshKeyExporter

    #[DataProvider('keyTypes')]
    public function test_exported_ssh_keys_are_accepted_by_openssh(string $algorithm, int $size): void
    {
        if (! $this->cli('ssh-keygen')) {
            $this->markTestSkipped('ssh-keygen indisponible.');
        }

        $pem = (new KeyGenerator)->generate($algorithm, $size)['public_key'];
        $dir = sys_get_temp_dir().'/ssh-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/ours.pub", (new SshKeyExporter)->export($pem, 'ma-cle'));
        file_put_contents("$dir/key.pem", $pem);

        // OpenSSH accepte notre fichier...
        exec('ssh-keygen -l -f '.escapeshellarg("$dir/ours.pub").' 2>&1', $ours, $code);
        $this->assertSame(0, $code, implode("\n", $ours));
        $this->assertStringContainsString('ma-cle', implode("\n", $ours));

        // ... et c'est exactement la même clé que la conversion de référence d'OpenSSH.
        exec('ssh-keygen -i -m PKCS8 -f '.escapeshellarg("$dir/key.pem").' 2>/dev/null', $reference);
        $this->assertSame(trim($reference[0]), implode(' ', array_slice(explode(' ', trim(file_get_contents("$dir/ours.pub"))), 0, 2)));

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    public function test_ssh_comment_cannot_inject_extra_lines(): void
    {
        $pem = (new KeyGenerator)->generate('ecdsa')['public_key'];

        $line = (new SshKeyExporter)->export($pem, "innocent\nssh-rsa AAAAFAKE attacker@evil\r\n");

        $this->assertSame(1, substr_count($line, "\n"));
        $this->assertStringEndsWith("\n", $line);
        $this->assertSame('key', explode(' ', trim((new SshKeyExporter)->export($pem, "\n\n")))[2]);
    }

    public function test_ssh_export_refuses_unsupported_keys(): void
    {
        $p384 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);

        $this->expectException(UserFacingException::class);

        (new SshKeyExporter)->export(openssl_pkey_get_details($p384)['key'], 'x');
    }

    #[DataProvider('keyTypes')]
    public function test_ssh_export_route_serves_a_valid_file_for_every_key_type(string $algorithm, int $size): void
    {
        $user = User::factory()->create();
        Storage::fake('local');
        $this->actingAs($user)->post(route('keys.store'), ['key_name' => 'Ma clé', 'algorithm' => $algorithm, 'key_size' => $algorithm === 'rsa' ? $size : 2048]);

        $response = $this->get(route('keys.export.ssh', KeyPair::firstOrFail()->id));

        $response->assertOk();
        $this->assertMatchesRegularExpression('/^(ssh-rsa|ecdsa-sha2-nistp256) [A-Za-z0-9+\/=]+ Ma clé\n$/', $response->getContent());
    }

    // ---------------------------------------------------------------- KeyManager

    private function manager(): KeyManager
    {
        return app(KeyManager::class);
    }

    private function messageOf(callable $action): string
    {
        try {
            $action();
        } catch (UserFacingException $e) {
            return $e->getMessage();
        }

        $this->fail('Une UserFacingException était attendue.');
    }

    public function test_creating_a_default_key_demotes_the_previous_default(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $first = $this->manager()->create($user, ['key_name' => 'A', 'algorithm' => 'ecdsa', 'set_as_default' => true], new ClientContext('1.2.3.4', 'UA'));
        $second = $this->manager()->create($user, ['key_name' => 'B', 'algorithm' => 'ecdsa', 'set_as_default' => true], new ClientContext);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->is_default);
        $this->assertSame('1.2.3.4', $first->created_by_ip);
        $this->assertSame('UA', $first->created_by_user_agent);
        $this->assertSame(365, (int) round(now()->diffInDays($first->valid_to)));
    }

    public function test_business_rules_have_clear_messages(): void
    {
        $user = User::factory()->create();
        $default = KeyPair::factory()->for($user)->create(['is_default' => true]);
        $other = KeyPair::factory()->for($user)->create();
        $revoked = KeyPair::factory()->for($user)->create(['is_revoked' => true, 'is_active' => false]);
        $inactive = KeyPair::factory()->for($user)->create(['is_active' => false]);

        $this->assertSame('Cannot activate a revoked key.', $this->messageOf(fn () => $this->manager()->activate($revoked)));
        $this->assertSame('Cannot deactivate default key. Set another key as default first.', $this->messageOf(fn () => $this->manager()->deactivate($default)));
        $this->assertSame('Cannot set revoked or inactive key as default.', $this->messageOf(fn () => $this->manager()->setDefault($inactive)));
        $this->assertSame('Cannot set revoked or inactive key as default.', $this->messageOf(fn () => $this->manager()->setDefault($revoked)));
        $this->assertSame('Cannot revoke default key. Set another key as default first.', $this->messageOf(fn () => $this->manager()->revoke($default)));
        $this->assertSame('Cannot delete default key. Set another key as default first.', $this->messageOf(fn () => $this->manager()->delete($default)));
        $this->assertSame('A revoked key cannot be modified.', $this->messageOf(fn () => $this->manager()->update($revoked, ['key_name' => 'x'])));

        // Une règle violée ne modifie rien.
        $this->assertTrue($default->fresh()->is_default);
        $this->assertTrue($default->fresh()->is_active);

        // Les chemins valides fonctionnent.
        $this->manager()->setDefault($other);
        $this->assertTrue($other->fresh()->is_default);
        $this->assertFalse($default->fresh()->is_default);
        $this->manager()->revoke($default->fresh(), 'compromise');
        $this->assertTrue($default->fresh()->is_revoked);
        $this->assertSame('compromise', $default->fresh()->revocation_reason);
        $this->assertNotNull($default->fresh()->revoked_at);
    }

    public function test_the_only_key_can_be_deleted_even_if_default(): void
    {
        $key = KeyPair::factory()->create(['is_default' => true]);

        $this->manager()->delete($key);

        $this->assertModelMissing($key);
    }

    public function test_unsetting_the_default_hands_over_to_another_usable_key(): void
    {
        $user = User::factory()->create();
        $default = KeyPair::factory()->for($user)->create(['is_default' => true]);
        KeyPair::factory()->for($user)->create(['is_revoked' => true, 'is_active' => false]); // pas candidate
        $candidate = KeyPair::factory()->for($user)->create();

        $this->manager()->update($default, ['is_default' => false]);

        $this->assertFalse($default->fresh()->is_default);
        $this->assertTrue($candidate->fresh()->is_default);
    }

    public function test_validity_beyond_the_certificate_is_reported_on_the_field(): void
    {
        $key = KeyPair::factory()->create(); // certificat de 30 jours

        try {
            $this->manager()->update($key, ['valid_to' => now()->addYear()->toDateString()]);
            $this->fail('Une UserFacingException était attendue.');
        } catch (UserFacingException $e) {
            $this->assertSame('valid_to', $e->field);
        }
    }
}
