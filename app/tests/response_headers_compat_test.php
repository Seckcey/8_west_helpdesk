<?php
/** PHP 8.5 deprecates the magic $http_response_header; production still runs older PHP. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../lib/eightwestid/http.php';
$checks = 0;
function headers_check(bool $ok, string $name): void { global $checks; if (! $ok) throw new RuntimeException('FAIL ' . $name); $checks++; }
$name = '$http_' . 'response_header';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../lib', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') continue;
    $named = array_filter(token_get_all((string) file_get_contents($file->getPathname())),
        static fn($token): bool => is_array($token) && $token[0] === T_VARIABLE && $token[1] === $name);
    headers_check($named === [], 'application code never names the deprecated variable: ' . $file->getFilename());
}
if (! function_exists('http_get_last_response_headers')) {
    headers_check(EightWest\Id\last_response_headers(['HTTP/1.1 200 OK', 7, 'X-Probe: yes']) === ['HTTP/1.1 200 OK', 'X-Probe: yes'],
        'older PHP uses the caller-supplied headers, strings only');
}
headers_check(EightWest\Id\last_response_headers(null) === [] || function_exists('http_get_last_response_headers'),
    'no request means no headers');
echo "PASS $checks response header compatibility checks\n";
