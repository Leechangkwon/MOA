# 지시서 — 옛 계산 모듈을 떼어내 지운다

## 1. 수정 파일
### 1-1. 삭제 (git rm)
- `src/calc/` 전부 · `public/assets/calc_*.js` · `tests/calc_*.sh`
### 1-2. 이동 (git mv)
| 지금 | 옮길 곳 |
|---|---|
| `docs/calc/howto.md` | `docs/howto.md` |
### 1-3. 새로 만드는 파일
- `tests/calc_gone.sh` — 잔재가 없는지 보는 시험
