<?php
declare(strict_types=1);
/** Isolated pure validation tests. No production DB or app bootstrap. */
final class Http {
 public const MSG_BAD_REQUEST='bad request';
 public static function error(int $status,string $message): void { throw new RuntimeException($message,$status); }
}
require __DIR__.'/../../src/community/Memories.php';
require __DIR__.'/../../src/community/TravelPlans.php';
$n=0;
function check(bool $ok,string $label): void { global $n; if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);} $n++; }
function bad(array $body,string $label): void {try {TravelPlans::body($body);}catch(RuntimeException $e){check($e->getCode()===400,$label);return;}check(false,$label);}
$step=['id'=>'11111111-1111-4111-8111-111111111111','title'=>'해변','date'=>null,'status'=>'planned','notes'=>''];
$base=['title'=>'제주','destination'=>'','starts_on'=>null,'ends_on'=>null,'notes'=>'','steps'=>[$step]];
check(TravelPlans::body($base)['starts_on']===null,'undated trip');
$trip=$base;$trip['starts_on']='2026-10-04';$trip['ends_on']='2026-10-05';$trip['steps'][0]['date']='2026-10-09';
check(TravelPlans::body($trip)['steps'][0]['date']==='2026-10-09','rescheduled activity outside original dates accepted');
foreach(['planned','done','skipped'] as $status){$trip=$base;$trip['steps'][0]['status']=$status;check(TravelPlans::body($trip)['steps'][0]['status']===$status,'status '.$status);}
$trip=$base;$trip['starts_on']='2026-02-30';bad($trip,'invalid calendar date');
$trip=$base;$trip['starts_on']='2026-10-06';$trip['ends_on']='2026-10-05';bad($trip,'inverted range');
$trip=$base;$trip['owner_user_id']=22;bad($trip,'owner cannot be supplied');
$trip=$base;$trip['visibility']='public';bad($trip,'cannot publish a plan');
$trip=$base;$trip['steps'][]=$step;bad($trip,'duplicate activity IDs');
$trip=$base;$trip['steps'][0]['id']='../secret';bad($trip,'invalid activity ID');
$trip=$base;$trip['steps'][0]['notes']=str_repeat('가',2001);bad($trip,'activity notes bound');
$trip=$base;$trip['steps'][0]['status']='archived';bad($trip,'unknown status');
$trip=$base;$trip['steps'][0]['post_id']=$step['id'];bad($trip,'record linking is separate owner-guarded operation');
$trip=$base;$trip['steps']=array_fill(0,101,$step);bad($trip,'activity count bound');
$trip=$base;$trip['steps']='[]';bad($trip,'wrong steps shape');
$trip=$base;$trip['title']="bad\0title";bad($trip,'control character blocked');
check(TravelPlans::body(['title'=>'빈 계획'])['steps']===[],'empty plan supported');
echo "TRAVEL_PLAN_UNIT_PASS $n\n";
