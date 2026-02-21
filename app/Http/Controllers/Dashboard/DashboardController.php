<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\FileSigned;
use App\Models\SignatureVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        // Statistiques générales
        $totalKeys = KeyPair::where('user_id', $userId)->count();
        $activeKeys = KeyPair::where('user_id', $userId)->active()->count();
        $defaultKey = KeyPair::where('user_id', $userId)->where('is_default', true)->first();

        // Statistiques des signatures
        $totalSignatures = Signature::where('signer_id', $userId)->count();
        $validSignatures = Signature::where('signer_id', $userId)->valid()->count();
        $pendingSignatures = Signature::where('signer_id', $userId)->pendingValidation()->count();
        $revokedSignatures = Signature::where('signer_id', $userId)->whereNotNull('revoked_at')->count();

        // Statistiques des fichiers signés
        $totalFiles = FileSigned::where('uploader_id', $userId)->count();
        $recentFiles = FileSigned::where('uploader_id', $userId)
            ->latest()
            ->limit(5)
            ->get();

        // Statistiques des vérifications
        $totalVerifications = SignatureVerification::whereHas('signature', function($q) use ($userId) {
            $q->where('signer_id', $userId);
        })->count();

        $successfulVerifications = SignatureVerification::whereHas('signature', function($q) use ($userId) {
            $q->where('signer_id', $userId);
        })->where('result', true)->count();

        $publicVerifications = SignatureVerification::whereHas('signature', function($q) use ($userId) {
            $q->where('signer_id', $userId);
        })->where('is_public_verification', true)->count();

        // Activité récente (dernières signatures)
        $recentActivity = Signature::with(['file', 'signer'])
            ->where('signer_id', $userId)
            ->latest('signed_at')
            ->limit(5)
            ->get()
            ->map(function($sig) {
                return [
                    'id' => $sig->id,
                    'file_name' => $sig->file->original_file_name,
                    'status' => $sig->validation_status,
                    'signed_at' => $sig->signed_at?->diffForHumans(),
                    'is_valid' => $sig->is_valid,
                ];
            });

        // Statistiques par mois (pour les graphiques)
        $signaturesByMonth = Signature::where('signer_id', $userId)
            ->select(DB::raw('DATE_FORMAT(signed_at, "%Y-%m") as month'), DB::raw('count(*) as total'))
            ->where('signed_at', '>=', now()->subMonths(6))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $verificationsByMonth = SignatureVerification::whereHas('signature', function($q) use ($userId) {
            $q->where('signer_id', $userId);
        })
        ->select(DB::raw('DATE_FORMAT(verified_at, "%Y-%m") as month'), DB::raw('count(*) as total'))
        ->where('verified_at', '>=', now()->subMonths(6))
        ->groupBy('month')
        ->orderBy('month')
        ->get();

        // Répartition par algorithme
        $algorithms = KeyPair::where('user_id', $userId)
            ->select('algorithm_used', DB::raw('count(*) as total'))
            ->groupBy('algorithm_used')
            ->get();

        // Expiration proche (clés qui expirent dans 30 jours)
        $expiringKeys = KeyPair::where('user_id', $userId)
            ->whereNotNull('valid_to')
            ->where('valid_to', '<=', now()->addDays(30))
            ->where('valid_to', '>', now())
            ->count();

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
                    'fingerprint' => $this->generateFingerprint($defaultKey->public_key),
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
            'recentFiles' => $recentFiles->map(function($file) {
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
    private function generateFingerprint($publicKey)
    {
        $key = preg_replace('/-----(BEGIN|END) (PUBLIC|PRIVATE) KEY-----/', '', $publicKey);
        $key = preg_replace('/\s+/', '', $key);
        $hash = hash('sha256', $key);
        return implode(':', str_split(substr(strtoupper($hash), 0, 16), 2));
    }

    /**
     * Formate la taille du fichier
     */
    private function formatFileSize($bytes)
    {
        if ($bytes === 0) return '0 Bytes';
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }
}