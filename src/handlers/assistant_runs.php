<?php
declare(strict_types=1);
use QarediAssistant\{Error,Provider,Request,Service,Store};
require_once dirname(__DIR__) . '/assistant/bootstrap.php';
$streaming=false;
$id=null;

try {
    Provider::ensureEnabled();
    $route=(string)TBOX_ROUTE; $id=$params[0] ?? null;
    $token=(int)TBOX_API_TOKEN_ID;
    if ($route==='POST /api/assistant/runs') {
        $key=$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
        $body=Request::body();
        $r=Store::transaction(function () use ($user,$token,$body,$key) {
            $r=Service::create($user,$token,$body,is_string($key) ? $key:'');
            Audit::allow('assistant.create',['target_type'=>'system','detail'=>['run_id'=>$r['run_id'],'run_status'=>$r['status']]]);
            return $r;
        });
        Http::json($r,202);
    } elseif ($route==='GET /api/assistant/runs') {
        Request::keys($_GET,['limit','cursor']);
        $raw=$_GET['limit'] ?? '20';
        if (!is_string($raw) || !preg_match('/^\d{1,3}$/D',$raw)) Request::invalid();
        $limit=Request::integer((int)$raw,1,100);
        $cursor=$_GET['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) Request::invalid();
        Http::json(Service::listing($user,$limit,$cursor));
    } elseif ($route==='GET /api/assistant/runs/{id}') {
        Http::json(Service::get($user,Request::uuid($id)));
    } elseif ($route==='POST /api/assistant/runs/{id}/cancel') {
        Request::keys(Request::body(),[]);
        [$out,$status]=Store::transaction(function () use ($user,$id) {
            $r=Service::cancel($user,Request::uuid($id));
            Audit::allow('assistant.cancel',['target_type'=>'system','detail'=>['run_id'=>$id,'run_status'=>$r[0]['status']]]);
            return $r;
        });
        Http::json($out,$status);
    } elseif ($route==='POST /api/assistant/runs/{id}/confirm') {
        $body=Request::body();
        [$out,$status]=Store::transaction(function () use ($user,$id,$body) {
            $r=Service::confirm($user,Request::uuid($id),$body);
            Audit::allow('assistant.confirm',['target_type'=>'system','detail'=>['run_id'=>$id,'run_status'=>$r[0]['status']]]);
            return $r;
        });
        Http::json($out,$status);
    } elseif ($route==='GET /api/assistant/runs/{id}/events') {
        $id=Request::uuid($id);
        $r=Store::own((int)$user['id'],$id);
        $raw=$_SERVER['HTTP_LAST_EVENT_ID'] ?? '0';
        if (!is_string($raw) || !preg_match('/^\d{1,9}$/D',$raw)) Request::invalid();
        $after=(int)$raw;
        if ($after > (int)$r['event_seq']) throw new Error(409,'event_cursor_invalid','현재 실행의 마지막 이벤트 이후 번호입니다.');
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store'); header('X-Accel-Buffering: no');
        $streaming=true;
        while (ob_get_level()>0) ob_end_flush();
        echo "retry: 1000\n\n"; flush();
        $until=microtime(true)+10; $heartbeat=0;
        do {
            try { $current=Store::freshUser((int)$user['id'],$token); }
            catch (Error $e) { echo 'event: stream.error' . "\n" . 'data: ' . Request::json(['error'=>$e->payload($id)]) . "\n\n"; flush(); break; }
            $r=Store::own((int)$user['id'],$id);
            foreach (Store::events($id,$after) as $ev) {
                echo 'id: ' . (int)$ev['seq'] . "\n" . 'event: ' . $ev['name'] . "\n";
                echo 'data: ' . Request::json(Service::projectEvent($current,$r,$ev)) . "\n\n";
                $after=(int)$ev['seq'];
            }
            flush();
            if (connection_aborted() || (in_array($r['status'],Store::FINAL,true) && $after >= (int)$r['event_seq'])) break;
            if (microtime(true)>=$heartbeat) { echo ": heartbeat\n\n"; flush(); $heartbeat=microtime(true)+3; }
            usleep(250000);
        } while (microtime(true)<$until);
    }
} catch (Error $e) {
    if (in_array($e->http,[403,404],true)) Audit::deny('assistant.request','not_permitted',['target_type'=>'system']);
    elseif ($e->http===409) Audit::deny('assistant.request','invalid_state',['target_type'=>'system']);
    elseif ($e->http===429) Audit::deny('assistant.request','quota',['target_type'=>'system']);
    $payload=['error'=>$e->payload(is_string($id) ? $id:null)];
    if ($streaming) { echo "event: stream.error\ndata: " . Request::json($payload) . "\n\n"; flush(); }
    else {
        if ($e->http===429) header('Retry-After: 60');
        Http::json($payload,$e->http);
    }
} catch (Throwable $e) {
    error_log('[assistant] request_id=' . TBOX_REQUEST_ID . ' failure=' . get_class($e));
    $payload=['error'=>(new Error(500,'server_error','서버 오류입니다.',true))->payload()];
    if ($streaming) { echo "event: stream.error\ndata: " . Request::json($payload) . "\n\n"; flush(); }
    else Http::json($payload,500);
}
