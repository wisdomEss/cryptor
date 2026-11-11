<?php

/**
 * Lit un rapport de couverture Clover, écrit un résumé Markdown sur la sortie standard
 * (destiné à $GITHUB_STEP_SUMMARY) et échoue si la couverture est sous le seuil.
 *
 * Usage : php scripts/coverage-check.php coverage.xml [seuil_en_pourcentage]
 */
$file = $argv[1] ?? 'coverage.xml';
$min = (float) ($argv[2] ?? 0);

if (! is_file($file)) {
    fwrite(STDERR, "Rapport de couverture introuvable : {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
$metrics = $xml->project->metrics ?? null;

if (! $metrics) {
    fwrite(STDERR, "Rapport Clover invalide : {$file}\n");
    exit(2);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? round($covered / $statements * 100, 2) : 100.0;

echo "## Couverture des tests PHP\n\n";
echo "| Lignes couvertes | Total | Taux | Seuil |\n|---|---|---|---|\n";
echo sprintf("| %d | %d | **%.2f %%** | %s |\n\n", $covered, $statements, $percent, $min > 0 ? $min.' %' : 'aucun');

// Détail des fichiers les moins couverts (utile pour savoir où écrire des tests).
$files = [];
foreach ($xml->xpath('//file') as $f) {
    $m = $f->metrics;
    $s = (int) $m['statements'];
    if ($s > 0) {
        $files[(string) $f['name']] = [$s, (int) $m['coveredstatements']];
    }
}
uasort($files, fn ($a, $b) => ($a[1] / $a[0]) <=> ($b[1] / $b[0]));

echo "<details><summary>10 fichiers les moins couverts</summary>\n\n| Fichier | Taux |\n|---|---|\n";
foreach (array_slice($files, 0, 10, true) as $name => [$s, $c]) {
    echo sprintf("| %s | %.0f %% (%d/%d) |\n", preg_replace('~^.*?/app/~', 'app/', $name), $c / $s * 100, $c, $s);
}
echo "\n</details>\n";

if ($percent < $min) {
    fwrite(STDERR, sprintf("Couverture insuffisante : %.2f %% < seuil %.2f %%\n", $percent, $min));
    exit(1);
}
