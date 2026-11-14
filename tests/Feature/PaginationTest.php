<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les listes sont paginées côté serveur ; les compteurs affichés en tête viennent de
 * la base (ils portaient avant sur le tableau complet envoyé au navigateur).
 */
class PaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_list_is_paginated_and_counts_cover_every_page(): void
    {
        $user = User::factory()->create();
        KeyPair::factory()->count(20)->for($user)->create(['is_active' => true, 'is_revoked' => false]);

        $page = $this->actingAs($user)->get(route('keys.index'))->assertOk();
        $props = $page->viewData('page')['props'];

        $this->assertCount(15, $props['keys']);
        $this->assertSame(2, $props['pagination']['last_page']);
        $this->assertSame(20, $props['pagination']['total']);
        $this->assertSame(20, $props['stats']['total']);
        $this->assertSame(20, $props['stats']['active']);

        $second = $this->actingAs($user)->get(route('keys.index', ['page' => 2]))->assertOk();
        $this->assertCount(5, $second->viewData('page')['props']['keys']);
    }

    public function test_signature_list_is_paginated_with_server_side_counts(): void
    {
        $user = User::factory()->create();
        Signature::factory()->count(18)->for($user, 'signer')->create(['validation_status' => 'pending']);
        Signature::factory()->count(2)->for($user, 'signer')->create(['validation_status' => 'valid']);

        $props = $this->actingAs($user)->get(route('signatures.index'))->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(15, $props['signatures']);
        $this->assertSame(20, $props['pagination']['total']);
        $this->assertSame(20, $props['stats']['total']);
        $this->assertSame(18, $props['stats']['pending']);
        $this->assertSame(2, $props['stats']['valid']);
    }

    public function test_filtered_signature_lists_keep_their_own_counts(): void
    {
        $user = User::factory()->create();
        Signature::factory()->count(3)->for($user, 'signer')->create(['validation_status' => 'pending']);
        Signature::factory()->count(4)->for($user, 'signer')->create(['validation_status' => 'valid']);

        $props = $this->actingAs($user)->get(route('signatures.pending'))->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('pending', $props['type']);
        $this->assertSame(3, $props['stats']['total']);
        $this->assertCount(3, $props['signatures']);
    }

    public function test_revoked_key_list_is_paginated(): void
    {
        $user = User::factory()->create();
        KeyPair::factory()->count(17)->for($user)->create([
            'is_revoked' => true,
            'is_active' => false,
            'is_default' => false,
            'revoked_at' => now(),
        ]);

        $props = $this->actingAs($user)->get(route('keys.revoked'))->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(15, $props['keys']);
        $this->assertSame(17, $props['pagination']['total']);
    }

    public function test_a_user_never_sees_another_users_rows_in_a_paginated_list(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Signature::factory()->count(5)->for($other, 'signer')->create();
        Signature::factory()->count(2)->for($user, 'signer')->create();

        $props = $this->actingAs($user)->get(route('signatures.index'))->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(2, $props['pagination']['total']);
        $this->assertSame(2, $props['stats']['total']);
    }

    public function test_signature_list_no_longer_ships_the_cms_container(): void
    {
        $user = User::factory()->create();
        Signature::factory()->for($user, 'signer')->create();

        $props = $this->actingAs($user)->get(route('signatures.index'))->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('signature_value', $props['signatures'][0]);
    }
}
