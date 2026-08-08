<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
logout();
header('Location: ' . suite_logout_url());
exit;
