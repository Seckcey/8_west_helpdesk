<?php
/**
 * Text arriving from the wild must reach utf8mb4 columns intact.
 *
 * The bug this pins: `Incorrect string value: '\x97 {tec…'` — fatal 500s on
 * snippets.php and ticket.php, from a Windows-1252 em dash reaching a
 * utf8mb4 column. Eleven of the fifteen free-text inputs never called
 * utf8_clean(), so whether a smart quote broke the app depended entirely on
 * which form you typed it into.
 *
 * The second, quieter bug: the old utf8_clean() replaced every bad byte with
 * '?'. It prevented the 500 and corrupted the text instead, with nothing
 * reporting it.
 *
 * Run:  php app/tests/utf8_input_test.php        (from the repo root)
 * Exit: 0 = all green, 1 = failures. Touches no database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$failures = 0;
$checks = 0;

function check(string $what, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    if (! $ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$what}\n");

        return;
    }
    fwrite(STDOUT, "ok: {$what}\n");
}

// The exact byte from the production error, plus the rest of the Windows-1252
// punctuation block that Word and Outlook emit.
$emDash = "\x97";
$curlyApostrophe = "\x92";
$openQuote = "\x93";
$closeQuote = "\x94";

$body = "Front desk PC {$emDash} won{$curlyApostrophe}t print {$openQuote}claim forms{$closeQuote}";

check('the raw input really is invalid UTF-8', ! mb_check_encoding($body, 'UTF-8'));

$cleaned = utf8_clean($body);

check('cleaned text is valid UTF-8', mb_check_encoding($cleaned, 'UTF-8'));
check('the em dash survives as an em dash', str_contains($cleaned, '—'));
check('the curly apostrophe survives', str_contains($cleaned, '’'));
check('the smart quotes survive', str_contains($cleaned, '“') && str_contains($cleaned, '”'));

// The regression that matters most: the previous implementation turned each
// of these into '?' and reported nothing.
check('no character was silently replaced with a question mark', ! str_contains($cleaned, '?'));

// Text that is already valid must pass through byte-for-byte — including
// characters outside the BMP, which a lossy conversion would mangle.
foreach ([
    'plain ascii',
    'already — curly ’ and “smart”',
    'accents: café niño Ærø',
    'emoji: 🚢 anchors aweigh',
    'CJK: 八西',
] as $good) {
    check("valid input is left untouched: {$good}", utf8_clean($good) === $good);
}

check('an empty string survives', utf8_clean('') === '');

// --- The boundary sweep -------------------------------------------------

$posted = [
    'subject' => "Printer {$emDash} jammed",
    'body' => "Won{$curlyApostrophe}t feed",
    'nested' => ['note' => "{$openQuote}urgent{$closeQuote}"],
    'count' => 3,
    'flag' => true,
    'nothing' => null,
];

$clean = utf8_clean_input($posted);

check('subject is coerced', mb_check_encoding($clean['subject'], 'UTF-8'));
check('body is coerced', mb_check_encoding($clean['body'], 'UTF-8'));
check('nested arrays are walked', mb_check_encoding($clean['nested']['note'], 'UTF-8'));
check('the em dash survives the sweep', str_contains($clean['subject'], '—'));

// Non-strings must come back as themselves, not stringified: an (int) cast
// downstream would still work, but in_array($_POST['flag'], [...], true)
// would quietly stop matching.
check('integers keep their type', $clean['count'] === 3);
check('booleans keep their type', $clean['flag'] === true);
check('nulls keep their type', $clean['nothing'] === null);

// A password is a byte string, not prose. Rewriting bytes inside one would
// change the secret, so it must pass through exactly as sent.
$raw = "corr{$emDash}horse";
$withPasswords = [
    'password' => $raw,
    'current_password' => $raw,
    'NEW_PASSWORD' => $raw,
    'body' => $raw,
];
$sweptPasswords = utf8_clean_input($withPasswords);

check('password is untouched', $sweptPasswords['password'] === $raw);
check('current_password is untouched', $sweptPasswords['current_password'] === $raw);
check('case does not matter when spotting a password', $sweptPasswords['NEW_PASSWORD'] === $raw);
check('a normal field beside them is still cleaned', $sweptPasswords['body'] !== $raw);

// Idempotence: pages still call utf8_clean() individually after the boundary
// has already run. A second pass must not damage what the first produced.
check('cleaning twice changes nothing', utf8_clean($cleaned) === $cleaned);
check('sweeping twice changes nothing', utf8_clean_input($clean) === $clean);

fwrite(STDOUT, "\n{$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
