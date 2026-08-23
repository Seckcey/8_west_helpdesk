<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/jwt.php';

$failures = 0;
function rs_check(bool $ok, string $message): void { global $failures; if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); $failures++; } }

$options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$opensslConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
if (is_file($opensslConfig)) $options['config'] = $opensslConfig;
$key = openssl_pkey_new($options);
if ($key === false) { fwrite(STDOUT, "suite_rs256_test: skipped (OpenSSL unavailable)\n"); exit(0); }
$exportOptions = is_file($opensslConfig) ? ['config' => $opensslConfig] : [];
openssl_pkey_export($key, $private, null, $exportOptions);
$details = openssl_pkey_get_details($key);
$kid = 'rotation-test-key';
$jwks = ['keys' => [[
    'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid,
    'n' => b64url_encode($details['rsa']['n']), 'e' => b64url_encode($details['rsa']['e']),
]]];
$payload = ['iss' => 'https://id.test', 'sub' => 't1u1', 'email' => 'owner@example.test', 'exp' => time() + 300];
$header = b64url_encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]));
$body = b64url_encode((string) json_encode($payload));
openssl_sign($header . '.' . $body, $signature, $private, OPENSSL_ALGO_SHA256);
$token = $header . '.' . $body . '.' . b64url_encode($signature);

[$claims, $reason] = jwt_verify_rs256_reason($token, $jwks, 'https://id.test');
rs_check(is_array($claims) && $reason === null, 'valid RS256 token verifies');
rs_check(jwt_verify_rs256_reason($token, ['keys' => []], 'https://id.test')[1] === 'unknown_kid', 'unknown kid fails closed');
rs_check(jwt_verify_rs256_reason($token . 'x', $jwks, 'https://id.test')[1] === 'bad_signature', 'tampering is refused');

$hsBody = b64url_encode((string) json_encode($payload));
$wrongHeader = b64url_encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
$forgedHs = $wrongHeader . '.' . $hsBody . '.' . b64url_encode(hash_hmac('sha256', $wrongHeader . '.' . $hsBody, 'secret', true));
rs_check(jwt_verify_reason($forgedHs, 'secret', 'https://id.test')[1] === 'unexpected_algorithm', 'HS verifier pins its algorithm');
rs_check(jwt_verify($forgedHs, 'secret', 'https://id.test') === null, 'legacy HS verifier also pins its algorithm');

exit($failures === 0 ? 0 : 1);
