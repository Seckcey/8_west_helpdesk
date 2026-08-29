<?php
/**
 * Immutable release metadata and deterministic asset cache keys.
 *
 * Production writes the three marker files while assembling an exact Git
 * artifact. Development checkouts deliberately have no markers and use the
 * stable `dev` cache key instead.
 */
declare(strict_types=1);

/**
 * @return array{revision:string,artifact_sha256:string,release_digest:string}|null
 */
function safeharbor_release_metadata(?string $root = null): ?array
{
    $root ??= dirname(__DIR__);
    $values = [];
    foreach ([
        'revision' => ['REVISION', '/\A[0-9a-f]{40}\z/D'],
        'artifact_sha256' => ['ARTIFACT_SHA256', '/\A[0-9a-f]{64}\z/D'],
        'release_digest' => ['RELEASE_DIGEST', '/\A[0-9a-f]{64}\z/D'],
    ] as $key => [$filename, $pattern]) {
        $path = $root . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $value = trim((string)file_get_contents($path));
        if (preg_match($pattern, $value) !== 1) {
            return null;
        }
        $values[$key] = $value;
    }

    return $values;
}

function safeharbor_asset_version(): string
{
    static $version = null;
    if (is_string($version)) {
        return $version;
    }

    $metadata = safeharbor_release_metadata();
    $version = $metadata === null ? 'dev' : substr($metadata['revision'], 0, 12);
    return $version;
}

function safeharbor_asset_url(string $path): string
{
    if (preg_match('#\A/assets/[A-Za-z0-9._/-]+\z#D', $path) !== 1
        || str_contains($path, '/../')
        || str_ends_with($path, '/..')) {
        throw new InvalidArgumentException('Asset path must be an absolute local asset path.');
    }

    return $path . '?v=' . rawurlencode(safeharbor_asset_version());
}
