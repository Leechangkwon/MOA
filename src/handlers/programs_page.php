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
<p class="page-head__sub">내 PC에서 사용하는 비서를 내려받습니다.</p></div></header>
<div id="programs-root" data-base="<?= $e(rtrim(Http::url('/'),'/')) ?>" data-csrf="<?= $e(Auth::csrfToken()) ?>">
<p id="programs-status" class="help" role="status" aria-live="polite"></p>
<?php if (!$programs): ?>
<section class="card card--pad"><h2>설치본을 준비하고 있어요</h2><p>트레이 상주·Ctrl+Alt+Space·Gemini와 GPT 선택을 확인한 통합본이 올라오면 여기서 받을 수 있습니다.</p><p class="help">이전 GPT 연결 시험본은 통합 설치본이 아닙니다.</p></section>
<?php endif; ?>
<?php foreach ($programs as $program): ?>
<section class="card card--pad">
<h2>PC AI 비서 · <?= $e($program['version']) ?></h2>
<p><?= $e($program['name']) ?> · <?= $e(number_format((int)$program['size_bytes']/1048576,1)) ?> MB</p>
<p class="help">올린 사람 <?= $e($program['uploaded_by']) ?> · 게시 <?= $e((new DateTimeImmutable($program['published_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i')) ?></p>
<details><summary>파일 확인용 SHA256</summary><textarea readonly rows="2" aria-label="SHA256"><?= $e($program['sha256']) ?></textarea></details>
<form data-program-download data-id="<?= $e($program['id']) ?>" data-name="<?= $e($program['name']) ?>">
<p><label><input type="checkbox" name="ack" required> PC에서 실행하는 프로그램입니다. 게시자와 버전을 확인하고 내려받습니다.</label></p>
<button class="btn btn--primary" type="submit">Windows AI 비서 ZIP 받기</button>
</form>
<p>ZIP을 새 폴더에 모두 풀고 동봉된 설치 안내를 따라 주세요. 기존 비서 폴더를 덮어쓰지 마세요.</p>
</section>
<?php endforeach; ?>
<?php if ($publisher): ?>
<section class="card card--pad"><h2>검증한 설치본 게시하기</h2>
<p>먼저 <a href="<?= Http::url('/') ?>">파일 화면</a>에서 ZIP을 최상위 폴더의 ‘관리자 전용’ 등급으로 올리세요. 실행 파일 확인·비밀번호·OTP와 악성코드 검사를 통과한 내 파일만 선택할 수 있습니다.</p>
<p>원본 소스 작업 폴더·설정·키를 올리지 말고, 검증 완료된 배포 ZIP과 전달받은 SHA256을 확인하세요.</p>
<?php if (!$candidates): ?><p class="help">게시 가능한 검사 완료 ZIP이 없습니다. 검사가 끝난 뒤 새로고침하세요.</p><?php else: ?>
<form id="program-publish">
<p><label>설치 ZIP <select name="file_id" required><option value="">선택하세요</option><?php foreach ($candidates as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['name']) ?></option><?php endforeach; ?></select></label></p>
<p><label>버전 <input name="version" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._+\-]{0,63}" required placeholder="예: 1.0.0"></label></p>
<p><label>검증 결과의 SHA256 <input name="sha256" maxlength="64" minlength="64" pattern="[a-f0-9]{64}" required autocomplete="off"></label></p>
<p><label>받을 수 있는 범위 <select name="level"><option value="internal">사내</option><option value="public">모든 로그인 회원</option><option value="team">선택한 팀</option><option value="admin-only">관리자 전용</option></select></label></p>
<p><label>팀(팀 등급만) <select name="team_id"><option value="">선택하세요</option><?php foreach ($teams as $team): ?><option value="<?= $e($team['id']) ?>"><?= $e($team['name']) ?></option><?php endforeach; ?></select></label></p>
<p><label>내 비밀번호 <input type="password" name="password" autocomplete="current-password" required></label></p>
<p><label>새 OTP 코드 <input name="otp" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label></p>
<p><label><input type="checkbox" name="ack" required> 설치본과 검증 결과를 확인했습니다. 이 버전을 게시하면 이전 버전은 배포 목록에서 내려갑니다.</label></p>
<button class="btn btn--primary" type="submit">설치본 게시</button>
</form>
<?php endif; ?></section>
<?php endif; ?>
</div>
<script src="<?= Http::url('/assets/programs.js') ?>?v=<?= (int)filemtime(dirname(__DIR__,2).'/public/assets/programs.js') ?>" defer></script>
<?php require dirname(__DIR__) . '/views/shell_bottom.php'; ?>
