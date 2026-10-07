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
$rsTokenFor = static function (array $claims, array $headerOverrides = []) use ($kid, $private): string {
    $header = b64url_encode((string) json_encode(array_replace(
        ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid],
        $headerOverrides,
    ), JSON_THROW_ON_ERROR));
    $body = b64url_encode((string) json_encode($claims, JSON_THROW_ON_ERROR));
    openssl_sign($header . '.' . $body, $signature, $private, OPENSSL_ALGO_SHA256);
    return $header . '.' . $body . '.' . b64url_encode($signature);
};
$token = $rsTokenFor($payload);

[$claims, $reason] = jwt_verify_rs256_reason($token, $jwks, 'https://id.test');
rs_check(is_array($claims) && $reason === null, 'valid RS256 token verifies');
rs_check(jwt_verify_rs256_reason($token, ['keys' => []], 'https://id.test')[1] === 'unknown_kid', 'unknown kid fails closed');
rs_check(jwt_verify_rs256_reason($token . 'x', $jwks, 'https://id.test')[1] === 'bad_signature', 'tampering is refused');

$objectAmrPayload = array_replace($payload, ['amr' => (object) ['0' => 'passkey']]);
$emptyObjectAmrPayload = array_replace($payload, ['amr' => (object) []]);
$passkeyArrayPayload = array_replace($payload, ['amr' => ['passkey']]);
$objectProductsPayload = array_replace($payload, ['8west:products' => (object) ['0' => 'safeharbor']]);
$validProductsPayload = array_replace($payload, ['8west:products' => ['safeharbor', 'future_product']]);
rs_check(
    jwt_verify_rs256_reason($rsTokenFor($passkeyArrayPayload), $jwks, 'https://id.test')[1] === null,
    'RS256 passkey array remains valid',
);
rs_check(
    jwt_verify_rs256_reason($rsTokenFor($objectAmrPayload), $jwks, 'https://id.test')[1] === 'amr_invalid',
    'RS256 object-shaped AMR is refused',
);
rs_check(
    jwt_verify_rs256_reason($rsTokenFor($emptyObjectAmrPayload), $jwks, 'https://id.test')[1] === 'amr_invalid',
    'RS256 empty-object AMR is refused',
);
rs_check(
    jwt_verify_rs256_reason($rsTokenFor(array_replace($payload, ['amr' => ['passkey', 'passkey']])), $jwks, 'https://id.test')[1] === 'amr_invalid',
    'RS256 duplicate AMR is refused',
);
rs_check(
    jwt_verify_rs256_reason($rsTokenFor(array_replace($payload, ['amr' => ['passkey', 'future_factor']])), $jwks, 'https://id.test')[1] === 'amr_invalid',
    'RS256 unknown AMR is refused',
);
rs_check(
    jwt_verify_rs256_reason($rsTokenFor($validProductsPayload), $jwks, 'https://id.test')[1] === null,
    'RS256 canonical product list remains valid',
);
rs_check(
    jwt_verify_rs256_reason($rsTokenFor($objectProductsPayload), $jwks, 'https://id.test')[1] === 'products_invalid',
    'RS256 object-shaped products are refused',
);
rs_check(
    jwt_verify_rs256_reason($rsTokenFor(array_replace($payload, ['8west:products' => ['safeharbor', 'safeharbor']])), $jwks, 'https://id.test')[1] === 'products_invalid',
    'RS256 duplicate products are refused',
);
rs_check(
    jwt_verify_rs256_reason($rsTokenFor(array_replace($payload, [
        'aud' => 'another-oidc-client',
        'nonce' => 'transaction-nonce',
    ])), $jwks, 'https://id.test')[1] === 'token_type_invalid',
    'RS256 OIDC token cannot be reused as a suite cookie',
);
foreach ([(object) [], ['future_extension']] as $critical) {
    rs_check(
        jwt_verify_rs256_reason($rsTokenFor($payload, ['crit' => $critical]), $jwks, 'https://id.test')[1] === 'unexpected_algorithm',
        'RS256 unsupported or object-shaped critical header is refused',
    );
}
rs_check(
    jwt_verify_rs256_reason($rsTokenFor($payload, ['crit' => []]), $jwks, 'https://id.test')[1] === null,
    'RS256 explicit empty critical-header list remains valid',
);

$hsBody = b64url_encode((string) json_encode($payload));
$wrongHeader = b64url_encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
$forgedHs = $wrongHeader . '.' . $hsBody . '.' . b64url_encode(hash_hmac('sha256', $wrongHeader . '.' . $hsBody, 'secret', true));
rs_check(jwt_verify_reason($forgedHs, 'secret', 'https://id.test')[1] === 'unexpected_algorithm', 'HS verifier pins its algorithm');
rs_check(jwt_verify($forgedHs, 'secret', 'https://id.test') === null, 'legacy HS verifier also pins its algorithm');

$hsTokenFor = static function (array $claims, array $headerOverrides = []): string {
    $hsHeader = b64url_encode((string) json_encode(array_replace(
        ['alg' => 'HS256', 'typ' => 'JWT'],
        $headerOverrides,
    ), JSON_THROW_ON_ERROR));
    $body = b64url_encode((string) json_encode($claims, JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $hsHeader . '.' . $body, 'secret', true);
    return $hsHeader . '.' . $body . '.' . b64url_encode($signature);
};
rs_check(
    jwt_verify_reason($hsTokenFor($passkeyArrayPayload), 'secret', 'https://id.test')[1] === null,
    'HS256 passkey array remains valid',
);
rs_check(
    jwt_verify($hsTokenFor($passkeyArrayPayload), 'secret', 'https://id.test') !== null,
    'reasonless HS256 verifier preserves valid passkey arrays',
);
rs_check(
    jwt_verify_reason($hsTokenFor($objectAmrPayload), 'secret', 'https://id.test')[1] === 'amr_invalid',
    'HS256 object-shaped AMR is refused',
);
rs_check(
    jwt_verify_reason($hsTokenFor($emptyObjectAmrPayload), 'secret', 'https://id.test')[1] === 'amr_invalid',
    'HS256 empty-object AMR is refused',
);
rs_check(
    jwt_verify($hsTokenFor($objectAmrPayload), 'secret', 'https://id.test') === null,
    'reasonless HS256 verifier refuses object-shaped AMR',
);
rs_check(
    jwt_verify_reason($hsTokenFor($objectProductsPayload), 'secret', 'https://id.test')[1] === 'products_invalid',
    'HS256 object-shaped products are refused',
);
rs_check(
    jwt_verify_reason($hsTokenFor(array_replace($payload, ['8west:products' => ['safeharbor', 'safeharbor']])), 'secret', 'https://id.test')[1] === 'products_invalid',
    'HS256 duplicate products are refused',
);
foreach ([
    ['aud' => 'another-oidc-client'],
    ['nonce' => 'transaction-nonce'],
    ['azp' => 'another-oidc-client'],
] as $oidcOnlyClaim) {
    rs_check(
        jwt_verify_reason($hsTokenFor(array_replace($payload, $oidcOnlyClaim)), 'secret', 'https://id.test')[1] === 'token_type_invalid',
        'HS256 OIDC-only claim marks a different token type',
    );
}
foreach ([(object) [], ['future_extension']] as $critical) {
    rs_check(
        jwt_verify_reason($hsTokenFor($payload, ['crit' => $critical]), 'secret', 'https://id.test')[1] === 'unexpected_algorithm',
        'HS256 unsupported or object-shaped critical header is refused',
    );
    rs_check(
        jwt_verify($hsTokenFor($payload, ['crit' => $critical]), 'secret', 'https://id.test') === null,
        'reasonless HS256 verifier refuses unsupported critical headers',
    );
}
rs_check(
    jwt_verify_reason($hsTokenFor($payload, ['crit' => []]), 'secret', 'https://id.test')[1] === null,
    'HS256 explicit empty critical-header list remains valid',
);
$suiteConfig = [
    'token_algorithms' => ['HS256'],
    'issuer' => 'https://id.test',
    'sso_secret' => 'secret',
];
rs_check(
    jwt_verify_suite_reason($hsTokenFor($validProductsPayload, ['crit' => []]), $suiteConfig)[1] === null,
    'suite-cookie wrapper preserves a valid explicit empty critical-header list',
);
rs_check(
    jwt_verify_suite_reason($hsTokenFor($payload, ['crit' => (object) []]), $suiteConfig)[1]
        === 'unexpected_algorithm',
    'suite-cookie wrapper refuses an object-shaped critical header',
);
rs_check(
    jwt_verify_suite_reason($hsTokenFor(array_replace($payload, ['aud' => 'another-oidc-client'])), $suiteConfig)[1]
        === 'token_type_invalid',
    'suite-cookie wrapper refuses OIDC token reuse',
);
$jwksDirectory = sys_get_temp_dir() . '/safeharbor-suite-rs256-test-'
    . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($jwksDirectory, 0700)) {
    throw new RuntimeException('Could not create private JWKS test directory');
}
$jwksCache = $jwksDirectory . '/jwks.json';
register_shutdown_function(static function () use ($jwksCache, $jwksDirectory): void {
    @unlink($jwksCache);
    @rmdir($jwksDirectory);
});
$jwksEnvelope = json_encode([
    'schema_version' => 1,
    'source' => 'https://id.test/.well-known/jwks.json',
    'fetched_at' => time(),
    'jwks' => $jwks,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($jwksCache, $jwksEnvelope) !== strlen($jwksEnvelope)
    || !chmod($jwksCache, 0600)) {
    throw new RuntimeException('Could not write private JWKS test envelope');
}
$rsSuiteConfig = [
    'token_algorithms' => ['RS256'],
    'issuer' => 'https://id.test',
    'jwks_url' => 'https://id.test/.well-known/jwks.json',
    'jwks_cache_path' => $jwksCache,
];
rs_check(
    jwt_verify_suite_reason($rsTokenFor($validProductsPayload, ['crit' => []]), $rsSuiteConfig)[1] === null,
    'production RS256 suite-cookie wrapper preserves a valid token',
);
rs_check(
    jwt_verify_suite_reason($rsTokenFor($payload, ['crit' => (object) []]), $rsSuiteConfig)[1]
        === 'unexpected_algorithm',
    'production RS256 suite-cookie wrapper refuses an object-shaped critical header',
);
rs_check(
    jwt_verify_suite_reason($rsTokenFor(array_replace($payload, ['nonce' => 'oidc-nonce'])), $rsSuiteConfig)[1]
        === 'token_type_invalid',
    'production RS256 suite-cookie wrapper refuses OIDC token reuse',
);
@unlink($jwksCache);
@rmdir($jwksDirectory);

exit($failures === 0 ? 0 : 1);
