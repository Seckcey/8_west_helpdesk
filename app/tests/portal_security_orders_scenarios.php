<?php
/** Called by the real customer binding MySQL fixture. */
$settings['portal_devices']['security_orders_enabled']=true;
$orderCalls=0;
$orderTransport=static function($url,$body,$headers)use(&$orderCalls,$scope):array{
    $orderCalls++;$request=json_decode($body,true);
    check($request['scope']===$scope,'order channel keeps actual customer and actor scope');
    return ['status'=>200,'body'=>json_encode(['contract'=>PORTAL_DEVICES_CONTEXT,'ok'=>true,'result'=>['items'=>[]]])];
};
check(portal_devices_request($pdo,$a,'security_orders',[],$orderTransport)===['items'=>[]],'customer reads own order listing through real bindings');
foreach(['client_staff','client_viewer'] as $role) {
    $limited=$a;$limited['identity']['role']=$role;$before=$orderCalls;
    foreach(['security_review','security_accept','security_continue','security_install','security_refresh'] as $action) {
        check(refused(fn()=>portal_devices_request($pdo,$limited,$action,[],$orderTransport)),'limited role refuses '.$action);
    }
    check($before===$orderCalls,'limited order role never reaches service');
}
$settings['portal_devices']['security_orders_enabled']=false;$before=$orderCalls;
check(refused(fn()=>portal_devices_request($pdo,$a,'security_orders',[],$orderTransport)) && $orderCalls===$before,'disabled order gate makes no request');
$settings['portal_devices']['security_orders_enabled']=true;
$revoked=static function($url,$body,$headers)use($pdo,$a,$orderTransport):array{
    portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'disabled','Synthetic order revocation.');
    return $orderTransport($url,$body,$headers);
};
check(refused(fn()=>portal_devices_request($pdo,$a,'security_orders',[],$revoked)),'revocation during order response prevents disclosure');
portal_transition_binding($pdo,$a['identity']['binding_id'],'northwind-preview',1,11,101,'active','Synthetic fixture restored.');
