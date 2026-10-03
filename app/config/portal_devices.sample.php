<?php
// Merge this separate block into protected config.php during the reviewed rollout.
// Use a new secret shared only with Milepost's customer_portal service identity.
return ['portal_devices' => [
    'enabled' => false,
    'diagnostics_enabled' => false,
    'endpoint' => 'https://support.8westit.com/api/svc/customer_portal.php',
    'secret' => '',
]];
