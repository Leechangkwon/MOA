#!/usr/bin/env bash
# 지울 디스크 파일 목록 만들기 — 읽기만 한다. delete-plan.sql 을 돌리기 **전**의 DB 에서 뽑아야 한다.
# 쓰임: make-file-list.sh <DB> <keep_programs 0|1> <출력 파일>
# 출력: 절대경로|바이트 (한 줄에 하나). 끝에 갈래별 개수·바이트와 남는 파일을 보여 준다.
set -euo pipefail
DB=$1; KEEP=$2; OUT=$3
ROOT=/var/lib/tbox-v2
W=$(mktemp -d)
trap 'rm -rf "$W"' EXIT
Q="sudo -n -u postgres psql -X -At -d $DB -c"

$Q "SELECT stored_name FROM public.files WHERE NOT (program AND program_current AND $KEEP = 1)" | sed 's/^/T|/' > "$W/names"
$Q "WITH RECURSIVE proj AS (SELECT d.page_id AS root FROM community.databases d JOIN community.pages p ON p.id=d.page_id
       WHERE d.purpose='project' AND p.deleted_at IS NULL),
     tree AS (SELECT p.id FROM community.pages p JOIN proj r ON p.id=r.root
       UNION SELECT c.id FROM community.pages c JOIN tree t ON c.parent_page_id=t.id
             OR c.database_id IN (SELECT id FROM community.databases WHERE page_id=t.id))
     SELECT stored_name FROM community.page_attachments WHERE page_id NOT IN (SELECT id FROM tree)" | sed 's/^/A|/' >> "$W/names"
$Q "SELECT id FROM community.memory_media" | sed 's/^/M|/' >> "$W/names"

sudo -n find "$ROOT/storage" "$ROOT/quarantine" -type f -printf '%p|%s\n' | LC_ALL=C sort > "$W/disk"

awk -F'|' -v root="$ROOT" '
  NR==FNR { want[$1 "|" $2]=1; next }
  {
    p=$1; n=split(p, seg, "/"); base=seg[n]; rel=substr(p, length(root)+2)
    cls=""
    if (rel ~ /^storage\/[0-9a-f][0-9a-f]\/[0-9a-f][0-9a-f]\/[^\/]+$/ && ("T|" base) in want) cls="T"
    else if (rel ~ /^storage\/attachments\/[0-9a-f][0-9a-f]\/[^\/]+$/ && ("A|" base) in want) cls="A"
    else if (rel ~ /^storage\/memories\/[^\/]+$/ && ("M|" base) in want) cls="M"
    else if (rel ~ /^quarantine\/[^\/]+$/) cls="Q"
    if (cls != "") { print cls "|" $0 > "/dev/stdout" } else { print $0 > keepfile }
  }' keepfile="$W/keep" "$W/names" "$W/disk" > "$W/del"

cut -d'|' -f2- "$W/del" > "$OUT"
echo "== 지울 파일 (갈래 T=T-box, Q=격리, M=이야기 사진, A=문서 첨부)"
awk -F'|' '{c[$1]++; b[$1]+=$3} END {for (k in c) printf "  %s %d개 %d B\n", k, c[k], b[k]}' "$W/del"
echo "  합계 $(wc -l < "$OUT")개 $(awk -F'|' '{s+=$2} END {print s+0}' "$OUT") B"
echo "== 남는 파일"
cat "$W/keep"
echo "== DB 에 이름이 있는데 디스크에 없는 것(영구삭제된 행 등) 개수"
awk -F'|' 'NR==FNR {n=split($1, s, "/"); have[s[n]]=1; next} !($2 in have) {c[$1]++} END {for (k in c) printf "  %s %d\n", k, c[k]}' \
  <(cut -d'|' -f1 "$W/disk") "$W/names"
