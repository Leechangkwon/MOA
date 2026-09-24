# 견본 공용 도우미(가짜) — 단언 도우미는 FAIL 을 센다
PASS=0; FAIL=0
expect() {
  if [ "$2" = "$3" ]; then printf '  ok %s\n' "$1"; PASS=$((PASS+1))
  else printf '  NG %s (want=%s got=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
psqlq() { psql -X -At -d demo_db -c "$1"; }
db_read_var() { local _to="$1" _v; _v=$(psql -X -At -d "$2" -c "$3"); printf -v "$_to" '%s' "$_v"; }
mark() { psqlq "SELECT coalesce(max(id),0) FROM audit_logs"; }
