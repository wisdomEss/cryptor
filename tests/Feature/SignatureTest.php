<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_sign_a_document_and_verify_it()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $file = UploadedFile::fake()->create('contract.txt', 10, 'text/plain');

        $signResponse = $this->actingAs($user)->post(route('signatures.store'), [
            'file' => $file,
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ]);

        $signature = Signature::firstOrFail();
        $signResponse->assertRedirect(route('signatures.show', $signature->id));

        // Le fichier détaché doit être une vraie signature binaire, pas du JSON encodé en base64.
        $this->assertNotNull($signature->signature_file_path);
        Storage::disk('local')->assertExists($signature->signature_file_path);

        // Vérification via le flux "document interne"
        $verifyResponse = $this->actingAs($user)->post(route('verifications.store'), [
            'file' => UploadedFile::fake()->createWithContent('contract.txt', file_get_contents($file->getRealPath())),
        ]);

        $verifyResponse->assertOk();
    }

    public function test_pending_signatures_route_is_not_captured_by_show_route()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('signatures.pending'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('signatures/Index'));
    }

    public function test_validate_route_reuses_update_logic()
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();
        $signature = Signature::factory()->for($user, 'signer')->create([
            'key_pair_id' => $key->id,
            'validation_status' => 'pending',
        ]);

        $response = $this->actingAs($user)->patch(route('signatures.validate', $signature->id), [
            'validation_notes' => 'Looks good',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('signatures', [
            'id' => $signature->id,
            'validation_status' => 'valid',
            'is_valid' => true,
        ]);
    }

    public function test_user_can_delete_a_signature()
    {
        $user = User::factory()->create();
        $signature = Signature::factory()->for($user, 'signer')->create();

        $response = $this->actingAs($user)->delete(route('signatures.destroy', $signature->id));

        $response->assertRedirect(route('signatures.index'));
        $this->assertDatabaseMissing('signatures', ['id' => $signature->id]);
    }

    public function test_signing_produces_a_standard_cms_and_a_der_p7s_file()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $this->actingAs($user)->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'texte signé'),
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertRedirect();

        $signature = Signature::firstOrFail();

        // En base : CMS PEM, plus de JSON maison.
        $this->assertStringStartsWith('-----BEGIN CMS-----', $signature->signature_value);
        $this->assertNull(json_decode($signature->signature_value, true));

        // Fichier .p7s : CMS en DER, vérifiable par OpenSSL contre le document d'origine.
        $der = Storage::disk('local')->get($signature->signature_file_path);
        $this->assertSame("\x30", $der[0]);
        $this->assertMatchesRegularExpression('/-'.$signature->id.'\.p7s$/', $signature->signature_file_path);

        $contentFile = tempnam(sys_get_temp_dir(), 'doc');
        $sigFile = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($contentFile, 'texte signé');
        file_put_contents($sigFile, $der);

        $this->assertTrue(openssl_cms_verify(
            $contentFile,
            OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY,
            null, [], null, null, null, $sigFile, OPENSSL_ENCODING_DER
        ));

        @unlink($contentFile);
        @unlink($sigFile);
    }

    public function test_pdf_format_also_produces_a_real_cms_signature()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $this->actingAs($user)->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('rapport.pdf', '%PDF-1.4 faux pdf'),
            'key_pair_id' => $key->id,
            'signature_format' => 'pdf',
            'include_visual' => true,
            'expires_in_days' => 0,
        ])->assertRedirect();

        $signature = Signature::firstOrFail();

        $this->assertSame('pdf', $signature->signature_format);
        $this->assertStringStartsWith('-----BEGIN CMS-----', $signature->signature_value);
        $this->assertNotNull($signature->signature_file_path);
    }

    public function test_signing_fails_cleanly_when_the_key_has_no_certificate()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create(['certificate_data' => null]);

        $this->actingAs($user)->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'texte'),
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertSessionHasErrors('error');

        $this->assertSame(0, Signature::count());
    }

    public function test_detached_signature_download_returns_the_der_file_and_is_owner_only()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $this->actingAs($user)->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'texte'),
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ]);

        $signature = Signature::firstOrFail();

        $response = $this->get(route('signatures.download', $signature->id));
        $response->assertOk();
        $this->assertStringContainsString('pkcs7-signature', $response->headers->get('Content-Type'));

        $this->actingAs(User::factory()->create())
            ->get(route('signatures.download', $signature->id))
            ->assertNotFound();
    }
}
