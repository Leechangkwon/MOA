-- 반드시 트랜잭션 밖. 재시도 전 같은 이름의 invalid 인덱스/정의 차이를 점검한다.
-- 규모 D2a(docs/scale-d2a-directive.md · 보고 docs/scale-d2a-report.md) — 「읽는 행 수 ≈ 화면에 보이는 행 수」 모양에 쓰는 색인.
-- 새 데이터 0 · 삭제 0 · 판정 0. 같은 정의는 db/schema.sql · db/community/schema.sql 끝 「규모 D2a」 절에도 있다(새로 깔 때).
-- A6 공간 글 목록(updated_at 순) · A8 브리핑 「내 개인 공간」 갈래 · 공지 게시판 갈래
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_space_updated_idx ON community.pages(space_id,updated_at DESC,id DESC) WHERE deleted_at IS NULL;
-- A8 브리핑 「내가 쓴 글」 갈래 · 질문
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_author_updated_idx ON community.pages(author_id,updated_at DESC,id DESC) WHERE deleted_at IS NULL;
-- A8 브리핑 「내가 쓴 글」 갈래의 마감(지남 · 어제 · 오늘) · 검토 — 마감 · 상태가 있는 글만 담는 작은 부분 색인
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_author_due_idx ON community.pages(author_id,due_date) WHERE deleted_at IS NULL AND due_date IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_author_status_idx ON community.pages(author_id,status) WHERE deleted_at IS NULL AND status IS NOT NULL;
-- A7 내 휴지통 — 지운 뿌리(지운 사람 · 시각) · 함께 내려간 하위(부모 · 같은 시각)
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_trash_by_idx ON community.pages(deleted_by,deleted_at DESC) WHERE deleted_at IS NOT NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS pages_trash_parent_idx ON community.pages(parent_page_id,deleted_at) WHERE deleted_at IS NOT NULL;
-- A5 공유 받은 글 — 내 그룹의 공유 · 내가 든 그룹
CREATE INDEX CONCURRENTLY IF NOT EXISTS page_shares_group_idx ON community.page_shares(group_id) WHERE group_id IS NOT NULL;
-- 미반영 규모 C 후보와 같은 이름·정의를 유지한다. 사용자 조건으로 그룹 번호까지 색인에서 읽는다.
CREATE INDEX CONCURRENTLY IF NOT EXISTS share_group_members_user_idx ON community.share_group_members(user_id, group_id);
-- A3 · A4 자료실 목록 · 폴더 파일 수 — 폴더 안 정렬 셋(화면 고르개: 올린 날 · 이름 · 크기 × 오름 · 내림 — 이름 · 크기는 같은 값 안 id 를 증분 정렬)
CREATE INDEX CONCURRENTLY IF NOT EXISTS files_folder_created_idx ON public.files(folder_id,created_at DESC,id DESC) WHERE deleted_at IS NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS files_folder_name_idx ON public.files(folder_id,original_name) WHERE deleted_at IS NULL;
CREATE INDEX CONCURRENTLY IF NOT EXISTS files_folder_size_idx ON public.files(folder_id,size_bytes) WHERE deleted_at IS NULL;
-- A8 브리핑 「검사 중인 내 파일」
CREATE INDEX CONCURRENTLY IF NOT EXISTS files_pending_uploader_idx ON public.files(uploaded_by,created_at DESC,id DESC) WHERE scan_status='pending' AND deleted_at IS NULL;
