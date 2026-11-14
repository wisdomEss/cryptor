<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Garde-fou de performance : le nombre de requêtes SQL d'une page de liste ne doit pas
 * dépendre du nombre de lignes affichées (sinon on a réintroduit un N+1).
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_signature_list_does_not_grow_with_the_number_of_signatures(): void
    {
        $user = User::factory()->create();

        $this->assertSame(
            $this->queriesForSignatureIndex($user, 3),
            $this->queriesForSignatureIndex($user, 12),
            'La liste des signatures fait une requête par ligne : le N+1 est de retour.'
        );
    }

    public function test_dashboard_stays_under_its_query_budget(): void
    {
        $user = User::factory()->create();
        KeyPair::factory()->count(3)->for($user)->create();
        Signature::factory()->count(5)->for($user, 'signer')->create();

        $queries = $this->countQueries(fn () => $this->actingAs($user)->get(route('dashboard'))->assertOk());

        $this->assertLessThanOrEqual(11, $queries, "Le tableau de bord fait {$queries} requêtes.");
    }

    private function queriesForSignatureIndex(User $user, int $signatures): int
    {
        Signature::where('signer_id', $user->id)->delete();
        Signature::factory()->count($signatures)->for($user, 'signer')->create();

        return $this->countQueries(
            fn () => $this->actingAs($user)->get(route('signatures.index'))->assertOk()
        );
    }

    private function countQueries(callable $action): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $action();

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }
}
