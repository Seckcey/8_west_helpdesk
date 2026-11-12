<?php
declare(strict_types=1);

/** Display a recorded UTC instant; the desk script renders it in the user's selected time zone. */
function westy_review_time(?string $value): string
{
    if ($value === null || $value === '') return 'Not recorded';
    try { $instant = new DateTimeImmutable($value, new DateTimeZone('UTC')); }
    catch (Throwable) { return 'Time unavailable'; }
    $utc = $instant->setTimezone(new DateTimeZone('UTC'));
    return '<time data-westy-review-time datetime="' . h($utc->format('Y-m-d\TH:i:s\Z')) . '">'
        . h($utc->format('M j, Y, H:i \U\T\C')) . '</time>';
}
