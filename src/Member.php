<?php
declare(strict_types=1);
/** Invitation onboarding and member messaging. Authorization remains in route policies. */
final class Member
{
    public static function rate(string $kind, string $identity, int $limit): void
    {
        $row = Db::instance()->run(<<<'SQL'
            INSERT INTO member_rate_limits(key_hash) VALUES (:key)
            ON CONFLICT (key_hash) DO UPDATE SET
              hits = CASE WHEN member_rate_limits.window_start < now()-interval '1 minute' THEN 1 ELSE member_rate_limits.hits+1 END,
              window_start = CASE WHEN member_rate_limits.window_start < now()-interval '1 minute' THEN now() ELSE member_rate_limits.window_start END
            RETURNING hits
            SQL, [':key'=>hash('sha256', $kind . ':' . $identity)])->fetch();
        if ((int)$row['hits'] > $limit) {
            header('Retry-After: 60');
            Http::error(429, '요청이 많습니다. 1분 뒤 다시 시도해 주세요.');
        }
    }
    public static function csrf(): string
    {
        $value = $_COOKIE['__Host-moa_join'] ?? '';
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/', $value)) {
            $value = bin2hex(random_bytes(32));
            setcookie('__Host-moa_join', $value, ['expires'=>time()+3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
        }
        return $value;
    }
    public static function requireJoinCsrf(): void
    {
        $cookie = $_COOKIE['__Host-moa_join'] ?? '';
        $header = $_SERVER['HTTP_X_JOIN_CSRF'] ?? '';
        if (!is_string($cookie) || !preg_match('/\A[a-f0-9]{64}\z/', $cookie) || !is_string($header) || !hash_equals($cookie,$header)) {
            Http::error(403, '가입 화면을 새로 열고 다시 시도해 주세요.');
        }
    }
    /** 초대에 붙일 팀이 있고 쓸 수 있나 — 팀 없음(null)은 참. 초대를 만드는 두 길(관리자 직접 · 초대 요청 승인)이 같은 판정을 쓴다 */
    public static function activeTeam(?int $team): bool
    {
        return $team === null || Db::instance()->run('SELECT id FROM teams WHERE id=:id AND is_active',
            [':id'=>$team])->fetch() !== false;
    }
    /**
     * 가입 초대 한 벌 (사이트 H2 — member_invites.php 의 발급을 꺼낸 것 · 초대 요청 승인 뒤 확인 주소도 이것을 부른다).
     * 32바이트 난수 코드 → member_invites 에는 sha256 만. 원문 코드는 돌려주는 `path`(`/tbox/join#invite=…` — 주소 `#` 뒤)에만 있고
     * 저장 · 로그 · 감사에 싣지 않는다. 형식 검사 · 팀 판정(activeTeam) · 속도 제한 · 감사는 부르는 쪽이 한다(두 길의 감사가 다르다).
     * @return array{id:int,path:string,expires_at:string}
     */
    public static function issueInvite(int $createdBy, string $label, ?int $team, int $uses, int $days): array
    {
        $code = bin2hex(random_bytes(32));
        $row = Db::instance()->run("INSERT INTO member_invites(code_hash,label,team_id,created_by,expires_at,max_uses) VALUES(:hash,:label,:team,:by,now()+make_interval(days=>:days),:uses) RETURNING id,expires_at",
            [':hash'=>hash('sha256',$code),':label'=>$label,':team'=>$team,':by'=>$createdBy,':days'=>$days,':uses'=>$uses])->fetch();
        return ['id'=>(int)$row['id'],'path'=>Http::url('/join').'#invite='.$code,'expires_at'=>(string)$row['expires_at']];
    }
    public static function installer(): ?string
    {
        $row = Db::instance()->run("SELECT value FROM member_settings WHERE key='buddy_download_url'")->fetch();
        return is_array($row) && $row['value'] !== '' ? (string)$row['value'] : null;
    }
    public static function requireMessenger(array $user): void
    {
        if (!Authz::canCommunityWrite($user) || ($user['acting'] ?? false)) Http::error(403, Http::MSG_FORBIDDEN);
    }
    public static function peer(int $id, array $user): array
    {
        if ($id === (int)$user['id']) Http::error(400, '다른 구성원을 선택해 주세요.');
        $row = Db::instance()->run("SELECT id,username,display_name,role,team_id FROM users WHERE id=:id AND is_active AND (expires_at IS NULL OR expires_at>now())",
        [':id'=>$id])->fetch();
        if (!is_array($row) || !Authz::canCommunityWrite($row)) Http::error(404, '대화할 수 없는 구성원입니다.');
        return $row;
    }
}
