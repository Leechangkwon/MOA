<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php';
Member::requireMessenger($user);
$db=Db::instance(); $uid=(int)$user['id'];
if ($params===[]) {
    $q=Http::queryText('q',64);
    $like='%'.str_replace(['#','%','_'],['##','#%','#_'],$q).'%';
    $rows=$db->run(<<<'SQL'
        SELECT u.id,u.username,u.display_name,
          (SELECT count(*) FROM member_messages m WHERE m.sender_id=u.id AND m.recipient_id=:uid1 AND m.read_at IS NULL) AS unread,
          (SELECT max(m.id) FROM member_messages m WHERE (m.sender_id=u.id AND m.recipient_id=:uid2) OR (m.recipient_id=u.id AND m.sender_id=:uid3)) AS last_id
        FROM users u WHERE u.id<>:uid4 AND u.is_active AND u.role IN ('Admin','Manager','Employee')
          AND (u.expires_at IS NULL OR u.expires_at>now()) AND u.username NOT LIKE 'claude\_%' AND u.username NOT LIKE 'qa\_%'
          AND (u.username ILIKE :q1 ESCAPE '#' OR u.display_name ILIKE :q2 ESCAPE '#')
        ORDER BY last_id DESC NULLS LAST,u.display_name,u.username LIMIT 100
        SQL,[':uid1'=>$uid,':uid2'=>$uid,':uid3'=>$uid,':uid4'=>$uid,':q1'=>$like,':q2'=>$like])->fetchAll();
    Http::json(['people'=>$rows]); exit;
}
// Q:F-11 C-1 (R-118 전송 시도 상한): 모든 POST 시도 분당 120 — 상대·본문 검사 전, 트랜잭션 밖이라 없는 상대 404·모양 틀림 400·재전송 200/409 도 센다(새 행 30 은 아래 그대로).
if (Http::method()==='POST') Member::rate('message-attempt',(string)$uid,120);
$peer=Member::peer((int)$params[0],$user); $pid=(int)$peer['id'];
if (Http::method()==='POST') {
    // Q:F-11 지시서 A-4 (R-118): request_id(UUIDv4) 필수 — 같은 사람·같은 request_id 는 한 행(여러 탭·재시도).
    $body=Http::jsonBody(24000);
    $uuidRe='/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    $keys=array_keys($body);sort($keys);$attachment=$body['attachment_id']??null;$request=$body['request_id']??null;
    if (!in_array($keys,[['request_id','text'],['attachment_id','request_id','text']],true) || !is_string($body['text']) || preg_match('//u',$body['text'])!==1
        || !is_string($request) || preg_match($uuidRe,$request)!==1
        || ($attachment!==null && (!is_string($attachment)||preg_match($uuidRe,$attachment)!==1))) Http::error(400,Http::MSG_BAD_REQUEST);
    $message=trim($body['text']);
    if ($attachment!==null && $message==='') $message='AI로 만든 이미지';
    if ($message==='' || iconv_strlen($message,'UTF-8')>4000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$message)) Http::error(400,'메시지를 1~4,000자로 입력해 주세요.');
    $db->begin();
    try {
        // One send per sender at a time. NO KEY UPDATE (not FOR UPDATE): the INSERT's foreign-key check takes
        // FOR KEY SHARE on the recipient's users row, so two people writing to each other must not deadlock.
        $lock='SELECT id FROM users WHERE id=:uid FOR NO KEY UPDATE';
        $db->run($lock,[':uid'=>$uid]);
        $old=$db->run('SELECT id,recipient_id,body,ai_attachment_id FROM member_messages WHERE sender_id=:uid AND request_id=CAST(:request AS uuid)',
            [':uid'=>$uid,':request'=>$request])->fetch();
        if ($old) {
            $db->commit();
            if ((int)$old['recipient_id']!==$pid || $old['body']!==$message || $old['ai_attachment_id']!==$attachment) Http::error(409,'전송 내용을 확인하고 새로 보내 주세요.');
            Http::json(['ok'=>true,'id'=>(int)$old['id']]); exit;
        }
        if ($attachment!==null) {
            $image=$db->run("SELECT id FROM member_ai_assists WHERE id=CAST(:id AS uuid) AND user_id=:uid AND peer_id=:peer AND action='image' AND state='complete' AND stored_name IS NOT NULL FOR UPDATE",
                [':id'=>$attachment,':uid'=>$uid,':peer'=>$pid])->fetch();
            if (!$image) { $db->rollback();Http::error(404,'이 대화에 첨부할 수 있는 이미지가 아닙니다.'); }
            $prior=$db->run('SELECT id,body FROM member_messages WHERE ai_attachment_id=CAST(:id AS uuid) AND sender_id=:uid AND recipient_id=:peer',
                [':id'=>$attachment,':uid'=>$uid,':peer'=>$pid])->fetch();
            if ($prior) {
                $db->commit();
                if ($prior['body']!==$message) Http::error(409,'이미 보낸 이미지입니다. 대화에서 전송 결과를 확인해 주세요.');
                Http::json(['ok'=>true,'id'=>(int)$prior['id']]);exit;
            }
        }
        // The per-minute limit counts only a new row (a resend of the same request does not use it up).
        Member::rate('message',(string)$uid,30);
        $row=$db->run('INSERT INTO member_messages(sender_id,recipient_id,body,ai_attachment_id,request_id) VALUES(:me,:peer,:body,CAST(:image AS uuid),CAST(:request AS uuid)) RETURNING id,sent_at',
            [':me'=>$uid,':peer'=>$pid,':body'=>$message,':image'=>$attachment,':request'=>$request])->fetch();
        $db->commit();
    } catch (Throwable $e) { $db->rollback();throw $e; }
    Audit::allow('member.message.send',
        ['target_type'=>'user','target_id'=>$pid]);
    Http::json(['ok'=>true,'id'=>(int)$row['id']],201); exit;
}
if (Http::method()==='PATCH') {
    $body=Http::jsonBody(1024); $through=$body['through']??null;
    if (array_keys($body)!==['through']||!is_int($through)||$through<1) Http::error(400,Http::MSG_BAD_REQUEST);
    $db->run('UPDATE member_messages SET read_at=now() WHERE recipient_id=:me AND sender_id=:peer AND id<=:through AND read_at IS NULL',
        [':me'=>$uid,':peer'=>$pid,':through'=>$through]);
    Http::json(['ok'=>true]); exit;
}
$before=Http::queryText('before',20);
if ($before!=='' && (!ctype_digit($before) || strlen($before)>18)) Http::error(400,Http::MSG_BAD_REQUEST);
$before=$before!==''?$before:'9223372036854775807';
$rows=$db->run('SELECT id,sender_id,body,sent_at,read_at,ai_attachment_id FROM member_messages WHERE ((sender_id=:me1 AND recipient_id=:peer1) OR (sender_id=:peer2 AND recipient_id=:me2)) AND id<CAST(:before AS bigint) ORDER BY id DESC LIMIT 60',
        [':me1'=>$uid,':me2'=>$uid,':peer1'=>$pid,':peer2'=>$pid,':before'=>$before])->fetchAll();
foreach ($rows as &$row) $row['image_url']=$row['ai_attachment_id']===null?null:Http::url('/api/messages/attachments/'.$row['ai_attachment_id']);
unset($row);
Http::json(['peer'=>['id'=>$pid,'username'=>$peer['username'],'display_name'=>$peer['display_name']],'messages'=>array_reverse($rows),'has_more'=>count($rows)===60]);
