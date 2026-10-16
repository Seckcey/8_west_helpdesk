<?php
/** Owner-only Unix cache files. Directories are provisioned by the operator. */
declare(strict_types=1);

namespace EightWest\Id\Security;

final class PrivateFileCache
{
    /**
     * This protects against other OS identities, not code already running as
     * the same app user. Each consumer needs its own process UID/cache root.
     * No runtime mkdir/chmod: never adopt an attacker-precreated cache root.
     */
    public static function directory(string $path): ?array
    {
        if (!function_exists('posix_geteuid') || $path === '' || strlen($path) > 4096
            || !str_starts_with($path, '/') || str_contains($path, "\0")
            || str_contains($path, '\\') || str_contains($path, '//')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~D', $path) === 1
            || preg_match('~^[A-Za-z0-9._-]+$~D', basename($path)) !== 1) {
            return null;
        }
        $uid = posix_geteuid();
        $directory = dirname($path);
        $current = '';
        $parts = explode('/', trim($directory, '/'));
        array_unshift($parts, '');
        $final = null;
        foreach ($parts as $part) {
            $current = $current === '' ? '/' : rtrim($current, '/') . '/' . $part;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
                || !in_array($stat['uid'], [0, $uid], true)) {
                return null;
            }
            // A root-owned sticky ancestor (e.g. /tmp) cannot unlink another
            // owner's private child. The actual cache parent is never shared.
            if (($stat['mode'] & 0022) !== 0
                && !(($stat['mode'] & 01000) !== 0 && $stat['uid'] === 0)) {
                return null;
            }
            $final = $stat;
        }
        if (!is_array($final) || $final['uid'] !== $uid
            || ($final['mode'] & 07777) !== 0700) {
            return null;
        }
        return $final;
    }

    private static function file(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return self::validFile($stat) ? $stat : null;
    }

    private static function validFile(mixed $stat): bool
    {
        return is_array($stat) && function_exists('posix_geteuid')
            && ($stat['mode'] & 0170000) === 0100000
            && ($stat['mode'] & 07777) === 0600
            && $stat['uid'] === posix_geteuid() && $stat['nlink'] === 1;
    }

    private static function same(mixed $left, mixed $right): bool
    {
        return is_array($left) && is_array($right)
            && $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }

    public static function read(string $path, int $maximumBytes): ?string
    {
        if ($maximumBytes < 1 || $maximumBytes > 67108864) return null;
        $directory = self::directory($path);
        $before = $directory !== null ? self::file($path) : null;
        if ($before === null || $before['size'] > $maximumBytes) return null;
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) return null;
        try {
            $opened = @fstat($handle);
            if (!self::validFile($opened) || !self::same($before, $opened)
                || !@flock($handle, LOCK_SH)) return null;
            $body = stream_get_contents($handle, $maximumBytes + 1);
            $after = @fstat($handle);
            if (!is_string($body) || strlen($body) > $maximumBytes
                || !self::validFile($after) || !self::same($before, $after)
                || !self::same($before, self::file($path))
                || !self::same($directory, self::directory($path))) return null;
            return $body;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Atomically replace only a validated owned cache, never a link/device. */
    public static function write(string $path, string $body, int $maximumBytes): bool
    {
        if ($maximumBytes < 1 || $maximumBytes > 67108864 || strlen($body) > $maximumBytes) return false;
        $directory = self::directory($path);
        if ($directory === null || !self::absentOrValid($path)) return false;
        $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(16)) . '.tmp';
        $previousMask = umask(0077);
        try {
            $handle = @fopen($temporary, 'x+b');
        } finally {
            umask($previousMask);
        }
        if (!is_resource($handle)) return false;
        $created = @fstat($handle);
        try {
            if (!self::validFile($created) || !@flock($handle, LOCK_EX)) return false;
            $offset = 0;
            while ($offset < strlen($body)) {
                $written = @fwrite($handle, substr($body, $offset));
                if (!is_int($written) || $written < 1) return false;
                $offset += $written;
            }
            if (!@fflush($handle) || (function_exists('fsync') && !@fsync($handle))) return false;
            if (!self::same($directory, self::directory($path))
                || !self::same($created, self::file($temporary))
                || !self::absentOrValid($path)) return false;
            if (!@rename($temporary, $path)) return false;
            return self::same($created, self::file($path))
                && self::same($directory, self::directory($path));
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
            // Delete only the temp inode this call created, never a substituted path.
            if (self::same($created, self::file($temporary))) @unlink($temporary);
        }
    }

    /** Validated lock handle for callers that must serialize policy transitions. */
    public static function lock(string $path): mixed
    {
        $directory = self::directory($path);
        if ($directory === null) return null;
        $before = self::file($path);
        if ($before === null) {
            if (!self::absentOrValid($path)) return null;
            $previousMask = umask(0077);
            try {
                $handle = @fopen($path, 'x+b');
            } finally {
                umask($previousMask);
            }
            if (!is_resource($handle)) {
                // A concurrent legitimate creator may have won the exclusive create.
                $before = self::file($path);
                if ($before === null) return null;
                $handle = @fopen($path, 'r+b');
            }
        } else {
            $handle = @fopen($path, 'r+b');
        }
        if (!is_resource($handle)) return null;
        $opened = @fstat($handle);
        if (!self::validFile($opened)
            || ($before !== null && !self::same($before, $opened))
            || !self::same($opened, self::file($path))
            || !self::same($directory, self::directory($path))) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private static function absentOrValid(string $path): bool
    {
        clearstatcache(true, $path);
        return @lstat($path) === false || self::file($path) !== null;
    }
}
