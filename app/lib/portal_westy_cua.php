<?php
/** Curated Cua Driver tools. The model sees a small typed surface instead of raw MCP:
 * Westy validates each call, builds the exact upstream tools/call, the Companion adds
 * its own session, and only bounded evidence returns. Screen content stays transient. */
declare(strict_types=1);

/** One Cua call needs its 58-second receipt wait plus request overhead. */
const PORTAL_WESTY_CUA_DISPATCH_SECONDS=62.0;
const PORTAL_WESTY_CUA_EVIDENCE_BYTES=24000;
const PORTAL_WESTY_CUA_TEXT_BYTES=8000;
const PORTAL_WESTY_CUA_TOOLS=['cua_list_windows','cua_launch_app','cua_window_state','cua_click','cua_type_text','cua_set_value',
    'cua_press_keys','cua_scroll','cua_browser_state','cua_browser_prepare','cua_browser_navigate','cua_browser_click','cua_browser_type'];
/** Named keys the pinned 0.34.0 Windows driver resolves (case-insensitive). */
const PORTAL_WESTY_CUA_KEYS=['enter','return','tab','escape','esc','space','backspace','delete','del','insert','ins','home','end',
    'pageup','pgup','pagedown','pgdn','up','down','left','right','f1','f2','f3','f4','f5','f6','f7','f8','f9','f10','f11','f12',
    'ctrl','control','shift','alt','win','windows','meta','command','cmd','capslock','numlock'];

/** A pre-dispatch refusal. Its message is server-written correction guidance for the model. */
final class PortalWestyCuaRejected extends RuntimeException {}

function portal_westy_cua_definitions():array
{
    $object=static fn(array $properties,array $required):array=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false];
    $pid=['type'=>'integer','description'=>'Process id (pid) from cua_list_windows or cua_launch_app.'];
    $window=['type'=>'integer','description'=>'window_id of that process from cua_list_windows or cua_launch_app.'];
    $token=['type'=>'string','description'=>'element_token from the latest cua_window_state of this same window.'];
    $x=['type'=>'number','description'=>'Pixel x in the latest screenshot of this window, from its top-left corner.'];
    $y=['type'=>'number','description'=>'Pixel y in the latest screenshot of this window, from its top-left corner.'];
    $delivery=['type'=>'string','enum'=>['background','foreground'],'description'=>'Default background. Use foreground only to retry one action that returned background_unavailable.'];
    $tab=['target_id'=>['type'=>'string','description'=>'target_id from cua_browser_state.'],'tab_id'=>['type'=>'string','description'=>'tab_id from cua_browser_state.']];
    $ref=['type'=>'string','description'=>'A current ref from the latest cua_browser_state snapshot of this tab.'];
    return [
        ['name'=>'cua_list_windows','description'=>'List open windows with their pid, window_id, app and title. Use it to find the exact window for the request.',
            'input_schema'=>$object(['pid'=>['type'=>'integer','description'=>'Optional: only windows of this process.'],
                'on_screen_only'=>['type'=>'boolean','description'=>'Optional: only visible windows.']],[])],
        ['name'=>'cua_launch_app','description'=>'Start an app, or open web pages in the default browser, without taking focus. Give one of aumid, name or path, or give urls. Returns the pid and its windows.',
            'input_schema'=>$object(['aumid'=>['type'=>'string','description'=>'Packaged app id, for example Microsoft.WindowsNotepad_8wekyb3d8bbwe!App.'],
                'name'=>['type'=>'string','description'=>'App name, for example notepad or calc.'],
                'path'=>['type'=>'string','description'=>'Full path to an .exe file.'],
                'urls'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'http or https addresses to open in the default browser.'],
                'arguments'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'Optional command-line arguments for aumid, name or path.']],[])],
        ['name'=>'cua_window_state','description'=>'Observe one window: a screenshot plus its controls with element tokens, roles, labels and values. Call it before acting and again afterwards to verify. A new snapshot replaces older tokens.',
            'input_schema'=>$object(['pid'=>$pid,'window_id'=>$window,
                'query'=>['type'=>'string','description'=>'Optional text to show only matching controls and their parents, for large windows.'],
                'include_screenshot'=>['type'=>'boolean','description'=>'Default true.']],['pid','window_id'])],
        ['name'=>'cua_click','description'=>'Click a control by element_token (preferred) or at x and y in the latest screenshot. Use button right for a context menu and count 2 for a double-click.',
            'input_schema'=>$object(['pid'=>$pid,'window_id'=>$window,'element_token'=>$token,'x'=>$x,'y'=>$y,
                'button'=>['type'=>'string','enum'=>['left','right','middle']],'count'=>['type'=>'integer','minimum'=>1,'maximum'=>3],'delivery_mode'=>$delivery],['pid','window_id'])],
        ['name'=>'cua_type_text','description'=>'Insert text at the current caret, into element_token, or after focusing x and y. It inserts and never clears existing text. Use cua_press_keys for Enter, Tab and shortcuts.',
            'input_schema'=>$object(['pid'=>$pid,'window_id'=>$window,'text'=>['type'=>'string'],'element_token'=>$token,'x'=>$x,'y'=>$y,'delivery_mode'=>$delivery],['pid','window_id','text'])],
        ['name'=>'cua_set_value','description'=>'Replace the whole value of a text field or similar control, by element_token.',
            'input_schema'=>$object(['pid'=>$pid,'window_id'=>$window,'element_token'=>$token,'value'=>['type'=>'string']],['pid','window_id','element_token','value'])],
        ['name'=>'cua_press_keys','description'=>'Press one key or one combination, for example ["enter"], ["tab"], ["ctrl","a"] or ["ctrl","shift","t"].',
            'input_schema'=>$object(['pid'=>$pid,'window_id'=>$window,'keys'=>['type'=>'array','items'=>['type'=>'string'],'minItems'=>1,'maxItems'=>4],
                'element_token'=>$token,'delivery_mode'=>$delivery],['pid','window_id','keys'])],
        ['name'=>'cua_scroll','description'=>'Scroll the focused area, or the area under element_token or x and y.',
            'input_schema'=>$object(['pid'=>$pid,'window_id'=>$window,'direction'=>['type'=>'string','enum'=>['up','down','left','right']],
                'amount'=>['type'=>'integer','minimum'=>1,'maximum'=>50],'by'=>['type'=>'string','enum'=>['line','page']],
                'element_token'=>$token,'x'=>$x,'y'=>$y,'delivery_mode'=>$delivery],['pid','window_id','direction'])],
        ['name'=>'cua_browser_state','description'=>'Page content in Chrome or Edge. With pid and window_id it binds that browser window and lists its tabs. With target_id and tab_id it returns the page outline and refs for page actions.',
            'input_schema'=>$object(['pid'=>$pid,'window_id'=>$window]+$tab+[
                'query'=>['type'=>'string','description'=>'Optional text to narrow the page snapshot.'],
                'include_screenshot'=>['type'=>'boolean','description'=>'Optional page screenshot.'],
                'continuation'=>['type'=>'string','description'=>'Opaque continuation from the previous snapshot of this tab.']],[])],
        ['name'=>'cua_browser_prepare','description'=>'Connect to the person\'s open browser window when cua_browser_state reports browser_requires_setup or browser_consent_required. Tell the person first. It turns on the browser\'s debugging connection for this task.',
            'input_schema'=>$object(['pid'=>$pid,'window_id'=>$window],['pid','window_id'])],
        ['name'=>'cua_browser_navigate','description'=>'Open an http or https address in a bound tab. Refs from before the navigation expire.',
            'input_schema'=>$object($tab+['url'=>['type'=>'string']],['target_id','tab_id','url'])],
        ['name'=>'cua_browser_click','description'=>'Click a page element by its current ref.',
            'input_schema'=>$object($tab+['ref'=>$ref,'input_route'=>['type'=>'string','enum'=>['trusted','dom_event'],'description'=>'Default trusted.']],['target_id','tab_id','ref'])],
        ['name'=>'cua_browser_type','description'=>'Type into a page field by its current ref. replace true clears the field first.',
            'input_schema'=>$object($tab+['ref'=>$ref,'text'=>['type'=>'string'],'replace'=>['type'=>'boolean'],
                'mode'=>['type'=>'string','enum'=>['insert_text','keystrokes']]],['target_id','tab_id','ref','text'])],
    ];
}

function portal_westy_cua_reject(string $message):never{throw new PortalWestyCuaRejected($message);}

function portal_westy_cua_int(array $a,string $key,int $min,int $max,bool $required=false):?int
{
    if(($a[$key]??null)===null){if($required)portal_westy_cua_reject($key.' is required.');return null;}
    $value=$a[$key];
    if(is_float($value)&&is_finite($value)&&floor($value)===$value&&abs($value)<9.0e15)$value=(int)$value;
    if(!is_int($value)||$value<$min||$value>$max)portal_westy_cua_reject($key.' must be a whole number from '.$min.' to '.$max.'.');
    return $value;
}

function portal_westy_cua_number(array $a,string $key):int|float|null
{
    if(($a[$key]??null)===null)return null;
    $value=$a[$key];
    if(!(is_int($value)||is_float($value))||!is_finite((float)$value)||$value<0||$value>100000)portal_westy_cua_reject($key.' must be a pixel position from 0 to 100000.');
    return $value;
}

function portal_westy_cua_bool(array $a,string $key):?bool
{
    if(($a[$key]??null)===null)return null;
    if(!is_bool($a[$key]))portal_westy_cua_reject($key.' must be true or false.');
    return $a[$key];
}

/** Text the person asked to enter may contain line breaks and tabs, never other controls. */
function portal_westy_cua_string(array $a,string $key,int $max,string $pattern='',bool $required=false,bool $multiline=false):?string
{
    if(($a[$key]??null)===null){if($required)portal_westy_cua_reject($key.' is required.');return null;}
    $value=$a[$key];
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||strlen($value)>$max
        ||preg_match($multiline?'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/':'/[\x00-\x1F\x7F]/',$value)===1)
        portal_westy_cua_reject($key.' must be text of at most '.$max.' bytes'.($multiline?'':' on one line').'.');
    if($pattern!==''&&preg_match($pattern,$value)!==1)portal_westy_cua_reject($key.' has an unsupported format.');
    return $value;
}

function portal_westy_cua_enum(array $a,string $key,array $allowed):?string
{
    if(($a[$key]??null)===null)return null;
    if(!in_array($a[$key],$allowed,true))portal_westy_cua_reject($key.' must be one of: '.implode(', ',$allowed).'.');
    return $a[$key];
}

function portal_westy_cua_list(array $a,string $key,int $max,int $itemBytes,string $pattern=''):?array
{
    if(($a[$key]??null)===null)return null;
    $value=$a[$key];
    if(!is_array($value)||!array_is_list($value)||$value===[]||count($value)>$max)portal_westy_cua_reject($key.' must be a list of 1 to '.$max.' items.');
    foreach($value as $index=>$item)portal_westy_cua_string([$key=>$item],$key,$itemBytes,$pattern,true);
    return $value;
}

function portal_westy_cua_url(string $url,bool $about=false):string
{
    if($about&&$url==='about:blank')return $url;
    $parts=parse_url($url);
    if(!is_array($parts)||!in_array(strtolower($parts['scheme']??''),['http','https'],true)||($parts['host']??'')===''||preg_match('/\s/',$url)===1)
        portal_westy_cua_reject('Use a complete http or https address.');
    return $url;
}

/** Exactly the model's allowed fields, translated to one upstream tools/call. Westy adds
 * only safe defaults (background delivery, bounded observation); never a session. */
function portal_westy_cua_request(string $tool,mixed $arguments):array
{
    if(!in_array($tool,PORTAL_WESTY_CUA_TOOLS,true))portal_westy_cua_reject('Unknown computer tool.');
    if(!is_array($arguments)||($arguments!==[]&&array_is_list($arguments)))portal_westy_cua_reject('Arguments must be an object.');
    $allowed=['cua_list_windows'=>['pid','on_screen_only'],'cua_launch_app'=>['aumid','name','path','urls','arguments'],
        'cua_window_state'=>['pid','window_id','query','include_screenshot'],
        'cua_click'=>['pid','window_id','element_token','x','y','button','count','delivery_mode'],
        'cua_type_text'=>['pid','window_id','text','element_token','x','y','delivery_mode'],
        'cua_set_value'=>['pid','window_id','element_token','value'],
        'cua_press_keys'=>['pid','window_id','keys','element_token','delivery_mode'],
        'cua_scroll'=>['pid','window_id','direction','amount','by','element_token','x','y','delivery_mode'],
        'cua_browser_state'=>['pid','window_id','target_id','tab_id','query','include_screenshot','continuation'],
        'cua_browser_prepare'=>['pid','window_id'],'cua_browser_navigate'=>['target_id','tab_id','url'],
        'cua_browser_click'=>['target_id','tab_id','ref','input_route'],'cua_browser_type'=>['target_id','tab_id','ref','text','replace','mode']][$tool];
    $extra=array_diff(array_keys($arguments),$allowed);
    if($extra!==[])portal_westy_cua_reject('Unsupported field for '.$tool.': '.implode(', ',array_map('strval',$extra)).'.');
    $a=$arguments;$out=[];$public=[];
    $window=static function(bool $required=true)use($a,&$out,&$public):void{
        $pid=portal_westy_cua_int($a,'pid',1,4294967295,$required);$id=portal_westy_cua_int($a,'window_id',1,PHP_INT_MAX,$required);
        if(($pid===null)!==($id===null))portal_westy_cua_reject('Give both pid and window_id.');
        if($pid!==null){$out['pid']=$pid;$out['window_id']=$id;$public+=['pid'=>$pid,'window_id'=>$id];}
    };
    $token=static fn():?string=>portal_westy_cua_string($a,'element_token',32,'/\As[0-9a-f]{8}:[0-9]{1,9}\z/D');
    $point=static function()use($a):?array{
        $x=portal_westy_cua_number($a,'x');$y=portal_westy_cua_number($a,'y');
        if(($x===null)!==($y===null))portal_westy_cua_reject('Give both x and y.');
        return $x===null?null:['x'=>$x,'y'=>$y];
    };
    $delivery=static fn():string=>portal_westy_cua_enum($a,'delivery_mode',['background','foreground'])??'background';
    $opaque='/\A[\x21-\x7e]+\z/D';
    $tab=static function()use($a,$opaque,&$out):void{
        $out['target_id']=portal_westy_cua_string($a,'target_id',256,$opaque,true);
        $out['tab_id']=portal_westy_cua_string($a,'tab_id',256,$opaque,true);
    };
    $set=static function(array $values)use(&$out):void{foreach($values as $key=>$value)if($value!==null)$out[$key]=$value;};
    switch($tool){
        case 'cua_list_windows':
            $set(['pid'=>portal_westy_cua_int($a,'pid',1,4294967295),'on_screen_only'=>portal_westy_cua_bool($a,'on_screen_only')]);
            return ['name'=>'list_windows','arguments'=>$out,'public'=>$public];
        case 'cua_launch_app':
            $aumid=portal_westy_cua_string($a,'aumid',300,'/\A[A-Za-z0-9._-]{1,200}![A-Za-z0-9._-]{1,100}\z/D');
            $name=portal_westy_cua_string($a,'name',120,'/\A[\p{L}\p{N} ._()&+-]{1,120}\z/uD');
            $path=portal_westy_cua_string($a,'path',520,'/\A(?:[A-Za-z]:\\\\|\\\\\\\\)[^<>"|?*]+\.exe\z/iD');
            $urls=portal_westy_cua_list($a,'urls',5,2048);
            $chosen=count(array_filter([$aumid,$name,$path,$urls],static fn($v)=>$v!==null));
            if($chosen!==1)portal_westy_cua_reject('Give exactly one of aumid, name, path or urls.');
            foreach($urls??[] as $url)portal_westy_cua_url($url);
            $arguments=portal_westy_cua_list($a,'arguments',10,500);
            if($arguments!==null&&$urls!==null)portal_westy_cua_reject('arguments cannot be combined with urls.');
            $set(['aumid'=>$aumid,'name'=>$name,'path'=>$path,'urls'=>$urls,'additional_arguments'=>$arguments]);
            return ['name'=>'launch_app','arguments'=>$out,'public'=>$public];
        case 'cua_window_state':
            $window();$query=portal_westy_cua_string($a,'query',200);
            // Bound the accessibility walk and image so one observation fits a model round.
            $set(['query'=>$query,'include_screenshot'=>portal_westy_cua_bool($a,'include_screenshot')??true,
                'max_elements'=>$query===null?150:1000,'max_image_dimension'=>1280]);
            return ['name'=>'get_window_state','arguments'=>$out,'public'=>$public];
        case 'cua_click':
            $window();$element=$token();$at=$point();
            if(($element===null)===($at===null))portal_westy_cua_reject('Give either element_token or both x and y.');
            $set(['element_token'=>$element]+($at??[])+['button'=>portal_westy_cua_enum($a,'button',['left','right','middle']),
                'count'=>portal_westy_cua_int($a,'count',1,3),'delivery_mode'=>$delivery()]);
            return ['name'=>'click','arguments'=>$out,'public'=>$public];
        case 'cua_type_text':
            $window();$text=portal_westy_cua_string($a,'text',PORTAL_WESTY_CUA_TEXT_BYTES,'',true,true);
            if($text==='')portal_westy_cua_reject('text must not be empty.');
            $element=$token();$at=$point();
            if($element!==null&&$at!==null)portal_westy_cua_reject('Give element_token or x and y, not both.');
            $set(['text'=>$text,'element_token'=>$element]+($at??[])+['delivery_mode'=>$delivery()]);
            return ['name'=>'type_text','arguments'=>$out,'public'=>$public+['characters'=>mb_strlen($text)]];
        case 'cua_set_value':
            $window();$element=$token();
            if($element===null)portal_westy_cua_reject('element_token is required.');
            $set(['element_token'=>$element,'value'=>portal_westy_cua_string($a,'value',PORTAL_WESTY_CUA_TEXT_BYTES,'',true,true)]);
            return ['name'=>'set_value','arguments'=>$out,'public'=>$public];
        case 'cua_press_keys':
            $window();
            $keys=portal_westy_cua_list($a,'keys',4,24);
            if($keys===null)portal_westy_cua_reject('keys is required.');
            // The driver presses the first character of an unrecognized name, so
            // only its named keys or one literal printable character are sent.
            $keys=array_map(static fn(string $key):string=>strlen($key)===1?strtolower($key):strtolower(trim($key)),$keys);
            foreach($keys as $key)if(!in_array($key,PORTAL_WESTY_CUA_KEYS,true)&&preg_match('/\A[\x21-\x7e]\z/D',$key)!==1)
                portal_westy_cua_reject('Unsupported key "'.portal_westy_cua_clip($key,24).'". Use names such as enter, tab, esc, backspace, delete, up, pagedown, f5, ctrl, shift, alt or win, or one character.');
            $set(count($keys)===1?['key'=>$keys[0]]:['keys'=>$keys]);
            $set(['element_token'=>$token(),'delivery_mode'=>$delivery()]);
            return ['name'=>count($keys)===1?'press_key':'hotkey','arguments'=>$out,'public'=>$public];
        case 'cua_scroll':
            $window();$element=$token();$at=$point();
            if($element!==null&&$at!==null)portal_westy_cua_reject('Give element_token or x and y, not both.');
            $direction=portal_westy_cua_enum($a,'direction',['up','down','left','right']);
            if($direction===null)portal_westy_cua_reject('direction is required.');
            $set(['direction'=>$direction,'amount'=>portal_westy_cua_int($a,'amount',1,50),'by'=>portal_westy_cua_enum($a,'by',['line','page']),
                'element_token'=>$element]+($at??[])+['delivery_mode'=>$delivery()]);
            return ['name'=>'scroll','arguments'=>$out,'public'=>$public];
        case 'cua_browser_state':
            $bound=($a['target_id']??null)!==null||($a['tab_id']??null)!==null;
            if($bound){
                if(($a['pid']??null)!==null||($a['window_id']??null)!==null)portal_westy_cua_reject('Give either pid and window_id, or target_id and tab_id.');
                $tab();
                $set(['snapshot_format'=>'semantic_v2','query'=>portal_westy_cua_string($a,'query',200),
                    'include_screenshot'=>portal_westy_cua_bool($a,'include_screenshot'),'continuation'=>portal_westy_cua_string($a,'continuation',4096,$opaque)]);
            }else{
                foreach(['query','include_screenshot','continuation'] as $key)
                    if(($a[$key]??null)!==null)portal_westy_cua_reject($key.' needs target_id and tab_id from a bound window.');
                $window();
            }
            return ['name'=>'get_browser_state','arguments'=>$out,'public'=>$public];
        case 'cua_browser_prepare':
            $window();$out['strategy']=['kind'=>'existing_profile'];
            return ['name'=>'browser_prepare','arguments'=>$out,'public'=>$public];
        case 'cua_browser_navigate':
            $tab();$out['url']=portal_westy_cua_url(portal_westy_cua_string($a,'url',2048,'',true),true);
            return ['name'=>'browser_navigate','arguments'=>$out,'public'=>$public];
        case 'cua_browser_click':
            $tab();$set(['ref'=>portal_westy_cua_string($a,'ref',128,$opaque,true),'input_route'=>portal_westy_cua_enum($a,'input_route',['trusted','dom_event'])]);
            return ['name'=>'browser_click','arguments'=>$out,'public'=>$public];
        default:
            $tab();$text=portal_westy_cua_string($a,'text',PORTAL_WESTY_CUA_TEXT_BYTES,'',true,true);
            $set(['ref'=>portal_westy_cua_string($a,'ref',128,$opaque,true),'text'=>$text,'replace'=>portal_westy_cua_bool($a,'replace'),
                'mode'=>portal_westy_cua_enum($a,'mode',['insert_text','keystrokes'])]);
            return ['name'=>'browser_type','arguments'=>$out,'public'=>$public+['characters'=>mb_strlen($text)]];
    }
}

function portal_westy_cua_clip(string $text,int $bytes):string
{
    return strlen($text)<=$bytes?$text:mb_strcut($text,0,$bytes,'UTF-8').'…[truncated]';
}

/** One compact line per control instead of a JSON object plus a duplicate Markdown tree. */
function portal_westy_cua_elements(array $elements,int $limit):array
{
    $lines=[];
    foreach(array_slice($elements,0,$limit) as $element){
        if(!is_array($element)){$lines[]=is_scalar($element)?portal_westy_cua_clip((string)$element,200):'[unreadable element]';continue;}
        $parts=[];$token=$element['element_token']??$element['token']??null;$index=$element['element_index']??$element['index']??null;
        if(is_string($token))$parts[]=$token;elseif(is_int($index))$parts[]='#'.$index;
        foreach(['role','type'] as $key)if(is_string($element[$key]??null)&&$element[$key]!==''){$parts[]=portal_westy_cua_clip($element[$key],40);break;}
        foreach(['label','name','title'] as $key)if(is_string($element[$key]??null)&&$element[$key]!==''){
            $parts[]=json_encode(portal_westy_cua_clip($element[$key],160),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);break;}
        if(is_string($element['value']??null)&&$element['value']!=='')
            $parts[]='value='.json_encode(portal_westy_cua_clip($element['value'],300),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if(is_array($element['actions']??null)&&$element['actions']!==[])
            $parts[]='['.implode(',',array_map(static fn($v)=>portal_westy_cua_clip((string)$v,30),array_filter($element['actions'],'is_scalar'))).']';
        if(($element['enabled']??true)===false)$parts[]='disabled';
        if(($element['selected']??false)===true)$parts[]='selected';
        if(($element['focused']??false)===true)$parts[]='focused';
        $frame=$element['screenshot_frame']??null;
        if(is_array($frame)){
            $box=[];foreach([['x'],['y'],['w','width'],['h','height']] as $names)foreach($names as $name)if(is_int($frame[$name]??null)||is_float($frame[$name]??null)){$box[]=round((float)$frame[$name]);break;}
            if(count($box)===4)$parts[]='@'.$box[0].','.$box[1].' '.$box[2].'x'.$box[3];
        }
        $lines[]=implode(' ',$parts);
    }
    if(count($elements)>$limit)$lines[]='['.(count($elements)-$limit).' more controls omitted; use query to narrow]';
    return $lines;
}

function portal_westy_cua_compact(mixed $value,int $depth,array $limits,?string $key=null):mixed
{
    if(is_string($value))return portal_westy_cua_clip($value,$limits['string']);
    if(!is_array($value))return $value;
    if($depth>=6)return '[nested data omitted]';
    if(array_is_list($value)){
        if($key==='elements')return portal_westy_cua_elements($value,$limits['elements']);
        $out=[];foreach(array_slice($value,0,$limits['list']) as $item)$out[]=portal_westy_cua_compact($item,$depth+1,$limits);
        if(count($value)>$limits['list'])$out[]='['.(count($value)-$limits['list']).' more items omitted]';
        return $out;
    }
    $out=[];
    // Markdown trees duplicate the structured elements; inline pixels travel as images.
    foreach($value as $name=>$item)if(!in_array($name,['tree_markdown','markdown','data'],true))$out[$name]=portal_westy_cua_compact($item,$depth+1,$limits,(string)$name);
    return $out;
}

/** Bounded model-facing evidence of one driver reply. Images are attached separately. */
function portal_westy_cua_evidence(?array $response,int $images=0):array
{
    if($response===null)return ['response'=>'unavailable'];
    $structured=is_array($response['structuredContent']??null)?$response['structuredContent']:null;
    $texts=[];
    foreach($response['content']??[] as $part)
        if(is_array($part)&&($part['type']??null)==='text'&&is_string($part['text']??null))$texts[]=$part['text'];
    foreach([['string'=>1500,'list'=>60,'elements'=>200,'text'=>4000],['string'=>500,'list'=>25,'elements'=>80,'text'=>1500]] as $limits){
        $evidence=['is_error'=>($response['isError']??false)===true];
        if($structured!==null)$evidence['structured']=portal_westy_cua_compact($structured,0,$limits);
        // A structured element list supersedes the Markdown rendering of the same tree.
        if($texts!==[]&&!isset($structured['elements']))
            $evidence['text']=array_map(static fn(string $text):string=>portal_westy_cua_clip($text,$limits['text']),array_slice($texts,0,3));
        if($images>0)$evidence['images_attached']=$images;
        $wire=json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if(is_string($wire)&&strlen($wire)<=PORTAL_WESTY_CUA_EVIDENCE_BYTES)return $evidence;
    }
    return ['is_error'=>($response['isError']??false)===true,'truncated'=>true,
        'text'=>portal_westy_cua_clip((string)$wire,PORTAL_WESTY_CUA_EVIDENCE_BYTES-200)]+($images>0?['images_attached'=>$images]:[]);
}

/** Closed driver codes only; the public receipt never carries screen content. */
function portal_westy_cua_facts(?array $response):array
{
    $structured=is_array($response['structuredContent']??null)?$response['structuredContent']:[];$facts=[];
    $code=static fn(mixed $value):bool=>is_string($value)&&preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$value)===1;
    foreach(['effect','code','route'] as $key)if($code($structured[$key]??null))$facts['driver_'.$key]=$structured[$key];
    $escalation=$structured['escalation']['recommended']??$structured['escalation']['target']??null;
    if($code($escalation))$facts['driver_escalation']=$escalation;
    return $facts;
}

function portal_westy_cua_guidance(array $public):string
{
    return match($public['state']??null){
        'returned'=>($public['driver_error']??false)
            ?'The driver did not complete this call. Read its error and correct the call or observe again; do not repeat it unchanged.'
            :'The driver answered. Check its result, then observe again to verify the requested outcome before reporting success.',
        'rejected'=>'Not sent. Correct the arguments and try again.',
        'refused'=>'Not run. Do not repeat it unchanged; explain the reason or choose another supported step.',
        'cancelled'=>'Cancelled before it ran. Do not repeat it unless the person asks again.',
        default=>'The outcome is unknown and computer control may have stopped. Do not repeat this call. Observe first if control is still available.',
    };
}

/** Validate, dispatch once and shape the result exactly like the other desktop tools. */
function portal_westy_cua_tool(array $context,string $tool,mixed $arguments,callable $alive,?callable $transport=null):array
{
    try{
        $request=portal_westy_cua_request($tool,$arguments);
        $params=json_encode(['name'=>$request['name'],'arguments'=>(object)$request['arguments']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(strlen($params)>60000)portal_westy_cua_reject('This call is too large. Send less text at once.');
    }catch(PortalWestyCuaRejected $error){
        $public=['state'=>'rejected','reason'=>'invalid_arguments','executed'=>false,'correction_allowed'=>true,'retry_allowed'=>false];
        return ['public_result'=>$public+['guidance'=>portal_westy_cua_guidance($public).' '.$error->getMessage()]];
    }
    $dispatch=portal_westy_cua_dispatch($context,['method'=>'tools/call','params_json'=>$params],$alive,$transport);
    $response=$dispatch['private_observation']['cua']??null;$response=is_array($response)?$response:null;
    $public=$dispatch['public_result']+$request['public']+portal_westy_cua_facts($response);
    $public['guidance']=portal_westy_cua_guidance($public);
    $result=['public_result'=>$public,'images'=>$dispatch['images']??[]];
    if(($public['state']??null)==='returned')$result['private_observation']=portal_westy_cua_evidence($response,count($result['images']));
    return $result;
}
