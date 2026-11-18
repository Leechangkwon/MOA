<?php
declare(strict_types=1);
require_once __DIR__.'/../../src/community/WhiteboardGraph.php';
$elements=[
 ['id'=>'a','type'=>'rectangle','isDeleted'=>false],['id'=>'b','type'=>'diamond','isDeleted'=>false],
 ['id'=>'ta','type'=>'text','text'=>'자료 "수집" <입력>','containerId'=>'a','isDeleted'=>false],
 ['id'=>'tb','type'=>'text','text'=>'자동 대사','containerId'=>'b','isDeleted'=>false],
 ['id'=>'edge','type'=>'arrow','startBinding'=>['elementId'=>'a'],'endBinding'=>['elementId'=>'b'],'isDeleted'=>false],
 ['id'=>'te','type'=>'text','text'=>'검사 후','containerId'=>'edge','isDeleted'=>false]
];
$graph=WhiteboardGraph::describe($elements);
if (count($graph['nodes'])!==2 || count($graph['connections'])!==1 || $graph['connections'][0]['text']!=='검사 후'
    || str_contains($graph['mermaid'],'<입력>') || !str_contains($graph['mermaid'],'&quot;수집&quot;')) {
    fwrite(STDERR,"Graph evidence mapping failed\n"); exit(1);
}
echo "GRAPH: two bound labels become two nodes, arrow label stays on edge, markup escaped\n";
