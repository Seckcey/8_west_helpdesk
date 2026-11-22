<?php
/** Check the words the released Safeharbor and Companion guides tell customers to look for. */
declare(strict_types=1);
require_once __DIR__.'/../lib/help_catalog.php';

function missing_ui_labels(array $labels, string $views): array
{
    return array_values(array_filter($labels, static fn(string $label): bool =>
        $label === '' || !str_contains($views, $label)));
}

$views = '';
foreach (['lib/portal_*.php', 'public/portal/*.php', 'public/assets/js/portal-*.js', 'public/assets/js/desktop-handoff.js'] as $pattern) {
    $files = glob(__DIR__.'/../'.$pattern);
    if (!$files) throw new RuntimeException('No view files match '.$pattern);
    foreach ($files as $file) {
        $source = file_get_contents($file);
        if ($source === false) throw new RuntimeException('Cannot read '.$file);
        $views .= $source."\n";
    }
}

$articles = 0;
$labels = 0;
$errors = [];
$realLabel = null;
foreach (help_catalog_index()['articles'] as $row) {
    if (!in_array($row['app'], ['safeharbor', 'companion'], true)) continue;
    $articles++;
    $labels += count($row['ui_labels']);
    $realLabel ??= $row['ui_labels'][0] ?? null;
    if (!$row['ui_labels']) $errors[] = $row['id'].': no UI labels';
    foreach (missing_ui_labels($row['ui_labels'], $views) as $label) {
        $errors[] = $row['id'].': missing label "'.$label.'"';
    }
}
if ($errors) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}
$madeUp = 'Fictional button for the help drift test';
if ($realLabel === null || missing_ui_labels([$realLabel, $madeUp], $views) !== [$madeUp]) {
    throw new RuntimeException('The drift check must accept a real label and flag the made-up label');
}
echo "$labels UI labels across $articles articles and the drift self-test passed\n";
