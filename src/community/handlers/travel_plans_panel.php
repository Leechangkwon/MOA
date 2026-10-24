<?php declare(strict_types=1); ?>
<dialog id="travel-dialog" class="travel-dialog" aria-labelledby="travel-heading">
<header class="dialog-head"><div><p class="eyebrow">여행 계획</p><h2 id="travel-heading">여행 계획과 기록</h2></div><button type="button" class="icon-button" data-travel-close aria-label="여행 계획 닫기">×</button></header>
<div class="dialog-body">
<p class="field-hint">계획은 나만 볼 수 있어요. 일정이 바뀌거나 계획에 없던 순간을 만나도 자유롭게 기록하세요. 연결한 게시글의 공개 범위는 그대로 유지됩니다.</p>
<div class="travel-toolbar"><button type="button" class="primary" id="travel-new">새 여행 계획</button><button type="button" class="secondary" id="travel-refresh">새로 보기</button></div>
<p id="travel-status" role="status"></p><section id="travel-confirm" class="travel-confirm" role="alert" hidden><p id="travel-confirm-message"></p><div class="travel-toolbar"><button type="button" class="secondary" id="travel-confirm-cancel">계속 작성</button><button type="button" class="primary" id="travel-confirm-accept">저장하지 않고 이동</button></div></section><div id="travel-list" class="travel-list"></div>
<form id="travel-form" hidden>
<label class="field">여행 이름<input id="travel-title" maxlength="120" required placeholder="예: 가을의 제주"></label>
<label class="field">어디로 떠나나요?<input id="travel-destination" maxlength="160" placeholder="아직 정하지 않아도 괜찮아요"></label>
<div class="field-row"><label class="field">출발 예정<input id="travel-start" type="date"></label><label class="field">마지막 날<input id="travel-end" type="date"></label></div>
<label class="field">여행 메모<textarea id="travel-notes" maxlength="5000" rows="3" placeholder="함께 가는 사람, 준비할 것, 하고 싶은 것"></textarea></label>
<h3>가보고 싶은 곳과 할 일</h3><p class="field-hint">날짜를 비우거나, 순서와 날짜를 언제든 바꿀 수 있어요. 다녀옴·건너뜀도 표시해 보세요.</p>
<div id="travel-steps" class="travel-steps"></div><button type="button" class="secondary" id="travel-add-step">+ 일정 추가</button>
<p id="travel-error" class="form-error" role="alert"></p>
<div class="travel-toolbar"><button type="submit" class="primary" id="travel-save">계획 저장</button><button type="button" class="secondary" id="travel-record">이 여행 기록하기</button><button type="button" class="text-button danger" id="travel-delete">계획 삭제</button></div>
</form>
<section id="travel-linked" hidden><h3>함께 남긴 순간</h3><div id="travel-records"></div><label class="field">기존 내 기록 연결하기<select id="travel-existing"><option value="">기록을 고르세요</option></select></label><button type="button" id="travel-load-posts" class="secondary">내 기록 불러오기</button><button type="button" id="travel-link" class="secondary">여행 전체에 연결</button></section>
<details class="travel-ai"><summary>AI와 함께 계획 다듬기</summary><p>ChatGPT 등에서 모아 MCP를 연결했다면 여행 계획 읽기·쓰기 권한으로 일정을 함께 만들고 바꿀 수 있어요. 연결 전에는 아래 요청문을 복사해 상담하고, 마음에 드는 일정을 옮겨 적으세요.</p><textarea id="travel-ai-prompt" rows="4" readonly aria-label="여행 계획 AI 요청문"></textarea><button type="button" id="travel-copy-prompt" class="secondary">요청문 복사</button></details>
</div></dialog>
<div id="travel-notice" class="travel-notice" role="status" hidden></div>
