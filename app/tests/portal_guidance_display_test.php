<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
require_once __DIR__.'/fixtures/portal_guidance_display.php';
$checks = 0;
$check = static function(string $name, bool $ok) use (&$checks): void {
    if (!$ok) throw new RuntimeException($name);
    echo 'ok '.++$checks.' - '.$name."\n";
};
foreach (['blank','filled','invalid','uncertain','picker-empty','picker-one','picker-many'] as $state) {
    ob_start(); portal_guidance_display_render($state); $html = ob_get_clean();
    $dom = new DOMDocument(); @$dom->loadHTML($html); $xp = new DOMXPath($dom);
    $check($state.' has no inline scripts or styles', $xp->query('//script[not(@src)]|//*[@style or @onclick]')->length===0);
    if (str_starts_with($state,'picker')) {
        $count = match($state) { 'picker-empty'=>0,'picker-one'=>1,default=>2 };
        $forms = $xp->query('//form');
        $check($state.' retains exactly the supplied choices', $forms->length===$count);
        $check($state.' names the whole portal selection', $xp->query('//h1')->item(0)->textContent==='Choose a client');
        foreach($forms as $i=>$form) {
            $names = array_map(fn($n)=>$n->getAttribute('name'),iterator_to_array($xp->query('.//input',$form)));
            $check($state.' choice '.$i.' preserves the POST contract', $form->getAttribute('method')==='post'
                && !$form->hasAttribute('action') && $names===['csrf','reference','generation']
                && $xp->query('.//input[@name="reference"]',$form)->item(0)->getAttribute('value')==='example-reference-'.($i+1)
                && $xp->query('.//input[@name="generation"]',$form)->item(0)->getAttribute('value')===(string)($i+3)
                && trim($xp->query('.//button',$form)->item(0)->textContent)==='Continue with this client');
        }
        continue;
    }
    $form = $xp->query('//form[@action="/portal/new.php"]')->item(0);
    $fields = $xp->query('.//*[@name]',$form);
    $check($state.' keeps exactly the existing request fields', array_map(fn($n)=>$n->getAttribute('name'),iterator_to_array($fields))===['csrf','action_nonce','subject','priority','body']
        && $form->getAttribute('method')==='post');
    foreach(['subject','priority','body'] as $name) {
        $field = $xp->query('.//*[@name="'.$name.'"]',$form)->item(0);
        $id = $field->getAttribute('id'); $help = $field->getAttribute('aria-describedby');
        $check($state.' '.$name.' has a persistent associated label and description',
            $xp->query('//label[@for="'.$id.'"]')->length===1 && $xp->query('//*[@id="'.$help.'"]')->length===1 && !$field->hasAttribute('placeholder'));
    }
    $check($state.' retains limits, required fields and selected priority',
        $xp->query('.//input[@name="subject" and @maxlength="190" and @required and @autofocus]',$form)->length===1
        && $xp->query('.//textarea[@name="body" and @maxlength="8000" and @required]',$form)->length===1
        && $xp->query('.//option[@selected]',$form)->item(0)->getAttribute('value')===($state==='blank'?'normal':'high'));
    $check($state.' keeps the existing CSRF and nonce fields',
        $xp->query('.//input[@name="csrf"]',$form)->item(0)->getAttribute('value')==='synthetic-form-csrf'
        && $xp->query('.//input[@name="action_nonce"]',$form)->item(0)->getAttribute('value')==='synthetic-form-nonce');
    if($state!=='blank') $check($state.' preserved subject stays escaped',
        $xp->query('.//input[@name="subject"]',$form)->item(0)->getAttribute('value')==='Example <printer> issue' && !str_contains($html,'<printer>'));
    if($state==='filled'||$state==='uncertain') $check($state.' preserved body stays escaped',
        $xp->query('.//textarea[@name="body"]',$form)->item(0)->textContent==='The printer shows <an error>.' && !str_contains($html,'<an error>'));
    if($state==='invalid') $check('validation error is unchanged',str_contains($html,'Enter a message between 1 and 8000 characters.'));
    if($state==='uncertain') $check('uncertain save stays explicit without a retry claim',str_contains($html,'We could not confirm whether the request was saved. Check the dashboard before trying again.'));
}
echo "{$checks} portal guidance checks passed\n";
ob_end_flush();
