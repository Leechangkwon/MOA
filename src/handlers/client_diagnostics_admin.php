<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/ClientDiagnostics.php';
if (Http::method()==='POST') {
    ClientDiagnostics::setPolicy(Http::jsonBody(1024));Http::json(['ok'=>true]);return;
}
$days=$_GET['days'] ?? '7';
if (!is_string($days) || !ctype_digit($days) || (int)$days<1 || (int)$days>30) Http::error(400,Http::MSG_BAD_REQUEST);
$now=new DateTimeImmutable();
$summary=ClientDiagnostics::summary($now->modify('-'.(int)$days.' days')->format('Y-m-d\TH:i:s.uP'),$now->format('Y-m-d\TH:i:s.uP'));
$recent=Db::instance()->run('SELECT kind,period_start,period_end,created_at,summary FROM client_diagnostic_rollups ORDER BY period_start DESC,kind LIMIT 16')->fetchAll();
if ((string)TBOX_ROUTE==='GET /api/admin/client-diagnostics') { Http::json(['summary'=>$summary,'policy'=>ClientDiagnostics::policy(),'reports'=>$recent]);return; }
$policy=ClientDiagnostics::policy();
$e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$pageTitle='PC 비서 요약 진단';$activeNav='programs';$pageKey='client-diagnostics';
require dirname(__DIR__).'/views/shell_top.php';
?>
<header class="page-head"><div class="page-head__text"><h1 class="page-head__title">PC 비서 요약 진단</h1><p class="page-head__sub">사용자가 전송을 선택한 요약만 모읍니다. 수신 기록이 없는 PC의 상태는 알 수 없습니다.</p></div></header>
<p><a class="btn btn--quiet" href="<?= Http::url('/programs') ?>">AI 비서 배포함</a></p>
<section class="card card--pad"><h2>최근 <?= $e($days) ?>일 관측</h2>
<p>수신일 기준 · 실행 <?= $e($summary['metrics']['observed_runs']) ?>건 · 완료 <?= $e($summary['metrics']['completed_runs']) ?>건 · 설치 <?= $e($summary['metrics']['installations']) ?>개</p>
<p>최초 완료율 <?= $summary['metrics']['first_completion_rate']===null?'관측 없음':$e(number_format(100*$summary['metrics']['first_completion_rate'],1)).'%' ?> · 재개 성공률 <?= $summary['metrics']['resume_success_rate']===null?'관측 없음':$e(number_format(100*$summary['metrics']['resume_success_rate'],1)).'%' ?></p>
<p>최초 완료 <?= $e($summary['metrics']['first_completed_runs']) ?>건 · 재개 <?= $e($summary['metrics']['resumed_runs']) ?>건 중 완료 <?= $e($summary['metrics']['resumed_completed_runs']) ?>건 · 캐시 재사용 <?= $e($summary['metrics']['cache_reuses']) ?>회</p>
<p>AI 요청 시도 <?= $e($summary['metrics']['ai_request_attempts']) ?>회 · 답변 수신 완료 <?= $e($summary['metrics']['completed_responses']) ?>회. 실제 과금·토큰 사용량은 확인하지 않았습니다.</p>
<p class="help">전송된 PC의 자기 보고입니다. 반복 클릭은 같은 실행으로 집계하고 최초·재개·완료 후 재확인을 구분합니다. 원문·AI 답변·인증정보·계정 이름·사용자 경로는 받지 않습니다.</p>
</section>
<section class="card card--pad"><h2>개선 검토 후보</h2>
<?php if (!$summary['improvement_candidates']): ?><p>이 기간에 전송된 오류 기록이 없습니다. 미전송 PC의 성공을 뜻하지는 않습니다.</p><?php endif; ?>
<?php foreach ($summary['improvement_candidates'] as $candidate): ?>
<details><summary><?= $e($candidate['app_version']) ?> · <?= $e($candidate['stage']) ?> · <?= $e($candidate['error_code']) ?> — 설치 <?= $e($candidate['affected_installations']) ?>개 / 실행 <?= $e($candidate['affected_runs']) ?>건</summary>
<p>중단 시도 <?= $e($candidate['interrupted_attempts']) ?>회. <?= $e($candidate['reproduction']) ?></p>
<p>재현 검사 대상: <?= $e($candidate['test_target']) ?></p></details>
<?php endforeach; ?></section>
<section class="card card--pad"><h2>일별·주간 개선 검토</h2><p>서버는 정해진 시각에 요약과 재현 검사 후보를 정리합니다. 코드 수정·배포·업무 승인은 자동으로 하지 않습니다.</p>
<?php foreach ($recent as $report): ?><p><?= $e($report['kind']==='day'?'일별':'주간') ?> · <?= $e($report['period_start']) ?> ~ <?= $e($report['period_end']) ?> · 생성 <?= $e($report['created_at']) ?></p><?php endforeach; ?>
<form id="client-diagnostics-policy" data-base="<?= $e(rtrim(Http::url('/'),'/')) ?>" data-csrf="<?= $e(Auth::csrfToken()) ?>">
<p><label><input type="checkbox" name="daily_enabled"<?= $policy['daily_enabled']?' checked':'' ?>> 일별 요약 만들기</label> <label><input type="checkbox" name="weekly_enabled"<?= $policy['weekly_enabled']?' checked':'' ?>> 주간 개선 후보 만들기</label></p>
<p><label>요약 생성 시각(한국 시간) <input type="number" name="daily_hour" min="0" max="23" value="<?= $e($policy['daily_hour']) ?>" required>시</label></p>
<p><label>주간 검토 요일 <select name="weekly_weekday"><?php foreach ([1=>'월',2=>'화',3=>'수',4=>'목',5=>'금',6=>'토',7=>'일'] as $number=>$label): ?><option value="<?= $e($number) ?>"<?= $policy['weekly_weekday']===$number?' selected':'' ?>><?= $e($label) ?>요일</option><?php endforeach; ?></select></label></p>
<p><label>진단 보관 기간 <input type="number" name="retention_days" min="1" max="30" value="<?= $e($policy['retention_days']) ?>" required>일</label><span class="help">최대 30일이며 지난 진단은 정기 작업에서 삭제합니다. 업무 자료는 대상이 아닙니다.</span></p>
<button class="btn btn--primary" type="submit">진단 운영 설정 저장</button><p id="client-diagnostics-status" role="status" aria-live="polite"></p>
</form></section>
<script src="<?= Http::url('/assets/client-diagnostics.js') ?>?v=<?= (int)filemtime(dirname(__DIR__,2).'/public/assets/client-diagnostics.js') ?>" defer></script>
<?php require dirname(__DIR__).'/views/shell_bottom.php'; ?>
