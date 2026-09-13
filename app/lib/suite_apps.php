<?php
/**
 * Safeharbor — the 8 West IT 365 app drawer (the 3x3 "squares"): what it may show, and nothing more.
 *
 * PURE on purpose: no db(), no cfg(), no bootstrap, no session reads — every function takes its
 * inputs as arguments, so app/tests/suite_chrome_test.php proves the entitlement filtering without
 * credentials, a database, or a signed token.
 *
 * WHAT THIS IS. The suite-wide launcher. Its markup, styling and behaviour are the shared w365
 * package (Seckcey/8_west_suite_ui) vendored under public/assets/w365/ — the same squares and
 * drawer every 8 West IT 365 app renders at the right end of its page header — and the PRODUCT
 * REGISTRY is the package's w365-products.json, generated from 8 West ID's canonical catalog,
 * versioned with the package and pinned by app/tests/w365_vendored_test.php. Safeharbor is a
 * CONSUMER of that registry, never its owner: the hard-coded "8 West Suite" list that used to sit
 * in the sidebar (Milepost, Coastmark, All apps) had already drifted from the catalog, which is
 * exactly why it is gone.
 *
 * ENTITLEMENT IS THE WHOLE POINT. The drawer shows ONLY the products the signed-in person is
 * licensed for — the same `8west:products` claim auth.php already validates before admitting a
 * suite sign-in (docs/suite-sso-contract.md). Rendering a fixed list of every suite app would
 * advertise apps the viewer may not be licensed for.
 *
 * THE LOCAL-LOGIN CASE. Safeharbor also has its own bcrypt accounts. A person who signed in with
 * a local password presents no verified suite identity, so there is genuinely NO entitlement list
 * to read. That case renders a sign-in prompt — never a guessed list. "We don't know your apps"
 * is the honest answer, and it is not the same as "you have no apps".
 *
 * THE CURRENT APP. The drawer lists OTHER apps: Safeharbor is left out of its own drawer, so a
 * viewer licensed for Safeharbor alone reads "No other 8 West IT 365 apps are licensed for your
 * account", which is true.
 */
declare(strict_types=1);

/** The product key 8 West ID uses for this app — left out of its own drawer. */
const SUITE_APPS_CURRENT_KEY = 'safeharbor';

/**
 * The product registry: key => presentation, read from the vendored w365-products.json.
 *
 * Closed-world and fail-closed: a missing or malformed file yields an EMPTY registry (the drawer
 * then honestly shows no apps; the vendoring guard fails the build before that can ship), a
 * product with a non-https entry URL or a key that is not a durable claim key is dropped, and the
 * mark is reduced to a bare same-origin FILE NAME so a registry entry can never aim the browser
 * at another host.
 *
 * `url` is each product's SUITE ENTRY POINT, not its app root — several apps only read the shared
 * `ewid_token` cookie on a dedicated route. Those values come from 8 West ID's catalog through
 * the package generator, so they cannot drift here.
 *
 * @return array<string,array{name:string,full_name:string,url:string,desc:string,mark:string}>
 */
function suite_app_registry(?string $registryFile = null): array
{
    $file = $registryFile ?? (__DIR__ . '/../public/assets/w365/w365-products.json');
    $raw = is_file($file) ? file_get_contents($file) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $products = is_array($decoded) && is_array($decoded['products'] ?? null) ? $decoded['products'] : [];

    $out = [];
    foreach ($products as $p) {
        if (!is_array($p)) {
            continue;
        }
        $key = $p['key'] ?? null;
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) !== 1) {
            continue;
        }
        $url = $p['url'] ?? null;
        if (!is_string($url) || !str_starts_with($url, 'https://')) {
            continue;
        }
        $mark = basename((string)($p['mark'] ?? ''));
        if (preg_match('/^[a-z0-9-]+\.png$/D', $mark) !== 1) {
            $mark = '';
        }
        $name = trim((string)($p['short_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($p['name'] ?? $key));
        }
        $out[$key] = [
            'name'      => $name,
            'full_name' => trim((string)($p['name'] ?? $name)),
            'url'       => $url,
            'desc'      => (string)($p['desc'] ?? ''),
            'mark'      => $mark,
        ];
    }
    return $out;
}

/**
 * Normalise the raw `8west:products` claim into a clean list of known product keys.
 *
 * Fail-closed and closed-world: a non-array claim yields []; non-string members are dropped; and
 * a key absent from the registry is DROPPED rather than rendered, because Safeharbor has no name,
 * URL or artwork for it — a tile it cannot describe is worse than no tile. (8 West ID gaining a
 * new product therefore shows nothing here until the package is bumped: a visible gap, not a
 * broken link.)
 *
 * @param mixed $claim the raw 8west:products value from the verified token
 * @return array<int,string> registry keys, order preserved, de-duplicated
 */
function suite_apps_entitled(mixed $claim, array $registry): array
{
    if (!is_array($claim)) {
        return [];
    }
    $out = [];
    foreach ($claim as $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (!array_key_exists($value, $registry)) {
            continue;
        }
        if (!in_array($value, $out, true)) {
            $out[] = $value;
        }
    }
    return $out;
}

/**
 * The drawer's state for a request. Three honest outcomes, never a fabricated list:
 *
 *   'apps'     — a verified suite session with at least one OTHER known entitled product.
 *   'none'     — a verified suite session entitled to nothing else this registry knows about.
 *   'no_suite' — no suite session at all (a local Safeharbor login). We do not know their apps.
 *
 * @param bool        $hasSuiteSession whether the request carries a verified 8 West ID identity
 * @param mixed       $productsClaim   the raw 8west:products claim (ignored without a suite session)
 * @param string|null $currentKey      this app's own product key, left out of its own drawer
 * @return array{state:string,apps:array<int,array{key:string,name:string,url:string,desc:string,mark:string}>}
 */
function suite_apps_drawer(bool $hasSuiteSession, mixed $productsClaim, array $registry, ?string $currentKey = SUITE_APPS_CURRENT_KEY): array
{
    if (!$hasSuiteSession) {
        return ['state' => 'no_suite', 'apps' => []];
    }

    $apps = [];
    foreach (suite_apps_entitled($productsClaim, $registry) as $key) {
        if ($currentKey !== null && $key === $currentKey) {
            continue;
        }
        $entry = $registry[$key];
        $apps[] = [
            'key'  => $key,
            'name' => (string)($entry['name'] ?? $key),
            'url'  => (string)($entry['url'] ?? ''),
            'desc' => (string)($entry['desc'] ?? ''),
            // Bare FILE NAME only — never a URL. The renderer prefixes the vendored marks/ path, so
            // a registry entry can never point the browser at another origin.
            'mark' => (string)($entry['mark'] ?? ''),
        ];
    }

    return ['state' => $apps === [] ? 'none' : 'apps', 'apps' => $apps];
}

/** The one-letter mark shown on a tile when its artwork is missing — the product's initial. */
function suite_app_initial(string $name): string
{
    $trimmed = trim($name);
    return $trimmed === '' ? '?' : mb_strtoupper(mb_substr($trimmed, 0, 1));
}

/**
 * Initials for the account button's letter mark, by the w365 identity rule: the first letters of
 * the first and last word, one letter for a single word, upper-cased. (Deliberately not
 * initials_of() from bootstrap.php, which doubles a single word — "seckcey" → "SS".)
 */
function suite_name_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    if ($parts === []) {
        return '?';
    }
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}
