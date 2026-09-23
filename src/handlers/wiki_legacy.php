<?php
declare(strict_types=1);

/**
 * src/handlers/wiki_legacy.php — GET /wiki/{slug} → 308 /wiki/tbox/{slug} (정책: role:Admin)
 *
 * 책이 나뉘기 전(2026-09-23 이전)의 위키 주소를 살려 두는 자리다. 그때 글은 전부 t-box 것이었으므로
 * 옛 주소 `/tbox/wiki/stage-14` 는 `/tbox/wiki/tbox/stage-14` 로 **영구 이동(308)** 한다
 * (옛 t-box 주소를 308 로 살려 둔 28단계와 같은 결 — 북마크·글 속 링크가 끊기지 않게).
 *
 * 정한 동작
 *   · t-box 책에 **그 글 파일이 있을 때만** 308. 없으면 **바로 404**(`/wiki/zzz` → 404) —
 *     없는 글을 새 주소로 넘겨 거기서 404 를 받게 하면 한 번 더 돌 뿐 얻는 것이 없다.
 *   · Location 은 코드가 만든다: 고정 접두(`Http::url`) + 책 상수(`Wiki::LEGACY_BOOK`) +
 *     SLUG_RE 를 통과한 slug. 요청의 쿼리·헤더(Host·Referer)는 쓰지 않는다 — 열린 리다이렉트가
 *     생길 자리가 없다(`Http::redirectPermanent` 가 `/` 로 시작·`//` 아님·제어문자 없음을 한 번 더 본다).
 *   · 책 이름(`qaredi`·`tbox`·`community`)은 라우트 표에서 `{book}` 줄이 먼저 잡으므로 여기 오지 않는다.
 *
 * ★ 정책은 다른 위키 화면과 같은 `role:Admin` 이다 — 옮겨 주는 일도 「그 글이 있다」를 알려 주므로
 *   Admin 이 아니면 403, 재인증 전이면 재인증 화면으로 간다(`TBOX_REAUTH_RETURN` 의 `wiki` 키).
 * ★ 감사 기록을 남기지 않는다 (PRD F-21).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params [0] => slug
 */

require_once dirname(__DIR__) . '/Wiki.php';

$wikiSlug = (string) ($params[0] ?? '');
if (preg_match(Wiki::SLUG_RE, $wikiSlug) !== 1 || !Wiki::pageExists(Wiki::LEGACY_BOOK, $wikiSlug)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

Http::redirectPermanent(Http::url('/wiki/' . Wiki::LEGACY_BOOK . '/' . $wikiSlug));
