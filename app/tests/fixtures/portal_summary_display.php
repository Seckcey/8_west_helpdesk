<?php
/** Synthetic rendering only; archive verification is covered by portal_report_archive_test.php. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
function portal_csrf_token(): string { return 'synthetic-display-only'; }
require_once __DIR__.'/../../lib/portal_data.php';
require_once __DIR__.'/../../lib/portal_render.php';
function portal_summary_display_fixture(string $state = 'detail', string $theme = 'light'): array
{
    $context = ['identity'=>['subject'=>'example-member','display_name'=>'Example member','role'=>'client_admin','theme'=>$theme,
        'preferences'=>['schema'=>'8west-user-preferences-v1','sub'=>'example-member','version'=>1,
            'appearance'=>['theme'=>$theme,'density'=>'comfortable','contrast'=>'standard','motion'=>'reduce'],
            'regional'=>['time_zone'=>'America/New_York','time_format'=>'12h','date_format'=>'mdy','locale'=>'en-US'],
            'experience'=>['default_product'=>null,'westy_detail'=>'balanced'],
            'notifications'=>['enabled'=>true,'quiet_hours'=>null]]],
        'binding'=>['client_name'=>'Example client']];
    $text = "Example client\nExact saved summary <script>invalid()</script>\nApproved service time: 90 minutes.";
    $archive = ['id'=>7,'period_start'=>'2026-01-05 08:00:00','period_end'=>'2026-01-12 08:00:00',
        'generated_at'=>'2026-01-15 18:00:00','definition_version'=>3,'text'=>$text,'content_sha256'=>hash('sha256',$text),
        'metrics'=>['source'=>['client_name'=>'Example client'],'period'=>['schedule_timezone'=>'America/Los_Angeles'],
            'tickets'=>['opened'=>12,'resolved'=>9],'first_response'=>['average_minutes'=>42],
            'service_goal'=>['attainment_percent'=>75],'approved_billable_time'=>['minutes'=>90],
            'csat'=>['average_score_out_of_3'=>2.7,'responses_received_by_generated_at'=>3,'surveys_sent'=>4]]];
    if ($state === 'missing' || $state === 'zero') {
        $archive['metrics']['first_response']['average_minutes'] = $state === 'missing' ? null : 0;
        $archive['metrics']['service_goal']['attainment_percent'] = $state === 'missing' ? null : 0;
        $archive['metrics']['csat']['average_score_out_of_3'] = null;
        $archive['metrics']['csat']['responses_received_by_generated_at'] = 0;
    }
    return [$context,$archive];
}
function portal_summary_display_render(string $state, string $theme = 'light'): void
{
    [$context,$archive] = portal_summary_display_fixture($state,$theme);
    $_SERVER['REQUEST_URI'] = '/portal/reports.php';
    if ($state === 'list' || $state === 'empty') portal_render_reports($context,$state === 'empty' ? [] : [$archive]);
    else portal_render_report($context,$archive);
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) portal_summary_display_render($argv[1] ?? 'detail',$argv[2] ?? 'light');
