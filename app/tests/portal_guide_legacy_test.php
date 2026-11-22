<?php
/** Pin the titles and text of the six legacy portal guide articles. */
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_guide.php';

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo 'ok '.++$checks.' - '.$label."\n";
}

$fixture = file_get_contents(__DIR__.'/fixtures/portal_guide_legacy_sha256.json');
check($fixture !== false && !str_contains($fixture, "\r"), 'fixture is readable and contains no carriage returns');
$expected = json_decode($fixture, true, 8, JSON_THROW_ON_ERROR);
check(is_array($expected) && count($expected) === 6, 'fixture has exactly six keys');
foreach ($expected as $id => $digest) {
    check(is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1,
        $id.' fixture value is a lowercase SHA-256 digest');
}

$legacy = portal_guide_legacy_articles();
check(array_keys($legacy) === ['devices', 'requests', 'updates', 'response', 'summaries', 'privacy'], 'legacy ids and order');
check(array_keys($expected) === array_keys($legacy), 'fixture covers every legacy article');
foreach ($legacy as $id => $article) {
    $keys = array_keys($article);
    check($keys === ['title', 'text'] || $keys === ['title', 'text', 'catalog_id'], $id.' shape');
    check(hash('sha256', $article['title']."\n\n".$article['text']) === $expected[$id],
        $id.' exactly preserves approved title and text');
}

echo "$checks legacy guide checks passed\n";
