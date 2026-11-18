<?php
declare(strict_types=1);
/** Q:F-17 / MB-12. Read-only event/snapshot verification; never reseals or repairs records. */
require_once __DIR__.'/lib/cli_bootstrap.php';
require_once __DIR__.'/../src/community/WhiteboardSync.php';
$only=null;
foreach(array_slice($argv,1) as $i=>$arg) {
    if (in_array($arg,['--verify','--strict'],true)) { continue; }
    if ($arg==='--whiteboard') { continue; }
    if ($i>0 && $argv[$i]==='--whiteboard' && preg_match('/^[a-f0-9-]{36}$/D',$arg)) { $only=$arg; continue; }
    fwrite(STDERR,"Usage: whiteboard_chain.php --verify [--whiteboard UUID] [--strict]\n"); exit(3);
}
$db=Db::instance(); $errors=[]; $checked=0;
$sql='SELECT * FROM community.whiteboards'; $bind=[];
if ($only!==null) { $sql.=' WHERE public_id=CAST(:id AS uuid)'; $bind[':id']=$only; }
$boards=$db->run($sql,$bind)->fetchAll();
foreach($boards as $board) {
    $prior=WhiteboardSync::ZERO; $seq=0; $tips=[0=>$prior];
    $events=$db->run("SELECT e.*,to_char(e.at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS at_utc
        FROM community.whiteboard_events e WHERE whiteboard_id=:id ORDER BY seq",[':id'=>(int)$board['id']]);
    while($event=$events->fetch()) {
        $checked++; $seq++;
        if ((int)$event['seq']!==$seq || !hash_equals($prior,$event['prev_hash'])
            || !hash_equals($event['hash'],WhiteboardSync::eventHash($event))) { $errors[]=$board['public_id'].':'.$event['seq']; }
        $prior=$event['hash']; $tips[(int)$event['seq']]=$prior;
    }
    if ($seq!==(int)$board['last_seq']) { $errors[]=$board['public_id'].':last_seq'; }
    $snapshot=$db->run('SELECT * FROM community.whiteboard_snapshots WHERE whiteboard_id=:id',[':id'=>(int)$board['id']])->fetch();
    if ($snapshot) {
        $actual=hash('sha256',WhiteboardSync::canonical(json_decode($snapshot['scene'],true,64,JSON_THROW_ON_ERROR)));
        if (!hash_equals($actual,$snapshot['scene_sha256']) || !isset($tips[(int)$snapshot['last_seq']])
            || !hash_equals($tips[(int)$snapshot['last_seq']],$snapshot['chain_head'])) { $errors[]=$board['public_id'].':snapshot'; }
    } elseif ($board['state']==='locked') { $errors[]=$board['public_id'].':missing_snapshot'; }
}
echo json_encode(['boards'=>count($boards),'events'=>$checked,'mismatches'=>count($errors)],JSON_THROW_ON_ERROR)."\n";
if ($errors!==[]) {
    require_once __DIR__.'/lib/alerts_lib.php';
    cli_raise_alert('AL-6',['source'=>'whiteboard_chain','mismatches'=>array_slice($errors,0,50)],'system');
    exit(2);
}
exit(0);
