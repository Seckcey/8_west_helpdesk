<?php
/** Synthetic rendering only; no sessions, database or real identities. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
function portal_csrf_token(): string { return 'synthetic-form-csrf'; }
function portal_action_nonce(string $purpose, ?int $now = null): string { return 'synthetic-form-nonce'; }
require_once __DIR__.'/../../lib/portal_data.php';
require_once __DIR__.'/../../lib/portal_render.php';
function portal_guidance_display_render(string $state, string $theme = 'light'): void
{
    $identity = ['display_name'=>'Example member','role'=>'client_staff','theme'=>$theme];
    if ($state === 'delegated') {
        $identity['role'] = 'technician';
        $identity['customer_access'] = ['role'=>'client_admin'];
    }
    $context = ['identity'=>$identity,'binding'=>['client_name'=>'Example client']];
    if (str_starts_with($state, 'resolved-') || str_starts_with($state, 'waiting-')) {
        $context['identity']['role'] = str_ends_with($state, '-viewer') ? 'client_viewer' : 'client_staff';
        $_SERVER['REQUEST_URI'] = '/portal/ticket.php?id=102';
        portal_render_ticket($context, [
            'ticket' => ['id'=>102, 'subject'=>'Example printer issue', 'priority'=>'normal',
                'status'=>str_starts_with($state, 'resolved-') ? 'resolved' : 'waiting',
                'created_at'=>gmdate('Y-m-d H:i:s', time()-7200),
                'first_response_at'=>gmdate('Y-m-d H:i:s', time()-3600),
                'resolved_at'=>str_starts_with($state, 'resolved-') ? gmdate('Y-m-d H:i:s', time()-1800) : null],
            'messages'=>[['kind'=>'tech', 'author_name'=>'Example support teammate',
                'created_at'=>gmdate('Y-m-d H:i:s', time()-3600), 'body'=>'The printer connection has been checked.']],
        ]);
        return;
    }
    if (str_starts_with($state,'picker')) {
        $_SERVER['REQUEST_URI'] = '/portal/clients.php';
        $choices = [];
        $count = match ($state) { 'picker-empty'=>0, 'picker-one'=>1, default=>2 };
        for ($i=1; $i<=$count; $i++) $choices[] = [
            'binding'=>['client_name'=>$i===1 ? 'Example client' : 'Example second client'],
            'access'=>['role'=>$i===1 ? 'client_admin' : 'client_staff',
                'access'=>['reference'=>'example-reference-'.$i,'generation'=>$i+2]],
        ];
        portal_render_client_choices($identity,$choices);
        return;
    }
    $_SERVER['REQUEST_URI'] = '/portal/new.php';
    $values = $state === 'blank' ? [] : ['subject'=>'Example <printer> issue','priority'=>'high','body'=>'The printer shows <an error>.'];
    $error = null;
    if ($state === 'invalid') {
        try { portal_ticket_body(''); } catch (PortalDataValidationException $e) { $error = $e->getMessage(); }
        $values['body'] = '';
    } elseif ($state === 'uncertain') {
        $error = 'We could not confirm whether the request was saved. Check the dashboard before trying again.';
    }
    portal_render_new_ticket($context,$error,$values);
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) portal_guidance_display_render($argv[1] ?? 'blank',$argv[2] ?? 'light');
