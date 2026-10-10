<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/Programs.php';
$programs=Programs::listing($user);
$publisher=Programs::mayPublish($user);
$candidates=$publisher ? Programs::candidates($user) : [];
$teams=$publisher ? Db::instance()->run('SELECT id,name FROM teams WHERE is_active ORDER BY name')->fetchAll() : [];
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$pageTitle='AI 비서 배포함'; $activeNav='programs'; $pageKey='programs';
require dirname(__DIR__) . '/views/shell_top.php';
?>
<header class="page-head"><div class="page-head__text"><h1 class="page-head__title">AI 비서 배포함</h1>
<p class="page-head__sub">사용하던 PC 비서의 업데이트를 내려받습니다. 기존 바로가기·파일 팔레트·하나의 트레이를 유지합니다.</p></div></header>
<p><a class="btn btn--quiet" href="<?= Http::url('/welcome?section=pc-assistant') ?>">받은 다음 어떻게 하나요? 설치·연결 안내</a></p>
<div id="programs-root" data-base="<?= $e(rtrim(Http::url('/'),'/')) ?>" data-csrf="<?= $e(Auth::csrfToken()) ?>">
<p id="programs-status" class="help" role="status" aria-live="polite"></p>
<?php if (!$programs): ?>
<section class="card card--pad"><h2>기존 비서 업데이트를 준비하고 있어요</h2><p>기존 앱에 Gemini와 GPT를 통합한 업데이트의 게시를 준비하고 있습니다. AI는 기존 파일 팔레트(Ctrl+Shift+Space)에서 AI 단추 또는 Ctrl+J로 엽니다.</p><p class="help">기존 바로가기·파일 팔레트·단일 트레이를 유지합니다. 현재 검증 PC의 Ctrl+Alt+Space 등록 충돌과 실제 Gemini 응답 확인은 남아 있습니다.</p></section>
<?php endif; ?>
<?php foreach ($programs as $program): ?>
<section class="card card--pad">
<h2>기존 PC AI 비서 업데이트 · <?= $e($program['version']) ?></h2>
<p><?= $e($program['name']) ?> · <?= $e(number_format((int)$program['size_bytes']/1048576,1)) ?> MB</p>
<p class="help">올린 사람 <?= $e($program['uploaded_by']) ?> · 게시 <?= $e((new DateTimeImmutable($program['published_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i')) ?></p>
<details><summary>파일 확인용 SHA256</summary><textarea readonly rows="2" aria-label="SHA256"><?= $e($program['sha256']) ?></textarea></details>
<form data-program-download data-id="<?= $e($program['id']) ?>" data-name="<?= $e($program['name']) ?>">
<p><label><input type="checkbox" name="ack" required> PC에서 실행하는 프로그램입니다. 게시자와 버전을 확인하고 내려받습니다.</label></p>
<button class="btn btn--primary" type="submit">기존 비서 업데이트 ZIP 받기</button>
</form>
<p>기존 비서 폴더와 설정을 백업하고 트레이에서 종료한 뒤, ZIP을 모두 풀어 주세요. 기존 비서는 update.cmd로 원래 설치 폴더를 선택하고, 처음 설치하면 setup.cmd → run.cmd 순서로 실행합니다. 자세한 버튼 위치는 위 설치·연결 안내를 따라 주세요.</p>
</section>
<?php endforeach; ?>
<?php if ($publisher): ?>
<section class="card card--pad"><h2>검증한 기존 비서 업데이트 게시하기</h2>
<p>먼저 <a href="<?= Http::url('/') ?>">파일 화면</a>에서 ZIP을 최상위 폴더의 ‘관리자 전용’ 등급으로 올리세요. 실행 파일 확인·비밀번호·OTP와 악성코드 검사를 통과한 내 파일만 선택할 수 있습니다.</p>
<p>원본 소스 작업 폴더·설정·키를 올리지 말고, 기존 비서에 적용하는 검증 완료 업데이트 ZIP과 전달받은 SHA256을 확인하세요.</p>
<?php if (!$candidates): ?><p class="help">게시 가능한 검사 완료 ZIP이 없습니다. 검사가 끝난 뒤 새로고침하세요.</p><?php else: ?>
<form id="program-publish">
<p><label>업데이트 ZIP <select name="file_id" required><option value="">선택하세요</option><?php foreach ($candidates as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['name']) ?></option><?php endforeach; ?></select></label></p>
<p><label>버전 <input name="version" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._+\-]{0,63}" required placeholder="예: 1.0.0"></label></p>
<p><label>검증 결과의 SHA256 <input name="sha256" maxlength="64" minlength="64" pattern="[a-f0-9]{64}" required autocomplete="off"></label></p>
<p><label>받을 수 있는 범위 <select name="level"><option value="internal">사내</option><option value="public">모든 로그인 회원</option><option value="team">선택한 팀</option><option value="admin-only">관리자 전용</option></select></label></p>
<p><label>팀(팀 등급만) <select name="team_id"><option value="">선택하세요</option><?php foreach ($teams as $team): ?><option value="<?= $e($team['id']) ?>"><?= $e($team['name']) ?></option><?php endforeach; ?></select></label></p>
<p><label>내 비밀번호 <input type="password" name="password" autocomplete="current-password" required></label></p>
<p><label>새 OTP 코드 <input name="otp" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label></p>
<p><label><input type="checkbox" name="ack" required> 기존 앱 업데이트와 검증 결과를 확인했습니다. 이 버전을 게시하면 이전 버전은 배포 목록에서 내려갑니다.</label></p>
<button class="btn btn--primary" type="submit">업데이트 게시</button>
</form>
<?php endif; ?></section>
<?php endif; ?>
</div>
<script src="<?= Http::url('/assets/programs.js') ?>?v=<?= (int)filemtime(dirname(__DIR__,2).'/public/assets/programs.js') ?>" defer></script>
<?php require dirname(__DIR__) . '/views/shell_bottom.php'; ?>
