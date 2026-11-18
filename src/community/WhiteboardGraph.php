<?php
declare(strict_types=1);

/** Deterministic evidence description. Never calls an AI or interprets drawing text as HTML. */
final class WhiteboardGraph
{
    public static function describe(array $elements): array
    {
        $live = []; $labels = []; $nodes = []; $edges = []; $frames = [];
        foreach ($elements as $e) { if (!($e['isDeleted'] ?? false)) { $live[$e['id']]=$e; } }
        foreach ($live as $id=>$e) {
            if ($e['type']==='text') {
                $target = $e['containerId'] ?? $id;
                $labels[$target] = ($labels[$target] ?? '') . ($e['text'] ?? '');
            }
            if ($e['type']==='frame') { $frames[]=['id'=>$id,'name'=>$e['name'] ?? '안건']; }
        }
        $mermaid = ['flowchart LR']; $nodeIds=[];
        $quote = static fn(string $s): string => str_replace(['&','"','<','>','[',']',"\r","\n"],
            ['&amp;','&quot;','&lt;','&gt;','&#91;','&#93;','',' '], $s);
        foreach ($labels as $id=>$label) {
            if (!isset($live[$id]) || !in_array($live[$id]['type'],['rectangle','diamond','ellipse','text'],true)) { continue; }
            if (trim($label)==='') { continue; }
            $nodeIds[$id]='n'.count($nodeIds);
            $nodes[]=['id'=>$id,'text'=>$label,'frame_id'=>$live[$id]['frameId'] ?? null];
            $mermaid[]='  '.$nodeIds[$id].'["'.$quote(iconv_substr($label,0,300,'UTF-8')).'"]';
        }
        foreach ($live as $e) {
            if ($e['type']!=='arrow') { continue; }
            $from=$e['startBinding']['elementId'] ?? null; $to=$e['endBinding']['elementId'] ?? null;
            if (!isset($nodeIds[$from],$nodeIds[$to])) { continue; }
            $label=$labels[$e['id']] ?? '';
            $edges[]=['id'=>$e['id'],'from'=>$from,'to'=>$to,'text'=>$label];
            $mermaid[]='  '.$nodeIds[$from].' -->'.($label==='' ? '' : '|"'.$quote(iconv_substr($label,0,100,'UTF-8')).'"|').' '.$nodeIds[$to];
        }
        return ['nodes'=>$nodes,'connections'=>$edges,'frames'=>$frames,
            'summary'=>implode("\n", array_column($nodes,'text')), 'mermaid'=>implode("\n",$mermaid)];
    }
}
