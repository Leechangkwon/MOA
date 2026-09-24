# 메모장 설계 (가짜 견본)

## 감사 문장 표

| 키 (`action|result|reason`) | 문장 틀 | 쓰는 곳 |
|---|---|---|
| `memo.create|allow` | `{actor} 이(가) 메모 「{target}」 을(를) 만들었습니다` | 메모 창구 |
| `memo.create|deny|quota_full` | `{actor} 이(가) 메모를 더 만들려 했지만 칸이 가득 차 거부했습니다` | 메모 창구 |
| `memo.edit|allow` | `{actor} 이(가) 메모 「{target}」 를 고쳤습니다` | 메모 창구 |
| `memo.edit|deny|not_writer` | `{actor} 이(가) 남의 메모 「{target}」 을(를) 고치려 해서 막았습니다` | 메모 창구 |
| `memo.edit|deny|locked_memo` | `{actor} 이(가) 잠긴 메모 「{target}」 을(를) 고치려 해서 막았습니다` | 메모 창구 |
| `memo.view|deny|not_writer` | `{actor} 이(가) 남의 메모 「{target}」 을(를) 열려 해서 막았습니다` | 메모 창구 |
| `memo.view|allow|curator` | `{actor} 이(가) 관리자로서 메모 「{target}」 을(를) 열었습니다` | 메모 창구 |
| `memo.delete|allow` | `{actor} 이(가) 메모 「{target}」 을(를) 지웠습니다` | 메모 창구 |
