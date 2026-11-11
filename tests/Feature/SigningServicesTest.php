<?php

namespace Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Models\FileSigned;
use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Models\User;
use App\Services\Signing\DocumentSigner;
use App\Services\Signing\SignatureManager;
use App\Services\Signing\SigningRequest;
use App\Support\ClientContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTimestampServer;
use Tests\TestCase;

class SigningServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function signer(): DocumentSigner
    {
        return app(DocumentSigner::class);
    }

    private function document(string $name = 'contrat.pdf', string $content = 'contenu'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function sign(User $user, KeyPair $key, ?UploadedFile $file = null, ?SigningRequest $options = null): Signature
    {
        return $this->signer()->sign($user, $key, $file ?? $this->document(), $options ?? new SigningRequest, new ClientContext('10.0.0.1', 'TestAgent'));
    }

    // ------------------------------------------------------------ DocumentSigner

    public function test_signing_records_everything_and_stores_two_files(): void
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $signature = $this->sign($user, $key, $this->document('contrat.pdf', 'contenu'), new SigningRequest(reason: 'Accord', location: 'Paris', contact: 'a@b.c', expiresInDays: 30));

        $file = $signature->file;
        $this->assertSame(hash('sha256', 'contenu'), $file->file_hash);
        $this->assertSame(hash('sha512', 'contenu'), $file->metadata['sha512']);
        $this->assertSame(md5('contenu'), $file->metadata['md5']);
        $this->assertSame('10.0.0.1', $file->upload_ip);
        $this->assertSame('fully_signed', $file->status);
        $this->assertSame(1, $file->signatures_count);
        $this->assertEqualsWithDelta(30, now()->diffInDays($file->expires_at), 1);
        $this->assertSame(['Accord', 'Paris', 'a@b.c'], [$signature->signing_reason, $signature->signing_location, $signature->contact_info]);
        $this->assertSame('pending', $signature->validation_status);

        Storage::disk('local')->assertExists($file->getRawOriginal('file_path'));
        Storage::disk('local')->assertExists($signature->signature_file_path);
        $this->assertSame(1, $key->fresh()->use_count);
        $this->assertNotNull($key->fresh()->last_used);

        $initial = SignatureVerification::firstOrFail();
        $this->assertTrue($initial->result);
        $this->assertSame($user->id, $initial->verifier_id);
        $this->assertSame('10.0.0.1', $initial->verifier_ip);
    }

    public function test_the_stored_file_name_is_sanitized_whatever_the_client_sent(): void
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $weird = $this->sign($user, $key, $this->document('rapport.p<h>p', 'a'));
        $none = $this->sign($user, $key, $this->document('LISEZMOI', 'b'));

        $this->assertMatchesRegularExpression('/^\d+_[0-9a-f]+\.php$/', $weird->file->file_name);
        $this->assertMatchesRegularExpression('/^\d+_[0-9a-f]+$/', $none->file->file_name); // pas de point final
        $this->assertSame('rapport.p<h>p', $weird->file->original_file_name); // le nom d'origine est conservé tel quel pour l'affichage
    }

    public function test_two_documents_with_the_same_name_keep_distinct_detached_signatures(): void
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $a = $this->sign($user, $key, $this->document('contrat.pdf', 'version 1'));
        $b = $this->sign($user, $key, $this->document('contrat.pdf', 'version 2'));

        $this->assertNotSame($a->signature_file_path, $b->signature_file_path);
        Storage::disk('local')->assertExists($a->signature_file_path);
        Storage::disk('local')->assertExists($b->signature_file_path);
    }

    public function test_unusable_keys_are_refused_before_anything_is_written(): void
    {
        $user = User::factory()->create();
        $cases = [
            'revoked' => [KeyPair::factory()->for($user)->create(['is_revoked' => true]), 'not valid or has expired'],
            'inactive' => [KeyPair::factory()->for($user)->create(['is_active' => false]), 'not valid or has expired'],
            'expired' => [KeyPair::factory()->for($user)->create(['valid_to' => now()->subDay()]), 'not valid or has expired'],
            'no certificate' => [KeyPair::factory()->for($user)->create(['certificate_data' => null]), 'no certificate'],
        ];

        foreach ($cases as $label => [$key, $message]) {
            try {
                $this->sign($user, $key);
                $this->fail("{$label} : une UserFacingException était attendue.");
            } catch (UserFacingException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $label);
            }
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, FileSigned::count() + Signature::count());
    }

    public function test_a_failure_in_the_database_step_leaves_no_orphan_file(): void
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        // Échec tardif : après l'écriture du document ET du .p7s.
        SignatureVerification::creating(fn () => throw new QueryException('mysql', 'insert ...', [], new \Exception('boom')));

        try {
            $this->sign($user, $key);
            $this->fail('Une exception était attendue.');
        } catch (QueryException) {
            // attendu
        }

        $this->assertSame([], Storage::disk('local')->allFiles(), 'Document et .p7s doivent être supprimés.');
        $this->assertSame(0, FileSigned::count() + Signature::count() + SignatureVerification::count(), 'La transaction doit être annulée.');
        $this->assertSame(0, $key->fresh()->use_count);
    }

    public function test_the_timestamp_authority_is_called_outside_the_database_transaction(): void
    {
        if (! FakeTimestampServer::available()) {
            $this->markTestSkipped('Binaire openssl indisponible.');
        }

        $server = new FakeTimestampServer;
        config(['cryptor.tsa.url' => 'https://tsa.test/tsr', 'cryptor.tsa.ca_file' => $server->caFile]);

        $levelDuringCall = null;
        Http::fake(function (Request $request) use ($server, &$levelDuringCall) {
            $levelDuringCall = DB::transactionLevel();

            return Http::response($server->reply($request->body()), 200);
        });

        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();
        $levelBefore = DB::transactionLevel(); // RefreshDatabase ouvre déjà une transaction de test

        $signature = $this->sign($user, $key);

        $this->assertSame($levelBefore, $levelDuringCall, "L'appel réseau ne doit pas tenir de verrous SQL.");
        $this->assertSame('rfc3161', json_decode($signature->timestamp_token, true)['type']);
    }

    public function test_the_visual_signature_shows_the_real_certificate_issuer(): void
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create(['certificate_issuer' => 'CRYPTOR Internal CA']);

        $signature = $this->sign($user, $key, $this->document('a.pdf'), new SigningRequest(format: 'pdf', includeVisual: true));
        $visual = json_decode($signature->visual_signature_data, true);

        $this->assertSame('CRYPTOR Internal CA', $visual['certificate_issuer']);
        $this->assertSame($user->name, $visual['name']);

        // Sans demande, ou hors PDF : aucune donnée visuelle.
        $this->assertNull($this->sign($user, $key, $this->document('b.pdf', 'x'), new SigningRequest(format: 'pdf'))->visual_signature_data);
        $this->assertNull($this->sign($user, $key, $this->document('c.txt', 'y'), new SigningRequest(format: 'cms', includeVisual: true))->visual_signature_data);
    }

    // --------------------------------------------------------- SignatureManager

    private function manager(): SignatureManager
    {
        return app(SignatureManager::class);
    }

    public function test_validating_and_revoking_are_journaled_with_the_acting_user(): void
    {
        $user = User::factory()->create();
        $signature = $this->sign($user, KeyPair::factory()->for($user)->create());
        $client = new ClientContext('10.0.0.2', 'Agent2');

        $this->manager()->validate($signature, $user, 'RAS', $client);
        $this->assertSame('valid', $signature->fresh()->validation_status);
        $this->assertSame('RAS', $signature->fresh()->validation_message);

        $this->manager()->revoke($signature->fresh(), $user, 'Erreur de contenu', $client);
        $fresh = $signature->fresh();
        $this->assertSame('revoked', $fresh->validation_status);
        $this->assertFalse($fresh->is_valid);
        $this->assertSame('Erreur de contenu', $fresh->revocation_reason);
        $this->assertNotNull($fresh->revoked_at);

        $manual = SignatureVerification::where('verification_type', 'manual')->orderBy('id')->get();
        $this->assertCount(2, $manual);
        $this->assertSame([true, false], $manual->pluck('result')->all());
        $this->assertSame('validation', $manual[0]->verification_details['action']);
        $this->assertSame($user->name, $manual[1]->verification_details['revoked_by']);
        $this->assertSame([$user->id, $user->id], $manual->pluck('verifier_id')->all());
        $this->assertSame('10.0.0.2', $manual[0]->verifier_ip);
        $this->assertNotEmpty($manual[0]->verification_token);
    }

    public function test_deleting_the_last_signature_removes_the_document_and_the_files(): void
    {
        $user = User::factory()->create();
        $signature = $this->sign($user, KeyPair::factory()->for($user)->create());
        $document = $signature->file->getRawOriginal('file_path');
        $p7s = $signature->signature_file_path;

        $this->manager()->delete($signature);

        $this->assertModelMissing($signature);
        // FileSigned est en suppression logique : la ligne reste, le fichier disparaît du disque.
        $this->assertSoftDeleted($signature->file);
        Storage::disk('local')->assertMissing($document);
        Storage::disk('local')->assertMissing($p7s);
        $this->assertSame(0, SignatureVerification::count());
    }

    public function test_deleting_one_of_several_signatures_keeps_the_document(): void
    {
        $user = User::factory()->create();
        $first = $this->sign($user, KeyPair::factory()->for($user)->create());
        $file = $first->file;
        $second = Signature::factory()->create(['file_id' => $file->id, 'signer_id' => $user->id]);
        $file->forceFill(['signatures_count' => 2])->save();

        $this->manager()->delete($second);

        $this->assertModelExists($file->fresh());
        $this->assertSame(1, $file->fresh()->signatures_count);
        Storage::disk('local')->assertExists($file->getRawOriginal('file_path'));
        Storage::disk('local')->assertExists($first->signature_file_path);
    }

    public function test_files_survive_if_the_database_deletion_fails(): void
    {
        $user = User::factory()->create();
        $signature = $this->sign($user, KeyPair::factory()->for($user)->create());
        $document = $signature->file->getRawOriginal('file_path');
        $p7s = $signature->signature_file_path;

        Signature::deleting(fn () => throw new QueryException('mysql', 'delete ...', [], new \Exception('boom')));

        try {
            $this->manager()->delete($signature);
            $this->fail('Une exception était attendue.');
        } catch (QueryException) {
            // attendu
        }

        // Les fichiers ne sont effacés qu'après validation de la transaction.
        Storage::disk('local')->assertExists($document);
        Storage::disk('local')->assertExists($p7s);
        $this->assertModelExists($signature);
    }
}
