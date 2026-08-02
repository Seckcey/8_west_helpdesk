<?php
/**
 * One-click CSAT — the ONLY unauthenticated page besides login. The token
 * in the resolution email is the whole auth; first click records the score,
 * an optional comment can follow, later clicks just show the thank-you.
 *   GET  /csat.php?t=TOKEN&s=1|2|3
 *   POST /csat.php  (t, comment)
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
enforce_https();

$token = (string)($_REQUEST['t'] ?? '');
$row = null;
if (preg_match('/^[a-f0-9]{40}$/', $token)) {
    $q = db()->prepare('SELECT s.*, t.subject FROM csat s JOIN tickets t ON t.id = s.ticket_id WHERE s.token = ?');
    $q->execute([$token]);
    $row = $q->fetch();
}
if (!$row) {
    http_response_code(404);
    $title = 'Link not found';
    $lead = 'This survey link isn’t valid anymore.';
    $sub = 'No worries — nothing was recorded.';
} else {
    // First click records the score
    $score = (int)($_GET['s'] ?? 0);
    if ($row['responded_at'] === null && in_array($score, [1, 2, 3], true)) {
        db()->prepare('UPDATE csat SET score = ?, responded_at = UTC_TIMESTAMP() WHERE id = ? AND responded_at IS NULL')
            ->execute([$score, (int)$row['id']]);
        $row['score'] = $score;
        $row['responded_at'] = 'now';
    }
    // Optional comment (allowed once, after a score exists)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row['responded_at'] !== null && $row['comment'] === '') {
        $comment = mb_substr(utf8_clean(trim((string)($_POST['comment'] ?? ''))), 0, 500);
        if ($comment !== '') {
            db()->prepare('UPDATE csat SET comment = ? WHERE id = ?')->execute([$comment, (int)$row['id']]);
            $row['comment'] = $comment;
        }
    }
    $FACES = [1 => '🙁', 2 => '😐', 3 => '😀'];
    if ($row['responded_at'] === null) {
        $title = 'How did we do?';
        $lead = 'One tap — that’s the whole survey.';
        $sub = h(mb_substr((string)$row['subject'], 0, 80));
    } else {
        $title = 'Thank you!';
        $lead = ($FACES[(int)$row['score']] ?? '') . ' Your rating is in.';
        $sub = 'It helps us take better care of you.';
    }
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Safeharbor</title>
<link rel="icon" type="image/svg+xml" href="/assets/brand/favicon.svg">
<script>document.documentElement.dataset.theme=localStorage.getItem("safeharbor.theme")||"system";</script>
<link rel="stylesheet" href="/assets/css/app.css?v=2">
</head>
<body class="login-body">
<div class="login-wrap">
  <div class="login-head">
    <img src="/assets/brand/favicon.svg" alt="" class="login-mark">
    <h1 class="login-name"><?= h($title) ?></h1>
    <p class="login-tag"><?= $lead ?></p>
  </div>
  <div class="card login-card csat-card">
    <p class="csat-sub"><?= $sub ?></p>
    <?php if ($row && $row['responded_at'] === null): ?>
      <div class="csat-faces">
        <a class="csat-face" href="/csat.php?t=<?= h($token) ?>&amp;s=3" title="Great">😀</a>
        <a class="csat-face" href="/csat.php?t=<?= h($token) ?>&amp;s=2" title="Okay">😐</a>
        <a class="csat-face" href="/csat.php?t=<?= h($token) ?>&amp;s=1" title="Rough">🙁</a>
      </div>
    <?php elseif ($row && $row['comment'] === ''): ?>
      <form method="post" action="/csat.php" class="csat-comment">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <textarea name="comment" rows="3" maxlength="500" placeholder="Anything we should know? (optional)"></textarea>
        <button type="submit" class="btn-primary btn-sm">Send</button>
      </form>
    <?php elseif ($row): ?>
      <p class="csat-sub">— Safeharbor by 8 West IT</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
