<?php
/** Exercise the native HTTPS transport against an ephemeral local TLS server. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !function_exists('pcntl_fork')) { fwrite(STDERR, "Unix CLI with pcntl required\n"); exit(1); }
require_once __DIR__ . '/../lib/trusted_jwks.php';
use EightWest\Id\Security\TrustedJwks;
if (($argv[1] ?? '') === '--client') {
    $result = TrustedJwks::load($argv[2], $argv[3]);
    echo $result !== null && ($result['keys'][0]['kid'] ?? '') === 'tls-test' ? 'accepted' : 'refused';
    exit;
}
$root = sys_get_temp_dir() . '/eightwest-jwks-tls-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$config = <<<'INI'
[ req ]
distinguished_name = dn
[ dn ]
[ ca_extensions ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
[ server_extensions ]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:localhost
INI;
file_put_contents($root . '/openssl.cnf', $config);
$passed = 0; $failed = 0;
try {
    $options = ['config' => $root . '/openssl.cnf', 'digest_alg' => 'sha256', 'private_key_bits' => 2048];
    $caKey = openssl_pkey_new($options);
    $caRequest = openssl_csr_new(['commonName' => 'Synthetic JWKS test CA'], $caKey, $options);
    $ca = openssl_csr_sign($caRequest, null, $caKey, 1, $options + ['x509_extensions' => 'ca_extensions']);
    $serverKey = openssl_pkey_new($options);
    $serverRequest = openssl_csr_new(['commonName' => 'localhost'], $serverKey, $options);
    $serverCertificate = openssl_csr_sign($serverRequest, $ca, $caKey, 1, $options + ['x509_extensions' => 'server_extensions']);
    if (!$ca || !$serverCertificate
        || !openssl_x509_export_to_file($ca, $root . '/ca.pem')
        || !openssl_x509_export_to_file($serverCertificate, $root . '/server.pem')
        || !openssl_pkey_export_to_file($serverKey, $root . '/key.pem', null, $options)) {
        throw new RuntimeException('Synthetic TLS setup failed');
    }
    chmod($root . '/key.pem', 0600);
    $keys = json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'tls-test', 'n' => 'synthetic_modulus', 'e' => 'AQAB']]]);
    foreach ([
        ['trusted certificate and ordinary response', 'localhost', true, 200, $keys, true],
        ['untrusted certificate', 'localhost', false, 200, $keys, false],
        ['certificate hostname mismatch', '127.0.0.1', true, 200, $keys, false],
        ['redirect response', 'localhost', true, 302, $keys, false],
        ['HTTP error with otherwise valid keys', 'localhost', true, 500, $keys, false],
        ['oversized native response', 'localhost', true, 200, str_repeat('x', 262145), false],
    ] as $index => [$label, $hostname, $trust, $status, $body, $expected]) {
        $context = stream_context_create(['ssl' => ['local_cert' => $root . '/server.pem', 'local_pk' => $root . '/key.pem']]);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if (!is_resource($listener)) throw new RuntimeException('Synthetic TLS listener unavailable');
        $port = substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Synthetic TLS fork failed');
        if ($pid === 0) {
            $connection = @stream_socket_accept($listener, 5);
            if (is_resource($connection)) {
                stream_set_timeout($connection, 5);
                if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                    $request = '';
                    while (!str_contains($request, "\r\n\r\n") && strlen($request) < 8192) {
                        $line = fgets($connection); if (!is_string($line)) break; $request .= $line;
                    }
                    $headers = "HTTP/1.1 $status Test\r\nConnection: close\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
                    if ($status === 302) $headers .= "Location: https://localhost:$port/must-not-follow\r\n";
                    @fwrite($connection, $headers . "\r\n" . $body);
                }
                fclose($connection);
            }
            fclose($listener); exit(0);
        }
        fclose($listener);
        $caPath = $trust ? $root . '/ca.pem' : '/etc/ssl/certs/ca-certificates.crt';
        $command = [PHP_BINARY, '-d', 'openssl.cafile=' . $caPath, __FILE__, '--client', "https://$hostname:$port/jwks.json", $root . '/cache-' . $index . '.json'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Synthetic TLS client failed');
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]); $exit = proc_close($process);
        pcntl_waitpid($pid, $childStatus);
        if ($exit === 0 && $errors === '' && $output === ($expected ? 'accepted' : 'refused')) $passed++;
        else { $failed++; fwrite(STDERR, "FAIL native TLS: $label (exit $exit)\n"); }
    }
} finally {
    foreach (new DirectoryIterator($root) as $entry) if (!$entry->isDot()) unlink($entry->getPathname());
    rmdir($root);
}
echo "native JWKS HTTPS: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
