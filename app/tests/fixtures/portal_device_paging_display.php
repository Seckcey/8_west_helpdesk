<?php
/** Synthetic page rendering only: no sign-in, database or computer service. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
function cfg(string $key, mixed $default = null): mixed { return $default; }
function portal_csrf_token(): string { return 'synthetic-csrf'; }
function portal_action_nonce(string $purpose, ?int $now = null): string { return 'synthetic-nonce'; }
require_once __DIR__.'/../../lib/portal_devices_render.php';
function portal_device_paging_display(string $state, string $theme = 'light'): void
{
    $role = match ($state) {
        'later-empty-viewer'=>'client_viewer', 'later-empty-member'=>'client_staff',
        'later-empty-admin'=>'client_admin', default=>'client_owner',
    };
    $context = ['identity'=>['display_name'=>'Example member','role'=>$role,'theme'=>$theme],
        'binding'=>['client_name'=>'Example client']];
    $after = str_starts_with($state, 'later-') ? 50 : 0;
    $_SERVER['REQUEST_URI'] = '/portal/devices.php' . ($after > 0 ? '?after='.$after : '');
    $devices = ['items'=>[], 'next_after'=>null];
    if ($state === 'later-populated') $devices = ['items'=>[[
        'reference'=>'51:'.str_repeat('a',64), 'label'=>'Example office computer', 'platform'=>'Windows 11',
        'connection'=>'reporting', 'connection_label'=>'Connected and reporting', 'connection_help'=>'',
        'last_seen_at'=>null,
    ]], 'next_after'=>100];
    $download = $state === 'later-download' ? [
        'download_url'=>'https://support.example.test/synthetic-setup',
        'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),
    ] : null;
    portal_render_devices($context, $state === 'later-error' ? null : $devices, [],
        null, $download, null, null, null, null, $after);
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) portal_device_paging_display($argv[1] ?? 'later-empty-owner', $argv[2] ?? 'light');
