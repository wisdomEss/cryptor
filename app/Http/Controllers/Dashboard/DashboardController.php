<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\FileSigned;
use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Services\Keys\KeyFingerprint;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        // Un seul passage par table : les compteurs sont des agrégats conditionnels
        // (avant, le tableau de bord faisait une quinzaine de requêtes, dont trois
        // sous-requêtes `whereHas` identiques sur les vérifications).
        $keyStats = KeyPair::where('user_id', $userId)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_active = 1 and is_revoked = 0 then 1 else 0 end) as active')
            ->selectRaw('sum(case when valid_to is not null and valid_to > ? and valid_to <= ? then 1 else 0 end) as expiring', [now(), now()->addDays(30)])
            ->first();

        $totalKeys = (int) $keyStats->total;
        $activeKeys = (int) $keyStats->active;
        $expiringKeys = (int) $keyStats->expiring;

        $defaultKey = KeyPair::where('user_id', $userId)
            ->where('is_default', true)
            ->select(['id', 'key_name', 'public_key'])
            ->first();

        $signatureStats = Signature::where('signer_id', $userId)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when is_valid = 1 and validation_status = 'valid' then 1 else 0 end) as valid")
            ->selectRaw("sum(case when validation_status = 'pending' then 1 else 0 end) as pending")
            ->selectRaw('sum(case when revoked_at is not null then 1 else 0 end) as revoked')
            ->first();

        $totalSignatures = (int) $signatureStats->total;
        $validSignatures = (int) $signatureStats->valid;
        $pendingSignatures = (int) $signatureStats->pending;
        $revokedSignatures = (int) $signatureStats->revoked;

        // Statistiques des fichiers signés
        $totalFiles = FileSigned::where('uploader_id', $userId)->count();
        $recentFiles = FileSigned::where('uploader_id', $userId)
            ->select(['id', 'original_file_name', 'file_size', 'status', 'created_at'])
            ->latest()
            ->limit(5)
            ->get();

        // Statistiques des vérifications
        $verificationStats = SignatureVerification::whereHas('signature', function ($q) use ($userId) {
            $q->where('signer_id', $userId);
        })
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when result = 1 then 1 else 0 end) as successful')
            ->selectRaw('sum(case when is_public_verification = 1 then 1 else 0 end) as public_count')
            ->first();

        $totalVerifications = (int) $verificationStats->total;
        $successfulVerifications = (int) $verificationStats->successful;
        $publicVerifications = (int) $verificationStats->public_count;

        // Activité récente (dernières signatures)
        $recentActivity = Signature::with(['file:id,original_file_name'])
            ->where('signer_id', $userId)
            ->select(['id', 'file_id', 'validation_status', 'signed_at', 'is_valid'])
            ->latest('signed_at')
            ->limit(5)
            ->get()
            ->map(function ($sig) {
                return [
                    'id' => $sig->id,
                    'file_name' => $sig->file?->original_file_name,
                    'status' => $sig->validation_status,
                    'signed_at' => $sig->signed_at?->diffForHumans(),
                    'is_valid' => $sig->is_valid,
                ];
            });

        // Statistiques par mois (pour les graphiques)
        // Regroupement fait en PHP plutôt qu'en SQL (DATE_FORMAT n'existe pas
        // sur tous les moteurs, ex. SQLite utilisé par les tests).
        $signaturesByMonth = $this->groupCountByMonth(
            Signature::where('signer_id', $userId)
                ->where('signed_at', '>=', now()->subMonths(6))
                ->pluck('signed_at')
        );

        $verificationsByMonth = $this->groupCountByMonth(
            SignatureVerification::whereHas('signature', function ($q) use ($userId) {
                $q->where('signer_id', $userId);
            })
                ->where('verified_at', '>=', now()->subMonths(6))
                ->pluck('verified_at')
        );

        // Répartition par algorithme
        $algorithms = KeyPair::where('user_id', $userId)
            ->select('algorithm_used', DB::raw('count(*) as total'))
            ->groupBy('algorithm_used')
            ->get();

        // Taux de succès des vérifications
        $verificationSuccessRate = $totalVerifications > 0
            ? round(($successfulVerifications / $totalVerifications) * 100, 2)
            : 0;

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalKeys' => $totalKeys,
                'activeKeys' => $activeKeys,
                'defaultKey' => $defaultKey ? [
                    'id' => $defaultKey->id,
                    'key_name' => $defaultKey->key_name,
                    'fingerprint' => KeyFingerprint::short($defaultKey->public_key),
                ] : null,
                'expiringKeys' => $expiringKeys,

                'totalSignatures' => $totalSignatures,
                'validSignatures' => $validSignatures,
                'pendingSignatures' => $pendingSignatures,
                'revokedSignatures' => $revokedSignatures,

                'totalFiles' => $totalFiles,
                'totalVerifications' => $totalVerifications,
                'successfulVerifications' => $successfulVerifications,
                'publicVerifications' => $publicVerifications,
                'verificationSuccessRate' => $verificationSuccessRate,
            ],
            'recentActivity' => $recentActivity,
            'charts' => [
                'signaturesByMonth' => $signaturesByMonth,
                'verificationsByMonth' => $verificationsByMonth,
                'algorithms' => $algorithms,
            ],
            'recentFiles' => $recentFiles->map(function ($file) {
                return [
                    'id' => $file->id,
                    'name' => $file->original_file_name,
                    'size' => $this->formatFileSize($file->file_size),
                    'created_at' => $file->created_at->diffForHumans(),
                    'status' => $file->status,
                ];
            }),
        ]);
    }

    /**
     * Génère une empreinte à partir de la clé publique
     */

    /**
     * Regroupe une collection de dates par mois (format Y-m) et compte les
     * occurrences, indépendamment du moteur de base de données.
     *
     * @param  Collection<int, CarbonInterface|string|null>  $dates
     */
    private function groupCountByMonth($dates)
    {
        return $dates
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->format('Y-m'))
            ->countBy()
            ->map(fn ($total, $month) => ['month' => $month, 'total' => $total])
            ->sortKeys()
            ->values();
    }

    /**
     * Formate la taille du fichier
     */
    private function formatFileSize($bytes)
    {
        if ($bytes === 0) {
            return '0 Bytes';
        }
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));

        return round($bytes / pow($k, $i), 2).' '.$sizes[$i];
    }
}
