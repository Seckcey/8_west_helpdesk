<?php
/**
 * This loader reads only the customer catalog. A staff index belongs in a separate directory
 * with its own loader, which portal code must never require.
 */
declare(strict_types=1);

const HELP_CATALOG_CUSTOMER_AUDIENCES = ['owner_admin', 'employee', 'viewer'];

final class HelpCatalogException extends RuntimeException {
    public function __construct(public readonly string $reason = 'help_catalog_invalid') {
        parent::__construct($reason);
    }
}

final class HelpCatalog {
    private const ARTICLE_KEYS = [
        'id', 'app', 'audience', 'title', 'summary', 'keywords', 'ui_labels', 'related', 'anchor',
        'audience_label', 'application', 'order', 'legacy_guide', 'last_verified', 'body', 'approval',
    ];
    private const APPS = ['id', 'safeharbor', 'milepost', 'coastmark', 'companion', 'all'];
    private const APPROVERS = ['gpt-6.2-astra', 'fable-5.2', 'sol-6.2', 'opus-5.5'];
    private static ?array $cached = null;
    private static ?string $testDir = null;

    public static function index(?string $dir): array {
        if ($dir !== null) {
            return self::load($dir);
        }
        return self::$cached ??= self::load(self::$testDir ?? __DIR__.'/../help');
    }

    public static function useDir(?string $dir): void {
        self::expect(PHP_SAPI === 'cli');
        self::$testDir = $dir;
        self::$cached = null;
    }

    private static function expect(bool $valid): void {
        if (!$valid) {
            throw new HelpCatalogException();
        }
    }

    private static function keys(mixed $object, array $expected): void {
        self::expect($object instanceof stdClass && count((array)$object) === count($expected)
            && array_diff_key(array_flip($expected), (array)$object) === []);
    }

    private static function readBytes(string $path): string {
        self::expect(!@is_link($path) && @is_file($path) && @is_readable($path));
        $bytes = @file_get_contents($path);
        self::expect($bytes !== false && !str_contains($bytes, "\r"));
        return $bytes;
    }

    private static function load(string $dir): array {
        $dir = str_replace('\\', '/', $dir);
        self::expect($dir !== '' && !str_contains($dir, "\0") && !str_contains($dir, '://') && !str_starts_with($dir, '//'));
        $manifestBytes = self::readBytes($dir.'/help-manifest.json');
        $indexBytes = self::readBytes($dir.'/customer-index.json');
        try {
            $manifest = json_decode($manifestBytes, false, 32, JSON_THROW_ON_ERROR);
            self::keys($manifest, ['format', 'index', 'version', 'files', 'hash_format']);
            self::keys($manifest->files, ['customer-index.json']);
            $file = $manifest->files->{'customer-index.json'};
            self::keys($file, ['sha256', 'bytes']);
            self::expect($file->sha256 === hash('sha256', $indexBytes) && $file->bytes === strlen($indexBytes));
            self::expect($manifest->format === '8west-help-manifest-v1' && $manifest->index === 'customer'
                && $manifest->hash_format === 'SHA-256 of the exact LF file bytes'
                && is_string($manifest->version) && preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $manifest->version) === 1);

            // Keep JSON objects distinct from lists during validation, including {} versus [].
            $index = json_decode($indexBytes, false, 32, JSON_THROW_ON_ERROR);
            self::keys($index, ['format', 'index', 'version', 'articles']);
            self::expect($index->format === '8west-help-index-v1' && $index->index === 'customer'
                && $index->version === $manifest->version && is_array($index->articles));
            $ids = $anchors = [];
            foreach ($index->articles as $article) {
                self::validateArticle($article);
                $legacy = (int)$article->legacy_guide;
                self::expect(!isset($ids[$article->id]) && !isset($anchors[$article->app][$legacy][$article->anchor]));
                $ids[$article->id] = true;
                $anchors[$article->app][$legacy][$article->anchor] = true;
            }
            foreach ($index->articles as $article) {
                foreach ($article->related as $id) {
                    self::expect($id !== $article->id && isset($ids[$id]));
                }
            }
            return json_decode($indexBytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HelpCatalogException();
        }
    }

    private static function stringList(mixed $list): void {
        self::expect(is_array($list));
        foreach ($list as $value) {
            self::expect(is_string($value));
        }
    }

    private static function validateArticle(mixed $article): void {
        self::keys($article, self::ARTICLE_KEYS);
        foreach (['id', 'app', 'title', 'summary', 'anchor'] as $field) {
            self::expect(is_string($article->$field));
        }
        foreach (['audience', 'keywords', 'ui_labels', 'related'] as $field) {
            self::stringList($article->$field);
        }
        self::expect(($article->audience_label === null || is_string($article->audience_label))
            && ($article->application === null || is_string($article->application))
            && is_int($article->order) && $article->order >= 0 && is_bool($article->legacy_guide));
        self::keys($article->approval, ['approved_by', 'verdict']);
        self::expect(in_array($article->approval->approved_by, self::APPROVERS, true)
            && is_string($article->approval->verdict) && trim($article->approval->verdict) !== '');
        self::keys($article->last_verified, ['date', 'commit']);
        $verified = $article->last_verified;
        self::expect(is_string($verified->date) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $verified->date, $date) === 1
            && checkdate((int)$date[2], (int)$date[3], (int)$date[1])
            && is_string($verified->commit) && preg_match('/\A[0-9a-f]{7,40}\z/', $verified->commit) === 1);
        self::expect(in_array($article->app, self::APPS, true) && strlen($article->id) <= 64
            && preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $article->id) === 1
            && preg_match('/\A[a-z][a-z0-9-]{0,63}\z/', $article->anchor) === 1);
        self::expect($article->audience !== [] && array_diff($article->audience, HELP_CATALOG_CUSTOMER_AUDIENCES) === []
            && count(array_unique($article->audience)) === count($article->audience));
        if ($article->legacy_guide) {
            self::expect($article->app === 'safeharbor' && count($article->audience) === count(HELP_CATALOG_CUSTOMER_AUDIENCES));
        }
        self::expect(preg_match('/\A[^\r\n]{1,200}\z/u', $article->summary) === 1
            && !str_contains($article->summary, '**') && !str_contains($article->summary, '`'));
        self::validateBody($article->body);
    }

    private static function validateBody(mixed $body): void {
        if (($body->format ?? null) === 'text') {
            self::keys($body, ['format', 'text']);
            self::expect(is_string($body->text) && strlen($body->text) <= 6000);
            return;
        }
        self::keys($body, ['format', 'blocks']);
        self::expect($body->format === 'blocks' && is_array($body->blocks) && $body->blocks !== []);
        $paragraphs = [];
        foreach ($body->blocks as $block) {
            if (($block->type ?? null) === 'paragraph') {
                self::keys($block, ['type', 'text']);
                self::expect(is_string($block->text));
                $paragraphs[] = $block->text;
            } else {
                self::keys($block, ['type', 'items']);
                self::expect($block->type === 'steps');
                self::stringList($block->items);
                self::expect($block->items !== []);
                $steps = [];
                foreach ($block->items as $n => $item) {
                    $steps[] = ($n + 1).'. '.$item;
                }
                $paragraphs[] = implode("\n", $steps);
            }
        }
        self::expect(strlen(implode("\n\n", $paragraphs)) <= 6000);
    }
}

function help_catalog_index(?string $dir = null): array {
    return HelpCatalog::index($dir);
}

function help_catalog_use_dir(?string $dir): void {
    HelpCatalog::useDir($dir);
}
