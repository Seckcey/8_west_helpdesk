<?php
/** Merge into server config.php only during the coordinated release.
 * Independent producer read_keys.safeharbor key: literal ASCII 64 lowercase hex string,
 * never hex-decoded. Empty is unconfigured; no endpoint override or actual secret.
 */
return ['tenant_branding'=>['service_secret'=>'']];
