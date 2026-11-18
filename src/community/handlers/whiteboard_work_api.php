<?php
declare(strict_types=1);

$routePattern=substr(TBOX_ROUTE,strlen((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))+1+strlen(COMMUNITY_BASE));
$integer=static function(string $key,int $default,int $min=0,int $max=2147483646): int {
    $v=$_GET[$key] ?? (string)$default;
    if (!is_string($v) || !preg_match('/^[0-9]{1,15}$/D',$v)) { Http::error(400,Http::MSG_BAD_REQUEST); }
    return WhiteboardSync::integer((int)$v,$min,$max);
};
$db=Db::instance(); $db->begin();
try {
    if ($routePattern==='/api/work/whiteboards') {
        $args=['limit'=>$integer('limit',30,1,100)];
        foreach(['after_id','project_id','kind'] as $key) { if (isset($_GET[$key])) { $args[$key]=$_GET[$key]; } }
        $tool='qaredi_list_whiteboards'; $result=WhiteboardSnapshot::listing($user,$args);
    } else {
        $id=(string)$params[0];
        $version=isset($_GET['version_no']) ? $integer('version_no',1,1) : null;
        if (str_ends_with($routePattern,'/minutes')) {
            $tool='qaredi_save_meeting_minutes'; $result=WhiteboardSnapshot::minutes($user,$id,Http::jsonBody(131072,12));
        } elseif (str_ends_with($routePattern,'/image')) {
            $tool='qaredi_read_whiteboard_image'; $result=WhiteboardSnapshot::image($user,$id,$version);
        } elseif (str_ends_with($routePattern,'/events')) {
            $tool='qaredi_read_whiteboard_events'; $page=Whiteboards::page($user,$id);
            $result=Whiteboards::events($user,$id,WhiteboardSnapshot::version($page,$version),$integer('after_seq',0),$integer('limit',100,1,500),false);
        } else {
            $tool='qaredi_read_whiteboard'; $result=WhiteboardSnapshot::read($user,$id,$version,$integer('after',0,0,5000));
        }
    }
    Audit::allow('mcp.tool',['target_type'=>'system','target_name'=>'local_mcp','detail'=>['tool'=>$tool]]);
    $db->commit();
} catch(Throwable $e) { if ($db->inTransaction()) { $db->rollback(); } throw $e; }
Http::json($result);
