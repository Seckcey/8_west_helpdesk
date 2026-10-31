<?php
/**
 * Resolution survey — the ONLY unauthenticated page besides login. The token in the resolution
 * email is the whole auth. Mail scanners and link previewers open every link in an email, so opening
 * a link never records anything: it shows the survey with the tapped face already picked, and only
 * the customer's Send (a POST) records the rating, with an optional comment. A rating can be changed
 * for CSAT_CHANGE_DAYS after the first answer, whose time stays (reports count by it); a comment is
 * saved once.
 *   GET  /csat.php?t=TOKEN&s=1|2|3   shows the survey, writes nothing
 *   POST /csat.php  (t, s, comment)  records
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
enforce_https();

const CSAT_CHANGE_DAYS = 7;
$FACES = [3 => ['😀', 'Great'], 2 => ['😐', 'Okay'], 1 => ['🙁', 'Rough']];

/** The survey for a token, with whether its rating can still change, or null. */
function csat_find(string $token): ?array
{
    if (preg_match('/^[a-f0-9]{40}$/', $token) !== 1) return null;
    $q = db()->prepare(
        'SELECT s.*, t.subject, s.responded_at >= UTC_TIMESTAMP() - INTERVAL ? DAY AS changeable
           FROM csat s JOIN tickets t ON t.id = s.ticket_id WHERE s.token = ?'
    );
    $q->execute([CSAT_CHANGE_DAYS, $token]);
    return $q->fetch() ?: null;
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = $isPost ? $_POST : $_GET;
$token = is_string($in['t'] ?? null) ? $in['t'] : '';
$pick = in_array($in['s'] ?? null, ['1', '2', '3'], true) ? (int)$in['s'] : null;
$typed = $isPost && is_string($_POST['comment'] ?? null)
    ? mb_substr(utf8_clean(trim($_POST['comment'])), 0, 500) : '';
$row = csat_find($token);
$status = 200;
$notes = [];
$error = '';

if ($row !== null && $isPost) {
    $id = (int)$row['id'];
    $held = $FACES[(int)$row['score']] ?? null;
    if ($row['responded_at'] === null) {
        if ($pick === null) {
            $status = 422;
            $error = 'Pick a face first, then press Send.';
        } else {
            // If another tab answered a moment ago, that answer stands and this one changes nothing.
            $first = db()->prepare('UPDATE csat SET score = ?, comment = ?, responded_at = UTC_TIMESTAMP() WHERE id = ? AND responded_at IS NULL');
            $first->execute([$pick, $typed, $id]);
            if ($first->rowCount() === 1) $notes[] = $typed === '' ? 'We got your rating.' : 'We got your rating and your comment.';
        }
    } else {
        $saved = $refused = false;
        if ($pick !== null && $pick !== (int)$row['score']) {
            // Only the score moves; responded_at keeps the first answer time.
            $change = db()->prepare('UPDATE csat SET score = ? WHERE id = ? AND responded_at >= UTC_TIMESTAMP() - INTERVAL ? DAY');
            $change->execute([$pick, $id, CSAT_CHANGE_DAYS]);
            $saved = $change->rowCount() === 1;
            $refused = !$saved;
            if ($saved) $notes[] = 'Your rating is now ' . $FACES[$pick][0] . ' ' . $FACES[$pick][1] . '.';
        }
        if ($typed !== '') {
            $comment = db()->prepare("UPDATE csat SET comment = ? WHERE id = ? AND comment = ''");
            $comment->execute([$typed, $id]);
            if ($comment->rowCount() === 1) {
                $saved = true;
                $notes[] = 'We got your comment.';
            } else {
                $notes[] = 'We already have your comment, so we kept the first one.';
            }
        }
        if ($refused) {
            $notes[] = 'A rating can only be changed in the first ' . CSAT_CHANGE_DAYS . ' days'
                . ($held ? ', so it stays ' . $held[0] . ' ' . $held[1] . '.' : '.');
            if (!$saved) $status = 409;
        }
    }
    $row = csat_find($token);   // show what is recorded now
}

if ($row === null) {
    http_response_code(404);
    $title = 'Link not found';
    $lead = 'This survey link isn’t valid anymore.';
} else {
    http_response_code($status);
    $answered = $row['responded_at'] !== null;
    $held = $FACES[(int)$row['score']] ?? null;
    $canRate = !$answered || (int)$row['changeable'] === 1;
    $canComment = $row['comment'] === '';
    // The face that starts picked: the one in the link or the one just sent, else the recorded one.
    $selected = $pick ?? ($answered ? (int)$row['score'] : null);
    if (!$answered) {
        $title = 'How did we do?';
        $lead = $selected === null ? 'Pick a face, then press Send.' : 'Press Send to give this rating.';
    } else {
        $title = 'Thank you!';
        $lead = $held ? 'You rated this ' . $held[0] . ' ' . $held[1] . '.' : 'Your rating is in.';
    }
    $hint = '';
    if ($answered && !$isPost && $pick !== null && $pick !== (int)$row['score']) {
        $hint = $canRate ? 'Change it to ' . $FACES[$pick][0] . ' ' . $FACES[$pick][1] . '? Press Send.'
            : 'A rating can only be changed in the first ' . CSAT_CHANGE_DAYS . ' days.';
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
<link rel="stylesheet" href="/assets/css/app.css?v=5">
</head>
<body class="login-body">
<main class="login-wrap">
  <div class="login-head">
    <img src="/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png" alt="Safeharbor — 8 West IT 365" class="suite-login-logo" width="1851" height="513">
    <h1 class="login-name"><?= h($title) ?></h1>
    <p class="login-tag"><?= h($lead) ?></p>
  </div>
  <div class="card login-card csat-card">
    <?php if ($row === null): ?>
      <p class="csat-sub">No worries — nothing was recorded.</p>
    <?php else: ?>
      <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= h($error) ?></p><?php endif; ?>
      <?php if ($notes !== []): ?><p class="<?= $status === 409 ? 'form-error' : 'form-ok' ?>" role="status"><?= h(implode(' ', $notes)) ?></p><?php endif; ?>
      <p class="csat-sub"><?= h(mb_substr((string)$row['subject'], 0, 80)) ?></p>
      <?php if ($hint !== ''): ?><p class="csat-hint"><?= h($hint) ?></p><?php endif; ?>
      <?php if ($canRate || $canComment): ?>
      <form method="post" action="/csat.php" class="csat-form">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <?php if ($canRate): ?>
        <fieldset class="csat-faces">
          <legend>Your rating</legend>
          <?php foreach ($FACES as $score => [$face, $label]): ?>
          <label class="csat-face"><input type="radio" name="s" value="<?= (int)$score ?>" required<?= $score === $selected ? ' checked' : '' ?>><span><span class="csat-emoji" aria-hidden="true"><?= h($face) ?></span><?= h($label) ?></span></label>
          <?php endforeach; ?>
        </fieldset>
        <?php endif; ?>
        <?php if ($canComment): ?>
        <label class="csat-comment" for="csat-comment">Anything we should know? (optional)</label>
        <textarea id="csat-comment" name="comment" rows="3" maxlength="500"><?= h($typed) ?></textarea>
        <?php endif; ?>
        <button type="submit" class="btn-primary">Send</button>
      </form>
      <?php else: ?>
      <p class="csat-sub">— Safeharbor by 8 West IT</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
