<?php
/** Check desk guide labels against the staff views, using only the released labels pair. */
declare(strict_types=1);

const DESK_LABELS_VERSION = '0.3.0';
const DESK_LABELS_SOURCE_COMMIT = '285734bd5e0452b3f263c46b5c81335eb226df93';
const DESK_LABELS_MANIFEST_SHA256 = '346c15857d8f985a9abdc6ca384cd57431c740ca073491b0e4cb78b53d40a64f';

function desk_manifest(string $version, string $indexBytes): array
{
    return [
        'format' => '8west-help-manifest-v1', 'index' => 'labels', 'version' => $version,
        'files' => ['labels-index.json' => ['sha256' => hash('sha256', $indexBytes), 'bytes' => strlen($indexBytes)]],
        'hash_format' => 'SHA-256 of the exact LF file bytes',
    ];
}

function desk_pair_bytes(string $path): string
{
    if (!is_file($path) || is_link($path)) throw new RuntimeException('Expected a regular file at '.$path);
    $bytes = file_get_contents($path);
    if ($bytes === false || str_contains($bytes, "\r") || !str_ends_with($bytes, "\n")) {
        throw new RuntimeException('Expected LF-terminated JSON at '.$path);
    }
    return $bytes;
}

function desk_fields(mixed $object, array $keys): bool
{
    return $object instanceof stdClass && count((array)$object) === count($keys)
        && array_diff_key(array_flip($keys), (array)$object) === [];
}

function desk_articles(string $dir, string $version, ?string $pin = null): array
{
    if (is_link($dir) || scandir($dir) !== ['.', '..', 'help-manifest.json', 'labels-index.json']) {
        throw new RuntimeException('Expected just the labels index and its manifest in '.$dir);
    }
    $manifest = desk_pair_bytes($dir.'/help-manifest.json');
    $bytes = desk_pair_bytes($dir.'/labels-index.json');
    if ($pin !== null && hash('sha256', $manifest) !== $pin) {
        throw new RuntimeException('Desk manifest differs from catalog commit '.DESK_LABELS_SOURCE_COMMIT);
    }
    if (json_decode($manifest, true, 32, JSON_THROW_ON_ERROR) !== desk_manifest($version, $bytes)) {
        throw new RuntimeException('Desk manifest differs from the expected version, hash or byte count');
    }
    if (preg_match('/"(?:title|summary|keywords|body|approval)"\s*:/', $bytes)) {
        throw new UnexpectedValueException('Desk labels contain a forbidden staff article field');
    }
    $index = json_decode($bytes, false, 32, JSON_THROW_ON_ERROR);
    if (!desk_fields($index, ['format', 'index', 'version', 'articles'])
        || $index->format !== '8west-help-labels-v1' || $index->index !== 'labels'
        || $index->version !== $version || !is_array($index->articles)) {
        throw new RuntimeException('Invalid desk labels index');
    }
    $ids = [];
    foreach ($index->articles as $row) {
        if (!desk_fields($row, ['id', 'app', 'ui_labels'])) {
            throw new RuntimeException('Desk articles may contain only id, app and ui_labels');
        }
        if (!is_string($row->id) || strlen($row->id) > 64
            || !preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $row->id) || isset($ids[$row->id])) {
            throw new RuntimeException('Invalid or duplicate desk article ID');
        }
        $ids[$row->id] = true;
        if ($row->app !== 'safeharbor' || !is_array($row->ui_labels) || !$row->ui_labels) {
            throw new RuntimeException($row->id.': expected Safeharbor desk UI labels');
        }
        foreach ($row->ui_labels as $label) {
            if (!is_string($label) || !preg_match('/\A.{1,80}\z/us', $label)) {
                throw new RuntimeException($row->id.': invalid UI label');
            }
        }
    }
    return $index->articles;
}

function desk_views(string $app): string
{
    $views = '';
    foreach (['public/*.php' => null, 'lib/*.php' => 'portal_', 'public/assets/js/*.js' => 'portal-'] as $glob => $exclude) {
        $files = glob($app.'/'.$glob);
        if (!$files) throw new RuntimeException('No desk view files match '.$glob);
        foreach ($files as $file) {
            if ($exclude !== null && str_starts_with(basename($file), $exclude)) continue;
            $source = file_get_contents($file);
            if ($source === false) throw new RuntimeException('Cannot read '.$file);
            $views .= $source."\n";
        }
    }
    return $views;
}

function desk_missing_labels(array $articles, string $views): array
{
    $missing = [];
    foreach ($articles as $row) {
        foreach ($row->ui_labels as $label) {
            if (!str_contains($views, $label)) $missing[] = $row->id.': missing label "'.$label.'"';
        }
    }
    return $missing;
}

$app = dirname(__DIR__);
$articles = desk_articles($app.'/help-labels', DESK_LABELS_VERSION, DESK_LABELS_MANIFEST_SHA256);
$missing = desk_missing_labels($articles, desk_views($app));

// Exercise the same reader and matcher with a physical pair, including a case-only mismatch.
$dir = sys_get_temp_dir().'/safeharbor-desk-labels-'.bin2hex(random_bytes(12));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create desk label fixture');
try {
    $row = ['id' => 'desk-example', 'app' => 'safeharbor', 'ui_labels' => ['Example control', 'example control']];
    $write = static function (array $row) use ($dir): void {
        $index = ['format' => '8west-help-labels-v1', 'index' => 'labels', 'version' => '1.2.3', 'articles' => [$row]];
        $bytes = json_encode($index, JSON_THROW_ON_ERROR)."\n";
        file_put_contents($dir.'/labels-index.json', $bytes);
        file_put_contents($dir.'/help-manifest.json', json_encode(desk_manifest('1.2.3', $bytes), JSON_THROW_ON_ERROR)."\n");
    };
    $write($row);
    if (desk_missing_labels(desk_articles($dir, '1.2.3'), '<button>Example control</button>')
        !== ['desk-example: missing label "example control"']) {
        throw new RuntimeException('The desk drift checker must report just the missing label and its article ID');
    }
    foreach (['title', 'summary', 'keywords', 'body', 'approval'] as $field) {
        $write($row + [$field => true]);
        try {
            desk_articles($dir, '1.2.3');
        } catch (UnexpectedValueException) {
            continue;
        }
        throw new RuntimeException('Desk labels accepted a forbidden article field: '.$field);
    }
} finally {
    foreach (['labels-index.json', 'help-manifest.json'] as $name) {
        if (is_file($dir.'/'.$name)) unlink($dir.'/'.$name);
    }
    rmdir($dir);
}

if ($missing) {
    fwrite(STDERR, implode("\n", $missing)."\n");
    exit(1);
}
$labels = array_sum(array_map(static fn(stdClass $row): int => count($row->ui_labels), $articles));
echo $labels.' desk UI labels across '.count($articles)." articles and the drift self-test passed\n";
