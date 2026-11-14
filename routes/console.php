<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Journal d'audit de la vérification publique : voir config/cryptor.php
// (`public_verification.retention_days`). Nécessite `php artisan schedule:work`
// ou une entrée cron `schedule:run` sur le serveur.
Schedule::command('cryptor:prune-verifications')->dailyAt('03:30');
