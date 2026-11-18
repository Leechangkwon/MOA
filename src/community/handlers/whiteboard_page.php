<?php
declare(strict_types=1);
$id=(string)$params[0];
$page=Whiteboards::page($user,$id);
$e=static fn(string $s): string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
header('Cache-Control: no-store');
?><!doctype html>
<html lang="ko"<?=Http::themeAttr()?><?=Design::attrs()?><?=($user['high_contrast'] ?? false)===true ? ' data-contrast="high"' : ''?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>회의 그림판 · 모아</title>
<link rel="stylesheet" href="<?=Http::url('/assets/app.css')?>?v=<?=filemtime(__DIR__.'/../../../public/assets/app.css')?>">
<?=Design::assets(false)?>
<link rel="stylesheet" href="<?=Http::url('/assets/community/whiteboard.css')?>?v=<?=filemtime(__DIR__.'/../../../public/assets/community/whiteboard.css')?>">
<link rel="stylesheet" href="<?=Http::url('/assets/community/vendor/excalidraw/editor.css')?>?v=<?=substr(hash_file('sha256',__DIR__.'/../../../public/assets/community/vendor/excalidraw/editor.css'),0,12)?>">
</head><body class="whiteboard-page" data-base="<?=$e(TBOX_BASE)?>" data-community-base="<?=$e(COMMUNITY_BASE)?>" data-page-id="<?=$e($id)?>" data-csrf="<?=$e(Auth::csrfToken())?>" data-vendor-version="<?=substr(hash_file('sha256',__DIR__.'/../../../public/assets/community/vendor/excalidraw/editor.js'),0,12)?>">
<header class="whiteboard-head">
<a class="btn btn--quiet" href="<?=$e(COMMUNITY_BASE.'/p/'.$id)?>">작업으로</a>
<h1><?=$e((string)$page['title'])?> <span id="whiteboard-version"></span></h1>
<span id="whiteboard-presence" class="muted"></span>
<select id="whiteboard-versions" aria-label="판 고르기"></select>
<button class="btn btn--quiet" id="whiteboard-history" type="button" aria-expanded="false" aria-controls="whiteboard-records">그린 순서</button>
<button class="btn btn--quiet" id="whiteboard-recover" type="button" hidden>내 그림 보관</button>
<button class="btn" id="whiteboard-confirm" type="button" hidden>판 확정</button>
<button class="btn" id="whiteboard-new" type="button" hidden>새 판에서 이어 그리기</button>
</header>
<p id="whiteboard-status" class="whiteboard-status" role="status">그림판을 불러오고 있어요…</p>
<main class="whiteboard-main"><div id="whiteboard-canvas" aria-label="회의 그림판"></div>
<aside id="whiteboard-records" class="whiteboard-records" aria-label="그린 순서" hidden>
<h2>그린 순서</h2><button class="btn btn--quiet" id="whiteboard-live" type="button">현재 판으로</button>
<p id="whiteboard-fingerprints" class="muted" hidden></p>
<label for="whiteboard-replay">되감기</label><input type="range" id="whiteboard-replay" min="0" max="0" value="0">
<p id="whiteboard-replay-label" class="muted"></p><ol id="whiteboard-event-list"></ol>
</aside></main>
<dialog id="whiteboard-confirm-dialog" aria-labelledby="whiteboard-confirm-title"><form method="dialog">
<h2 id="whiteboard-confirm-title">이 판을 확정할까요?</h2><p>확정하면 이 판은 잠기고 회의 근거로 남아요. 이후에는 새 판에서 이어 그릴 수 있어요.</p>
<p>장면 기록이 원본이며 PNG는 참조 그림으로 함께 보관해요. 원본 지문은 그린 순서에서 확인할 수 있어요.</p>
<p>회의록은 연결한 Claude 앱에서 이 판을 읽어 작성할 수 있어요.</p>
<button class="btn btn--quiet" value="cancel">취소</button><button class="btn" value="confirm">판 확정</button>
</form></dialog>
<script type="module" src="<?=Http::url('/assets/community/whiteboard.js')?>?v=<?=filemtime(__DIR__.'/../../../public/assets/community/whiteboard.js')?>"></script>
</body></html>
