<?php
declare(strict_types=1);
$pub=community_publication($params[0]);[$database,$view]=community_public_form($pub,$params[1]);
if(!community_public_rate('form:'.Http::clientIp(),60,60)) { Http::error(429,'잠시 후 다시 시도해 주세요'); }
$schema=community_db_schema_of($database['schema']);$sig=community_public_form_signature($pub,$params[1]);$esc=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>'.$esc($view['name']??'공개 폼').'</title><link rel="icon" type="image/svg+xml" href="'.$esc(Http::url('/assets/moa-favicon.svg')).'?v='.(int) filemtime(__DIR__.'/../../../public/assets/moa-favicon.svg').'"><link rel="stylesheet" href="'.$esc(Http::url('/assets/app.css')).'"></head><body><main class="main"><h1>'.$esc($view['name']??'공개 폼').'</h1><form method="post" action="'.$esc(Http::communityUrl('/pub/'.$params[0].'/form/'.$params[1].'/submit')).'"><input type="hidden" name="signature" value="'.$esc($sig).'">';
foreach($view['questions']??[] as $question) {
    $key=is_string($question)?$question:($question['prop']??'');$p=$key==='title'?['key'=>'title','name'=>'제목','type'=>'text']:DbProps::find($schema,$key);
    if($p===null || !in_array($p['type'],['text','number','checkbox','date','select','multi_select'],true)) { continue; }
    $label=$p['name'].(($question['required']??false)?' (필수)':'');
    if(isset($question['show_if'])) { $label.=' · 조건에 해당할 때만 응답'; }
    echo '<label class="field"><span class="field__label">'.$esc($label).'</span>';
    if(in_array($p['type'],['select','multi_select'],true)) {
        echo '<select name="values['.$esc($key).']'.($p['type']==='multi_select'?'[]':'').'"'.($p['type']==='multi_select'?' multiple':'').'>'.($p['type']==='multi_select'?'':'<option value="">선택하세요</option>');
        foreach($p['options']??[] as $option) { $v=is_array($option)?($option['id']??$option['name']??''):$option;echo '<option value="'.$esc($v).'">'.$esc(is_array($option)?($option['name']??$v):$v).'</option>'; }echo '</select>';
    }else{
        $type=['number'=>'number','checkbox'=>'checkbox','date'=>'date'][$p['type']]??'text';echo '<input type="'.$type.'" name="values['.$esc($key).']"'.($type==='checkbox'?' value="1"':' maxlength="5000"').($type==='number'?' step="any"':'').'>';
    }
    echo '</label>';
}
echo '<button class="btn" type="submit">제출하기</button></form><p class="muted">조건에 맞지 않는 답은 제출할 때 제외됩니다. 모아 공개 폼</p></main></body></html>';
