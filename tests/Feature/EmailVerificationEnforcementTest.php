<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un compte dont l'adresse n'est pas confirmée ne peut utiliser ni clés, ni signatures, ni vérifications.
 */
class EmailVerificationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> [méthode, route] */
    public static function protectedRoutes(): array
    {
        return [
            'dashboard' => ['get', 'dashboard'],
            'liste des clés' => ['get', 'keys.index'],
            'nouvelle clé' => ['get', 'keys.create'],
            'création de clé' => ['post', 'keys.store'],
            'import de clé' => ['post', 'keys.import'],
            'liste des signatures' => ['get', 'signatures.index'],
            'nouvelle signature' => ['get', 'signatures.create'],
            'signature' => ['post', 'signatures.store'],
            'vérifications' => ['get', 'verifications.index'],
            'nouvelle vérification' => ['get', 'verifications.create'],
            'vérification' => ['post', 'verifications.store'],
            'réglages du mot de passe' => ['get', 'user-password.edit'],
            'réglages 2FA' => ['get', 'two-factor.show'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_unverified_users_are_sent_to_the_verification_page(string $method, string $route): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->{$method}(route($route))->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_downloads_are_blocked_too(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('files.download', 1))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_json_clients_get_a_403_instead_of_a_redirect(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->getJson(route('keys.index'))
            ->assertForbidden();
    }

    public function test_verified_users_are_not_affected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('keys.index'))->assertOk();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_unverified_users_can_still_fix_their_account_and_log_out(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $this->get(route('verification.notice'))->assertOk();
        $this->get(route('profile.edit'))->assertOk();
        $this->post(route('verification.send'))->assertRedirect();
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_public_features_stay_public(): void
    {
        $this->get(route('home'))->assertOk();
        $this->get(route('verifications.public'))->assertOk();
        $this->get(route('pki.ca'))->assertOk();
        $this->getJson(route('pki.revocations'))->assertOk();
        $this->postJson(route('api.public.verify'), ['token' => 'inconnu'])->assertOk();
    }

    public function test_the_requirement_can_be_switched_off_for_private_instances(): void
    {
        config(['cryptor.require_verified_email' => false]);

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('keys.index'))
            ->assertOk();
    }

    public function test_full_journey_registration_then_confirmation(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Alice',
            'username' => 'alice',
            'email' => 'alice@example.org',
            'password' => 'Un mot de passe solide 42!',
            'password_confirmation' => 'Un mot de passe solide 42!',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'alice@example.org')->firstOrFail();

        // Le lien de confirmation est envoyé automatiquement, et le compte est bloqué.
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get(route('keys.index'))->assertRedirect(route('verification.notice'));

        // Un clic sur le lien signé débloque le compte.
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get(route('keys.index'))->assertOk();
    }

    public function test_changing_the_email_address_requires_a_new_confirmation(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('keys.index'))->assertOk();

        $this->patch(route('profile.update'), ['name' => $user->name, 'email' => 'nouvelle@example.org'])
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('nouvelle@example.org', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get(route('keys.index'))->assertRedirect(route('verification.notice'));
    }

    public function test_updating_the_name_alone_keeps_the_account_verified_and_sends_nothing(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Nouveau nom', 'email' => $user->email]);

        $this->assertNotNull($user->fresh()->email_verified_at);
        Notification::assertNothingSent();
    }

    public function test_the_migration_lets_existing_accounts_in_but_not_future_ones(): void
    {
        $old = User::factory()->unverified()->create();
        $alreadyVerified = User::factory()->create(['email_verified_at' => now()->subYear()]);
        $before = $alreadyVerified->fresh()->email_verified_at;

        (include database_path('migrations/2026_11_12_100000_mark_existing_users_as_verified.php'))->up();

        $this->assertNotNull($old->fresh()->email_verified_at);
        $this->assertEquals($before, $alreadyVerified->fresh()->email_verified_at, 'Une date de confirmation existante ne doit pas être écrasée.');

        // Un compte créé après la migration reste à confirmer.
        User::factory()->unverified()->create();
        $this->assertSame(1, DB::table('users')->whereNull('email_verified_at')->count());
    }

    // ------------------------------------------------------------ commande

    public function test_verify_user_command(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'bob@example.org']);

        $this->artisan('cryptor:verify-user', ['email' => 'BOB@example.org'])->expectsOutputToContain('confirmé')->assertSuccessful();
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->artisan('cryptor:verify-user', ['email' => 'bob@example.org'])->expectsOutputToContain('déjà confirmé')->assertSuccessful();
        $this->artisan('cryptor:verify-user', ['email' => 'inconnu@example.org'])->assertFailed();
        $this->artisan('cryptor:verify-user')->assertFailed();
    }

    public function test_verify_user_command_can_confirm_everyone(): void
    {
        User::factory()->unverified()->count(3)->create();

        $this->artisan('cryptor:verify-user', ['--all' => true])->expectsOutputToContain('3 compte(s)')->assertSuccessful();

        $this->assertSame(0, User::whereNull('email_verified_at')->count());
    }
}
