<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/portal_auth.php';
require_once __DIR__ . '/../../lib/portal_devices_render.php';
enforce_https();
if (!portal_enabled()) { http_response_code(404); exit('Not found.'); }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); portal_render_error(405, 'Method not allowed', 'Open Your devices from the portal.'); exit; }
try {
    $context = portal_authenticated_context(db());
    if ($context === null) { portal_require_sign_in(); exit; }
    $error = null; $download = null; $notice = null; $devices = null; $enrollments = []; $choices = null;
    // One Family and one employee protection request per session: each keeps its original key and inputs for an
    // explicit same-facts retry or status check. They belong to this exact principal. A GET only shows earlier answers.
    $principal = [$context['identity']['subject'], $context['identity']['session_version'], $context['identity']['binding_id']];
    foreach (['portal_protection_request','portal_employee_protection_request'] as $slot) {
        if (($_SESSION[$slot]['principal'] ?? null) !== $principal) unset($_SESSION[$slot]);
    }
    $protection = $_SESSION['portal_protection_request'] ?? null;
    $employee = $_SESSION['portal_employee_protection_request'] ?? null;
    try {
        if ($method === 'POST') {
            if (!portal_csrf_valid($_POST['csrf'] ?? null)) throw new PortalDevicesException('sign_in', 403);
            $action = $_POST['action'] ?? '';
            if (!is_string($action) || !in_array($action, ['enrollment_create','enrollment_download','enrollment_revoke','protection_request','protection_retry',
                'employee_choices','employee_request','employee_status','employee_retry'], true)) throw new PortalDevicesException('invalid_request', 400);
            if ($action === 'employee_choices') {
                $choiceError = 'employee_choice';
                if (!portal_devices_keys($_POST, ['csrf','action','device_reference','cursor'])) throw new PortalDevicesException('employee_choice', 400);
                // A first page sends a JSON null cursor; "More employees" sends ID's original cursor back unchanged.
                $input = ['device_reference'=>$_POST['device_reference'], 'cursor'=>$_POST['cursor'] === '' ? null : $_POST['cursor']];
                $choices = ['device_reference'=>$input['device_reference']] + portal_devices_request(db(), $context, 'employee_protection_choices', $input);
            } elseif (in_array($action, ['protection_request','protection_retry','employee_request','employee_status','employee_retry'], true)) {
                $family = str_starts_with($action, 'protection_');
                $slot = $family ? 'portal_protection_request' : 'portal_employee_protection_request';
                $stored = $family ? $protection : $employee;
                $choiceError = $family ? 'protection_choice' : 'employee_choice';
                if (in_array($action, ['protection_retry','employee_status','employee_retry'], true)) {
                    // Only the stored request, with its original key and facts; the browser sends none. Never a new key.
                    // Family: retry while unconfirmed. Employee: check status while unconfirmed or absent; resend only after a proven absence.
                    $state = $stored === null ? null : ($stored['result']['state'] ?? 'unknown');
                    if ($state === null || ($action === 'protection_retry' && $state !== 'unknown')
                        || ($action === 'employee_status' && !in_array($state, ['unknown','absent'], true))
                        || ($action === 'employee_retry' && $state !== 'absent')) throw new PortalDevicesException('protection_retry_unavailable', 409);
                    $input = $stored['input'];
                } else {
                    $fields = ['device_reference','request_key','computer','same_as_device_reference'];
                    if (!$family) $fields[] = 'employee_reference';
                    if (!portal_devices_keys($_POST, array_merge(['csrf','action'], $fields))) throw new PortalDevicesException($choiceError, 400);
                    $input = [];
                    foreach ($fields as $field) $input[$field] = $_POST[$field];
                    // The form's empty earlier-record choice is the wire's JSON null.
                    if ($input['same_as_device_reference'] === '') $input['same_as_device_reference'] = null;
                    // A key never carries different choices; a new intent comes with its own new key.
                    if ($stored !== null && $stored['input']['request_key'] === $input['request_key'] && $stored['input'] !== $input) throw new PortalDevicesException('owner_request_changed', 409);
                }
                $record = ['principal'=>$principal, 'input'=>$input, 'result'=>null];
                if ($action === 'employee_status') {
                    // A readback of the same five facts changes nothing: the stored request stays as it was until an answer arrives.
                    $record['result'] = portal_devices_request(db(), $context, 'employee_protection_status', $input);
                } else {
                    // Kept as the transport starts, after every local check: an unknown outcome keeps this key and these facts.
                    $record['result'] = portal_devices_request(db(), $context, $family ? 'protection_request' : 'employee_protection_request', $input,
                        static function (string $url, string $body, array $headers) use ($record, $slot): array {
                            $_SESSION[$slot] = $record;
                            return portal_devices_transport($url, $body, $headers);
                        });
                }
                $_SESSION[$slot] = $record;
            } elseif ($action === 'enrollment_create') {
                if (($_POST['consent'] ?? '') !== 'yes') throw new PortalDevicesException('consent_required', 400);
                $result = portal_devices_request(db(), $context, $action, ['request_key'=>$_POST['request_key'] ?? '', 'platform'=>'windows']);
                if (($result['state'] ?? '') === 'ready') $download = portal_devices_request(db(), $context, 'enrollment_download', ['reference'=>$result['reference']]);
                else $notice = 'This request already has an installation link. Its current status is shown below.';
            } else {
                $result = portal_devices_request(db(), $context, $action, ['reference'=>$_POST['reference'] ?? '']);
                if ($action === 'enrollment_download') $download = $result;
                else $notice = 'The installation link is no longer available. Any computer already enrolled is unchanged.';
            }
        }
    } catch (PortalDevicesException $e) { $error = portal_devices_error(isset($choiceError) && $e->reason === 'invalid_request' ? $choiceError : $e->reason); }
    $protection = $_SESSION['portal_protection_request'] ?? null;
    $employee = $_SESSION['portal_employee_protection_request'] ?? null;
    try {
        $after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($after === false) $after = 0;
        $devices = portal_devices_request(db(), $context, 'devices', ['after'=>$after]);
        if (portal_devices_can_manage($context)) $enrollments = portal_devices_request(db(), $context, 'enrollments', [])['items'] ?? [];
    } catch (PortalDevicesException $e) { $error ??= portal_devices_error($e->reason); }
    // No response leaves this page after a revocation or binding change during a service request.
    $fresh = portal_authenticated_context(db());
    if ($fresh === null || $fresh['identity'] !== $context['identity']) { portal_require_sign_in('Please sign in again.'); exit; }
    portal_render_devices($fresh, $devices, $enrollments, $error, $download, $notice, $protection, $employee, $choices);
} catch (Throwable $e) {
    error_log('[safeharbor-portal-devices] request_failed type=' . $e::class);
    portal_render_error(503, 'Device services unavailable', 'Try again shortly. You can still contact the support team from the portal.');
}
