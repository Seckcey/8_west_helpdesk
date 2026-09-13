<?php
/**
 * Safeharbor — the shared suite chrome (the w365 cluster): unit tests. CLI only. HERMETIC and DB-FREE.
 *
 *   php app/tests/suite_chrome_test.php
 *
 * suite_chrome_drawer() and suite_chrome_account_menu() only ever run for a signed-in technician, so
 * no browser contract and no other suite exercises them. This file lifts the two renderers out of
 * lib/render.php (which cannot be required without bootstrap, config and a database), evaluates them
 * against the REAL lib/suite_apps.php and the REAL vendored registry, and drives them through every
 * session shape with warnings promoted to exceptions. A fatal in the page header would take every
 * authenticated page down; this is the net.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$PASS = 0; $FAIL = 0;
function t(string $name, bool $cond): void
{
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n"; }
}

// The one helper the lifted renderers call. Same escaping contract as lib/bootstrap.php.
if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** Lift one top-level function's source out of render.php by brace matching (no bootstrap needed). */
function lift_function(string $src, string $name): string
{
    $at = strpos($src, "function $name(");
    if ($at === false) { throw new RuntimeException("render.php no longer defines $name"); }
    $open = strpos($src, '{', $at);
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
    }
    return substr($src, $at, $i - $at + 1);
}

$renderPath = __DIR__ . '/../lib/render.php';
$render = (string)file_get_contents($renderPath);
// The lifted code resolves __DIR__ relative to THIS file, so point its require at lib/ explicitly.
$lifted = str_replace(
    "__DIR__ . '/suite_apps.php'",
    var_export(__DIR__ . '/../lib/suite_apps.php', true),
    lift_function($render, 'suite_chrome_drawer') . "\n" . lift_function($render, 'suite_chrome_account_menu')
);
eval($lifted);

function render_cluster(array $session, array $user): string
{
    $_SESSION = $session;
    ob_start();
    suite_chrome_drawer();
    suite_chrome_account_menu($user);
    return (string)ob_get_clean();
}

$local = ['full_name' => 'Local Person', 'email' => 'local@example.test', 'role' => 'tech', 'suite_subject' => null];
$staff = ['full_name' => 'Frank', 'email' => 'safeharbor-account@example.test', 'role' => 'admin', 'suite_subject' => 't1u1'];
$suite = [
    'suite_products' => ['safeharbor', 'milepost', 'coastmark', 'logbook'],
    'suite_name' => 'Frankie Gonzalez',
    'suite_email' => 'frank@8westventures.com',
    'suite_avatar' => 'https://id.8westit.com/uploads/avatars/u1-0123456789abcdef0123456789abcdef.png',
    'suite_avatar_subject' => 't1u1',
];

/* ── A. Every state renders without a warning and carries both halves of the cluster. ──────────── */
$htmlSuite = render_cluster($suite, $staff);
$htmlNone = render_cluster(['suite_products' => ['safeharbor'], 'suite_name' => 'Frankie Gonzalez'], $staff);
$htmlLocal = render_cluster([], $local);
foreach (['suite' => $htmlSuite, 'none' => $htmlNone, 'local' => $htmlLocal] as $label => $html) {
    t("A[$label]: the drawer button and the account button are both rendered",
        substr_count($html, 'data-w365-drawer-button') === 1 && substr_count($html, 'data-w365-account-button') === 1);
    t("A[$label]: every value is escaped through h() (no raw markup from data)", !str_contains($html, '<script'));
}
t('A: a name carrying markup is escaped, not injected',
    str_contains(render_cluster(['suite_products' => [], 'suite_name' => '<img src=x onerror=alert(1)>'], $staff), '&lt;img src=x'));

/* ── B. The drawer: entitlement-driven, current app excluded, honest states, same-origin marks. ── */
t('B1: a suite session lists the OTHER licensed apps (Safeharbor itself excluded)',
    substr_count($htmlSuite, 'class="w365-tile"') === 3
    && str_contains($htmlSuite, '>Milepost<') && str_contains($htmlSuite, '>Logbook<')
    && !str_contains($htmlSuite, '>Safeharbor<'));
t('B2: every tile mark is a same-origin file under /assets/w365/marks/',
    preg_match_all('/<img[^>]*src="([^"]+)"/', $htmlSuite, $m) > 0
    && count(array_filter($m[1], static fn (string $src): bool =>
        !str_starts_with($src, '/assets/w365/marks/') && !str_starts_with($src, 'https://id.8westit.com/'))) === 0);
t('B3: licensed for Safeharbor alone reads "No other … apps"',
    str_contains($htmlNone, 'No other 8 West IT 365 apps are licensed'));
t('B4: a local login gets the sign-in prompt and no launcher home link',
    str_contains($htmlLocal, 'Sign in with 8 West ID') && !str_contains($htmlLocal, '8 West IT 365 home'));
t('B5: both suite states carry the launcher home link',
    str_contains($htmlSuite, 'https://id.8westit.com/?apps=1') && str_contains($htmlNone, 'https://id.8westit.com/?apps=1'));
t('B6: an unknown product key is dropped rather than rendered as a nameless tile',
    !str_contains(render_cluster(['suite_products' => ['safeharbor', 'not_a_real_product']], $staff), 'class="w365-tile"'));
t('B7: the drawer never carries an inline event handler', !preg_match('/\son(error|click|load)=/i', $htmlSuite));

/* ── C. Identity: the same person in every suite app. ─────────────────────────────────────────── */
t('C1: with a suite session the menu shows the 8 West ID name, not the Safeharbor account name',
    str_contains($htmlSuite, '<strong>Frankie Gonzalez</strong>') && !str_contains($htmlSuite, '<strong>Frank</strong>'));
t('C2: the secondary line is the 8 West ID email plus the Safeharbor role',
    str_contains($htmlSuite, 'frank@8westventures.com · Administrator'));
t('C3: the 8 West ID picture is rendered from id.8westit.com',
    str_contains($htmlSuite, '<img class="w365-avatar" src="https://id.8westit.com/uploads/avatars/u1-0123456789abcdef0123456789abcdef.png"'));
t('C4: the button names the person for assistive technology',
    str_contains($htmlSuite, 'aria-label="Account menu for Frankie Gonzalez"'));
t('C5: Suite settings is offered only with a suite session',
    str_contains($htmlSuite, 'Suite settings') && !str_contains($htmlLocal, 'Suite settings'));
t('C6: a local login shows the Safeharbor account name and its initials',
    str_contains($htmlLocal, '<strong>Local Person</strong>')
    && str_contains($htmlLocal, '<span class="w365-avatar" aria-hidden="true">LP</span>'));
t('C7: Settings points at this app\'s profile page and Sign out at its logout',
    str_contains($htmlSuite, 'href="/profile.php">Settings<') && str_contains($htmlSuite, 'href="/logout.php">Sign out<'));
t('C8: the owner role reads as Owner',
    str_contains(render_cluster($suite, ['full_name' => 'Frank', 'email' => 'a@b.test', 'role' => 'owner', 'suite_subject' => 't1u1']), '· Owner'));

/* ── D. The picture is only ever this person's own, and only from 8 West ID. ─────────────────── */
$other = $suite; $other['suite_avatar_subject'] = 't1u9';
t('D1: an avatar belonging to a different subject is refused (initials instead)',
    !str_contains(render_cluster($other, $staff), '<img class="w365-avatar"'));
$evil = $suite; $evil['suite_avatar'] = 'https://evil.example/x.png';
t('D2: a picture on another host is refused', !str_contains(render_cluster($evil, $staff), '<img class="w365-avatar"'));
$scheme = $suite; $scheme['suite_avatar'] = '//id.8westit.com.evil.example/x.png';
t('D3: a scheme-relative picture is refused', !str_contains(render_cluster($scheme, $staff), '<img class="w365-avatar"'));
$lookalike = $suite; $lookalike['suite_avatar'] = 'https://id.8westit.com.evil.example/x.png';
t('D4: a look-alike host is refused (the origin match includes the trailing slash)',
    !str_contains(render_cluster($lookalike, $staff), '<img class="w365-avatar"'));
t('D5: a local login never renders a picture', !str_contains($htmlLocal, '<img class="w365-avatar"'));

/* ── E. Initials, by the w365 rule: first and last word, one letter for one word. ─────────────── */
t('E1: two words → two initials', suite_name_initials('Frankie Gonzalez') === 'FG');
t('E2: one word → one initial (not doubled)', suite_name_initials('seckcey') === 'S');
t('E3: three words → first and last', suite_name_initials('Mary Ann Lee') === 'ML');
t('E4: blank → placeholder', suite_name_initials('   ') === '?');

/* ── F. The layout itself: placement, load order, and no second way to switch apps. ───────────── */
$topbar = substr($render, strpos($render, '<header class="topbar">'), strpos($render, '</header>') - strpos($render, '<header class="topbar">'));
t('F1: the cluster sits at the right end of the topbar, after Safeharbor\'s own controls',
    strpos($topbar, 'timer-widget') !== false
    && strpos($topbar, '<div class="w365-cluster">') > strpos($topbar, 'timer-widget')
    && strpos($topbar, 'suite_chrome_drawer();') < strpos($topbar, 'suite_chrome_account_menu($user);'));
$cssTag = strpos($render, '/assets/w365/w365.css?v=');
t('F2: the package stylesheet loads from this origin, before app.css',
    $cssTag !== false && strpos($render, '/assets/css/app.css?v=') > $cssTag);
t('F3: the shared launcher is loaded deferred from this origin',
    str_contains($render, '<script src="/assets/w365/w365-launcher.js?v=20260913a" defer></script>'));
t('F4: nothing in the layout hot-loads the package from another origin',
    preg_match('~https?://[^"\']*w365~', $render) !== 1);
$sidebar = substr($render, strpos($render, '<aside class="sidebar"'), strpos($render, '</aside>') - strpos($render, '<aside class="sidebar"'));
t('F5: the sidebar no longer carries a hard-coded app list, identity, or Sign out',
    !preg_match('/class="suite-item|usermenu|sidebar-foot|logout\.php/', $sidebar));
t('F6: the mobile navigation anchors the browser contract pins',
    str_contains($sidebar, 'class="mobile-nav-close"') && str_contains($render, 'id="suite-sidebar"'));
$css = (string)file_get_contents(__DIR__ . '/../public/assets/css/app.css');
t('F7: the cluster takes Safeharbor colours through the hooks',
    str_contains($css, '--w365-surface: var(--bg-solid);') && str_contains($css, '--w365-text: var(--text);'));
t('F8: the dead sidebar suite and user-menu styles are gone',
    !preg_match('/^\.(suite-label|suite-item|suite-box|suite-tag|usermenu|um-|sidebar-foot|foot-name)/m', $css));
$js = (string)file_get_contents(__DIR__ . '/../public/assets/js/app.js');
t('F9: the old user-menu script is gone; the shared launcher wires the panels',
    !str_contains($js, '#usermenu-btn'));

/* ── G. How the identity and the app list REACH the session, from lib/auth.php. ───────────────── */
// The two session writers are lifted the same way. suite_sso_claims() is stubbed with an already
// "verified" claim set, which is exactly what the real one returns: this suite proves the rules
// about WHICH claims are adopted, not the signature check (suite_sso_test.php owns that).
require_once __DIR__ . '/../lib/suite_preferences.php';
$auth = (string)file_get_contents(__DIR__ . '/../lib/auth.php');
$STUB_CLAIMS = null;
function suite_sso_claims(): ?array { global $STUB_CLAIMS; return $STUB_CLAIMS; }
eval(lift_function($auth, 'suite_session_identity_forget') . "\n" . lift_function($auth, 'suite_sso_refresh_claims'));

$tokenClaims = [
    'sub' => 't1u1', 'name' => 'Frankie Gonzalez', 'email' => 'frank@8westventures.com',
    '8west:products' => ['safeharbor', 'milepost'], '8west:avatar' => 'https://id.8westit.com/a.png',
    '8west:theme' => 'dark', 'exp' => 2000000000,
];

/** Run one refresh against a stubbed token and return the session it leaves behind. */
function refresh_with(?array $claims, array $session, ?string $expectedSubject): array
{
    global $STUB_CLAIMS;
    $STUB_CLAIMS = $claims;
    $_SESSION = $session;
    suite_sso_refresh_claims($expectedSubject);
    return $_SESSION;
}

$afterSuite = refresh_with($tokenClaims, [], 't1u1');
t('G1: a matching token writes the entitlement list and the 8 West ID identity',
    ($afterSuite['suite_products'] ?? null) === ['safeharbor', 'milepost']
    && ($afterSuite['suite_name'] ?? null) === 'Frankie Gonzalez'
    && ($afterSuite['suite_email'] ?? null) === 'frank@8westventures.com');
t('G2: the account menu and drawer render the suite state from exactly those values',
    str_contains(render_cluster($afterSuite, $staff), '<strong>Frankie Gonzalez</strong>'));

$noProducts = $tokenClaims; unset($noProducts['8west:products']);
t('G3: a token with no products claim still marks a suite session, with an empty list',
    (refresh_with($noProducts, [], 't1u1')['suite_products'] ?? null) === []);

t('G4: no token at all forgets a previous identity (the drawer stops guessing)',
    !array_key_exists('suite_products', refresh_with(null, $afterSuite, 't1u1')));
t('G5: a token for somebody else is not adopted',
    !array_key_exists('suite_products', refresh_with($tokenClaims, $afterSuite, 't1u9')));
t('G6: a local account (no subject of its own) never borrows the browser\'s token identity',
    !array_key_exists('suite_products', refresh_with($tokenClaims, $afterSuite, '')));

$stale = $afterSuite; $stale['suite_claims_etag'] = 't1u1|dark|https://id.8westit.com/a.png|null|2000000000';
$revoked = $tokenClaims; $revoked['8west:products'] = ['safeharbor'];
t('G7: a revoked product reaches the drawer even when the cached etag is unchanged',
    (refresh_with($revoked, $stale, 't1u1')['suite_products'] ?? null) === ['safeharbor']);

t('G8: the theme and preference copies are untouched by the identity rules',
    (refresh_with($tokenClaims, [], 't1u1')['suite_theme'] ?? null) === 'dark');

t('G9: a local password login forgets the copied identity',
    preg_match('/function attempt_login[\s\S]*?suite_session_identity_forget\(\);/', $auth) === 1);

echo "\n";
if ($FAIL > 0) { echo "SUITE CHROME TESTS FAILED: $FAIL of " . ($PASS + $FAIL) . " checks failed.\n"; exit(1); }
echo "SUITE CHROME TESTS PASSED: all $PASS checks passed.\n";
