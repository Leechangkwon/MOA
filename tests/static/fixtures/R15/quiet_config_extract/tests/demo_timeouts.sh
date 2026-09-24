#!/usr/bin/env bash
# 가짜 견본(v3 ④ FP-0006 모양) — 저장소 파일에서 sed·grep 로 뽑은 설정 값(숫자+단위)은 비밀이 아니다.
# 두 줄 모두 조용해야 한다(v2 는 이름에 session 이 든 둘째 줄만 울렸다 — 설정 이름의 session 토큰 + sed 글의 / 때문).
MIG="$ROOT/db/migrations/demo_role_timeouts.sql"
pgq() { sudo -n -u postgres psql -X -d postgres -t -A -c "$1"; }
ST_VAL=$(sed -n "s/^ALTER ROLE demo_app SET statement_timeout = '\([0-9]*s\)';\$/\1/p" "$MIG")
IT_VAL=$(sed -n "s/^ALTER ROLE demo_app SET idle_session_timeout = '\([0-9]*s\)';\$/\1/p" "$MIG")
pgq "ALTER ROLE demo_app IN DATABASE demo_tmp SET statement_timeout = '$ST_VAL'"
pgq "ALTER ROLE demo_app IN DATABASE demo_tmp SET idle_session_timeout = '$IT_VAL'"
LIM=$(grep -oE '[0-9]+' "$ROOT/deploy/session_limits.conf" | head -1)
pgq "SELECT $LIM"
