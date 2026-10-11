<?php
declare(strict_types=1);
// TBOX_ROUTES declares every route authenticated. Central router applies session/CSRF/first-login/consent gates.
require_once dirname(__DIR__).'/site/ProductSpaces.php';
$service=new ProductSpaces($user);$verb=$_SERVER['REQUEST_METHOD'];
$path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
if(str_starts_with($path,TBOX_BASE.'/'))$path=substr($path,strlen(TBOX_BASE));
try {
    if(isset($_GET['kind'])&&!is_string($_GET['kind']))throw new ProductSpaceError(400);
    $itemOptions=array_intersect_key($_GET,array_flip(['view','assignee','limit']));
    if(array_key_exists('view',$_GET))$itemOptions=array_diff_key($_GET,['kind'=>true]);
    $body=in_array($verb,['POST','PATCH'],true)?Http::jsonBody():[];
    if($path==='/api/me/home-dashboard')$result=$verb==='PATCH'?$service->dashboardSave(null,$body):$service->dashboard(null);
    elseif($path==='/api/me/product-home')$result=$service->home(null);
    elseif($path==='/api/product-spaces')$result=$verb==='POST'?$service->create($body):$service->spaces();
    elseif($path==='/api/product-spaces/join')$result=$service->join($body);
    else {
        $sid=$params[0]??'';
        if(str_ends_with($path,'/download')){
            $fileId=$service->downloadFile($sid,$params[1]??'');
            // Same original file row/gates/integrity/audit/stream as legacy download, after membership check.
            $params=[$fileId];require __DIR__.'/files_download.php';exit;
        }
        elseif(str_ends_with($path,'/transfer'))$result=$service->transfer($sid,$body);
        elseif(str_ends_with($path,'/home')){
            if($_GET!==[]&&$_GET!==['view'=>'layout'])throw new ProductSpaceError(400);
            $result=$service->home($sid,$_GET===[]);
        }
        elseif(str_ends_with($path,'/dashboard'))$result=$verb==='PATCH'?$service->dashboardSave($sid,$body):$service->dashboard($sid);
        elseif(preg_match('~/members(?:/[0-9]+)?$~',$path))$result=count($params)>1?$service->memberChange($sid,(int)$params[1],$verb==='DELETE'?null:$body):$service->members($sid);
        elseif(preg_match('~/invites(?:/[0-9a-f-]{36})?$~',$path))$result=count($params)>1?$service->inviteRevoke($sid,$params[1]):($verb==='POST'?$service->inviteCreate($sid,$body):$service->invites($sid));
        elseif(preg_match('~/items(?:/[0-9a-f-]{36})?$~',$path))$result=count($params)>1?($verb==='GET'?$service->item($sid,$params[1]):$service->itemChange($sid,$params[1],$verb==='DELETE'?null:$body)):($verb==='POST'?$service->itemCreate($sid,$body):$service->items($sid,isset($_GET['kind'])&&is_string($_GET['kind'])?$_GET['kind']:null,$itemOptions));
        else $result=$verb==='PATCH'?$service->update($sid,$body):$service->get($sid);
    }
    Http::json($result,$verb==='POST'&&$path!=='/api/product-spaces/join'&&!str_ends_with($path,'/transfer')?201:200);
} catch(ProductSpaceError $error) {
    if(in_array($error->status,[403,404],true))Audit::deny('product.request','not_authorized',['target_type'=>'product_space']);
    $message=match($error->status){403=>Http::MSG_FORBIDDEN,404=>Http::MSG_NOT_FOUND,409=>$error->reason==='last_owner'?'마지막 소유자는 나갈 수 없습니다. 소유권을 먼저 이전하세요.':'다른 변경이 저장되었습니다. 새로 불러온 뒤 다시 저장하세요.',429=>'잠시 후 다시 시도하세요.',default=>Http::MSG_BAD_REQUEST};
    Http::json(['error'=>$message]+($error->reason!==''?['reason'=>$error->reason]:[]),$error->status);
}
