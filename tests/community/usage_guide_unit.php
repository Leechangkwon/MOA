<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/community/UsageGuide.php';
$checks=0;
foreach ([
    ['status'=>'active','context'=>'overview','step'=>0],
    ['status'=>'completed','context'=>'composer','step'=>30],
    ['status'=>'dismissed','context'=>'travel','step'=>1],
] as $valid) { if (UsageGuide::validate($valid)!==$valid) { throw new RuntimeException('valid preference rejected'); } $checks++; }
foreach ([
    [], ['status'=>'active','context'=>'overview','step'=>0,'user_id'=>2],
    ['status'=>'active','context'=>'https://example.invalid','step'=>0],
    ['status'=>'active','context'=>'overview','step'=>'0'],
    ['status'=>'active','context'=>'overview','step'=>-1],
    ['status'=>'active','context'=>'overview','step'=>31],
    ['status'=>'new','context'=>'overview','step'=>0],
    ['status'=>true,'context'=>'overview','step'=>0],
] as $invalid) {
    try { UsageGuide::validate($invalid); }
    catch (InvalidArgumentException $e) { $checks++; continue; }
    throw new RuntimeException('untrusted preference accepted');
}
echo 'usage_guide_unit PASS '.$checks.PHP_EOL;
