<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
require_once __DIR__.'/fixtures/portal_summary_display.php';
$checks = 0;
$check = static function(string $name, bool $ok) use (&$checks): void {
    if (!$ok) throw new RuntimeException($name);
    echo 'ok '.++$checks.' - '.$name."\n";
};
foreach (['list','detail','missing','zero','empty'] as $state) {
    ob_start(); portal_summary_display_render($state); $html = ob_get_clean();
    $document = new DOMDocument(); @$document->loadHTML($html); $xp = new DOMXPath($document);
    $check($state.' has no inline scripts/styles', $xp->query('//script[not(@src)]|//*[@style or @onclick]')->length === 0);
    if ($state === 'list' || $state === 'empty') {
        $check($state.' states the existing 24-summary limit', str_contains($html,'Showing up to 24 weekly summaries'));
        $check($state.' primary list omits version and fingerprint jargon', !str_contains($html,'definition v') && !str_contains($html,'Archive SHA-256'));
        continue;
    }
    [$context,$archive] = portal_summary_display_fixture($state);
    $check($state.' exact saved text remains intact and escaped',
        $xp->query('//details[contains(@class,"portal-report-official")]/pre')->item(0)->textContent === $archive['text']
        && !str_contains($html,'<script>invalid()</script>'));
    $check($state.' provenance is inside a closed disclosure',
        $xp->query('//details[contains(@class,"portal-report-integrity") and not(@open)]//code')->item(0)->textContent === $archive['content_sha256']);
    $check($state.' saved totals remain unchanged',
        str_contains($html,'>12</div>') && str_contains($html,'>9</div>') && str_contains($html,'90 minutes (1.50 hours)'));
    $check($state.' generation time uses the existing viewer-local display',
        str_contains($html,'datetime="2026-01-15T18:00:00Z"') && str_contains($html,'at 1:00 PM'));
    $check($state.' reporting period still uses the archived schedule timezone',
        str_contains($html,portal_h(portal_report_period_label($archive['period_start'],$archive['period_end'],'America/Los_Angeles'))));
    if ($state === 'missing') $check('missing response metrics are not shown as zeros',
        $xp->query('//div[contains(@class,"portal-report-missing")]')->length === 2 && !str_contains($html,'>0%</div>'));
    if ($state === 'zero') $check('measured zero remains distinct from missing',
        str_contains($html,'>0 min</div>') && str_contains($html,'>0%</div>') && !str_contains($html,'stat-v stat-dim portal-report-missing'));
}
echo "{$checks} summary display checks passed\n";
ob_end_flush();
