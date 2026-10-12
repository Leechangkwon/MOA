<?php
declare(strict_types=1);
// CLI-only actual window view; synthetic permissions, no bootstrap/session/database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TBOX_BASE', '/tbox');
define('COMMUNITY_BASE', '/community');
require dirname(__DIR__, 2) . '/src/Http.php';
final class Authz {
    public static function canCommunityWrite(array $user): bool { return true; }
    public static function canManageUsers(array $user): bool { return false; }
}
$messengerUser = ['id'=>1,'username'=>'synthetic_owner','display_name'=>'합성 사용자'];
?>
<!doctype html><html lang="ko" data-design="moa" data-theme="light"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>합성 메신저 위치 검사</title>
<link rel="stylesheet" href="/tbox/assets/app.css"><link rel="stylesheet" href="/tbox/assets/design.css"><link rel="stylesheet" href="/tbox/assets/moa.css">
</head><body data-base="/tbox" data-community-base="/community">
<main id="main"><button id="fixture-open" type="button" class="btn" data-messenger-open aria-controls="messenger-window" aria-expanded="false">메신저 열기 <span class="badge" data-message-unread hidden></span></button><button id="fixture-behind" type="button" class="btn btn--quiet">뒤 화면 동작</button></main>
<dialog id="fixture-modal" class="modal modal--form"><div class="modal__head"><h2 class="modal__title">합성 확인</h2></div><div class="modal__body"><button type="button" class="btn">확인</button></div></dialog>
<?php require dirname(__DIR__, 2) . '/src/views/messenger_window.php'; ?>
</body></html>
