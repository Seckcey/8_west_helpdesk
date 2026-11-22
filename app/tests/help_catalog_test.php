<?php
/** Exercise the customer catalog loader with local files and no settings or database. */
declare(strict_types=1);
require_once __DIR__.'/../lib/help_catalog.php';

const HELP_PIN = '0eea642371b8991fd1a8a7c8585b645b0100a9835c6b3d089a3fa75ef808b648';
const HELP_SOURCE_COMMIT = 'cd1e429c778096154bfd7f7eae1e7ce55e761658';
const VENDORED = __DIR__.'/../help';

$checks = 0;
$tempDirs = [];
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo 'ok '.++$checks.' - '.$label."\n";
}

function denied(string $label, callable $load): void {
    $reason = null;
    ob_start();
    try {
        $load();
    } catch (HelpCatalogException $e) {
        $reason = $e->reason;
    } finally {
        $output = ob_get_clean();
    }
    check($reason === 'help_catalog_invalid' && $output === '', $label.' is refused quietly');
}

function fixture_json(mixed $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}

function write_fixture(string $path, string $bytes): void {
    if (file_put_contents($path, $bytes) !== strlen($bytes) || file_get_contents($path) !== $bytes) {
        throw new RuntimeException('Could not write the exact fixture bytes');
    }
}

function write_index_bytes(string $dir, string $bytes): void {
    global $originalManifest;
    $manifest = $originalManifest;
    $manifest['files']['customer-index.json'] = ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
    write_fixture($dir.'/customer-index.json', $bytes);
    write_fixture($dir.'/help-manifest.json', fixture_json($manifest));
}

// Each case starts from the released rows. Only the chosen mutation and its integrity fields change.
function tamper_dir(callable $mutate, ?string $dir = null): string {
    global $originalIndex, $tempDirs;
    if ($dir === null) {
        $dir = sys_get_temp_dir().'/safeharbor-help-'.bin2hex(random_bytes(12));
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException('Could not create catalog fixture directory');
        }
        $tempDirs[] = $dir;
    }
    write_index_bytes($dir, fixture_json($mutate($originalIndex)));
    return $dir;
}

function control_dir(string $label): string {
    global $originalIndex;
    $dir = tamper_dir(static fn(array $index): array => $index);
    check(help_catalog_index($dir) === $originalIndex, $label.' accepts the unmutated control');
    return $dir;
}

function article_change(array $fields, bool $legacy = false): Closure {
    return static function (array $index) use ($fields, $legacy): array {
        $at = array_search($legacy, array_column($index['articles'], 'legacy_guide'), true);
        if ($at === false) {
            throw new RuntimeException('Expected article missing from fixture');
        }
        $index['articles'][$at] = array_replace($index['articles'][$at], $fields);
        return $index;
    };
}

$originalIndex = json_decode(file_get_contents(VENDORED.'/customer-index.json'), true, 32, JSON_THROW_ON_ERROR);
$originalManifest = json_decode(file_get_contents(VENDORED.'/help-manifest.json'), true, 32, JSON_THROW_ON_ERROR);
try {
    $index = help_catalog_index();
    check($index['version'] === '0.2.0', 'pinned catalog version');
    check(hash_file('sha256', VENDORED.'/help-manifest.json') === HELP_PIN, 'pinned manifest hash');
    check(preg_match('/\A[0-9a-f]{40}\z/', HELP_SOURCE_COMMIT) === 1, 'source commit is a full lowercase Git SHA');
    $files = array_values(array_diff(scandir(VENDORED), ['.', '..']));
    sort($files);
    check($files === ['customer-index.json', 'help-manifest.json'], 'only the customer index and manifest are vendored');
    check(count($index['articles']) === 37 && $index === $originalIndex, 'all 37 approved articles are returned intact');

    $blockBody = static fn(array $block): array => ['body' => ['format' => 'blocks', 'blocks' => [$block]]];
    $mutations = [
        'technician audience' => article_change(['audience' => ['technician']]),
        'staff index' => static fn(array $i): array => ['index' => 'staff'] + $i,
        'wrong index format' => static fn(array $i): array => ['format' => 'x'] + $i,
        'extra index key' => static fn(array $i): array => $i + ['extra' => true],
        'object in place of article list' => static fn(array $i): array => ['articles' => (object)$i['articles']] + $i,
        'bad ID' => article_change(['id' => '../x']),
        'overlong ID' => article_change(['id' => str_repeat('a', 65)]),
        'bad anchor' => article_change(['anchor' => 'a"b']),
        'added status' => article_change(['status' => 'draft']),
        'added drafted_by' => article_change(['drafted_by' => 'x']),
        'missing approval' => static function (array $i): array {
            unset($i['articles'][0]['approval']);
            return $i;
        },
        'unknown approver' => article_change(['approval' => ['approved_by' => 'someone', 'verdict' => 'Reviewed']]),
        'empty verdict' => article_change(['approval' => ['approved_by' => 'opus-5.5', 'verdict' => '']]),
        'extra approval key' => article_change(['approval' => $index['articles'][0]['approval'] + ['extra' => 'x']]),
        'wrong verification shape' => article_change(['last_verified' => ['date' => '2026-11-22']]),
        'bad verification date' => article_change(['last_verified' => ['date' => '2026-02-30', 'commit' => 'ad70bf4']]),
        'legacy owner only' => article_change(['audience' => ['owner_admin']], true),
        'legacy repeated audience' => article_change(['audience' => ['owner_admin', 'employee', 'employee']], true),
        'legacy other app' => article_change(['app' => 'id'], true),
        'duplicate pilot anchor' => static function (array $i): array {
            $i['articles'][1]['anchor'] = $i['articles'][0]['anchor'];
            return $i;
        },
        'duplicate legacy anchor' => static function (array $i): array {
            $legacy = array_keys(array_column($i['articles'], 'legacy_guide'), true, true);
            $i['articles'][$legacy[1]]['anchor'] = $i['articles'][$legacy[0]]['anchor'];
            return $i;
        },
        'duplicate ID' => static function (array $i): array {
            $i['articles'][1]['id'] = $i['articles'][0]['id'];
            return $i;
        },
        'empty audience' => article_change(['audience' => []]),
        'non-string audience member' => article_change(['audience' => [1]]),
        'unknown app' => article_change(['app' => 'other']),
        'unknown related article' => article_change(['related' => ['sh-missing']]),
        'summary over 200 characters' => article_change(['summary' => str_repeat('é', 201)]),
        'negative order' => article_change(['order' => -1]),
        'string order' => article_change(['order' => '10']),
        'numeric legacy flag' => article_change(['legacy_guide' => 1]),
        'object in place of keywords' => article_change(['keywords' => (object)[]]),
        'unknown body format' => article_change(['body' => ['format' => 'html', 'text' => 'x']]),
        'extra text body key' => article_change(['body' => ['format' => 'text', 'text' => 'x', 'blocks' => []]]),
        'non-string body text' => article_change(['body' => ['format' => 'text', 'text' => []]]),
        'body over 6000 bytes' => article_change(['body' => ['format' => 'text', 'text' => str_repeat('é', 3001)]]),
        'object in place of blocks' => article_change(['body' => ['format' => 'blocks', 'blocks' => (object)[]]]),
        'unknown body block type' => article_change($blockBody(['type' => 'other', 'text' => 'x'])),
        'extra paragraph key' => article_change($blockBody(['type' => 'paragraph', 'text' => 'x', 'extra' => 1])),
        'non-string paragraph' => article_change($blockBody(['type' => 'paragraph', 'text' => null])),
        'empty steps' => article_change($blockBody(['type' => 'steps', 'items' => []])),
        'non-string step' => article_change($blockBody(['type' => 'steps', 'items' => [1]])),
    ];
    foreach (['id', 'app', 'title', 'summary', 'anchor', 'audience_label', 'application'] as $field) {
        $mutations['wrong '.$field.' type'] = article_change([$field => false]);
    }
    foreach (['keywords', 'ui_labels', 'related'] as $field) {
        $mutations['non-string member of '.$field] = article_change([$field => [false]]);
    }
    foreach ($mutations as $label => $mutate) {
        $dir = control_dir($label);
        tamper_dir($mutate, $dir);
        denied($label, static fn() => help_catalog_index($dir));
    }

    foreach ([
        'extra manifest key' => static fn(array $m): array => $m + ['extra' => 1],
        'wrong manifest format' => static fn(array $m): array => ['format' => 'x'] + $m,
        'staff manifest' => static fn(array $m): array => ['index' => 'staff'] + $m,
        'manifest version differs from index' => static fn(array $m): array => ['version' => '0.1.1'] + $m,
        'wrong hash format' => static fn(array $m): array => ['hash_format' => 'other'] + $m,
        'extra manifest file' => static function (array $m): array {
            $m['files']['staff-index.json'] = $m['files']['customer-index.json'];
            return $m;
        },
        'extra file metadata key' => static function (array $m): array {
            $m['files']['customer-index.json']['extra'] = true;
            return $m;
        },
        'wrong byte count' => static function (array $m): array {
            $m['files']['customer-index.json']['bytes']++;
            return $m;
        },
    ] as $label => $mutate) {
        $dir = control_dir($label);
        $path = $dir.'/help-manifest.json';
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        write_fixture($path, fixture_json($mutate($manifest)));
        denied($label, static fn() => help_catalog_index($dir));
    }

    foreach (['help-manifest.json', 'customer-index.json'] as $name) {
        $dir = control_dir('missing '.$name);
        unlink($dir.'/'.$name);
        denied('missing '.$name, static fn() => help_catalog_index($dir));
    }
    $dir = control_dir('changed byte');
    $path = $dir.'/customer-index.json';
    $bytes = file_get_contents($path);
    $bytes[strpos($bytes, 'Sign in')] = 's';
    write_fixture($path, $bytes);
    denied('changed byte', static fn() => help_catalog_index($dir));

    $dir = control_dir('CRLF index');
    write_index_bytes($dir, str_replace("\n", "\r\n", file_get_contents($dir.'/customer-index.json')));
    denied('CRLF index with matching integrity fields', static fn() => help_catalog_index($dir));
    $dir = control_dir('CRLF manifest');
    $path = $dir.'/help-manifest.json';
    write_fixture($path, str_replace("\n", "\r\n", file_get_contents($path)));
    denied('CRLF manifest', static fn() => help_catalog_index($dir));
    $dir = control_dir('invalid index JSON');
    write_index_bytes($dir, "{\n");
    denied('invalid index JSON with matching integrity fields', static fn() => help_catalog_index($dir));
    $dir = control_dir('invalid manifest JSON');
    write_fixture($dir.'/help-manifest.json', "{\n");
    denied('invalid manifest JSON', static fn() => help_catalog_index($dir));
    denied('stream wrapper directory', static fn() => help_catalog_index('php://memory'));

    $dir = control_dir('cache reset');
    help_catalog_use_dir($dir);
    check(help_catalog_index() === $originalIndex, 'default reads the test directory');
    unlink($dir.'/help-manifest.json');
    check(help_catalog_index() === $originalIndex, 'default retains its validated result for this request');
    denied('explicit directory bypasses the cache', static fn() => help_catalog_index($dir));
    help_catalog_use_dir($dir);
    denied('setting the same test directory clears the cache', static fn() => help_catalog_index());
    help_catalog_use_dir(null);
    check(help_catalog_index() === $originalIndex, 'null restores the vendored directory');
} finally {
    help_catalog_use_dir(null);
    foreach ($tempDirs as $dir) {
        foreach (['customer-index.json', 'help-manifest.json'] as $name) {
            if (file_exists($dir.'/'.$name)) {
                unlink($dir.'/'.$name);
            }
        }
        rmdir($dir);
    }
}
echo "$checks help catalog checks passed\n";
