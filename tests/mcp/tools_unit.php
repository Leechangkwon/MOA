<?php
declare(strict_types=1);
/** Runs actual domain/tool PHP against a strict scripted DB fixture; not PostgreSQL integration evidence. */
final class Http {
    public const MSG_BAD_REQUEST = 'bad request'; public const MSG_NOT_FOUND = 'not found';
    public static function error(int $code, string $message): void { throw new McpToolError($code, $message); }
}
final class Authz { public static function authorize(string $policy, array $user): bool { return $user['allowed'] ?? false; } }
final class Auth { public static function csrfToken(): string { throw new RuntimeException('MCP must not access session CSRF'); } }
final class Audit { public static array $events = []; public static function allow(string $action, array $detail): void { self::$events[] = [$action, $detail]; } }
final class FixtureStatement {
    public function __construct(private $value) {}
    public function fetch() { return $this->value; }
    public function fetchAll() { return $this->value; }
    public function fetchColumn() { return $this->value; }
}
final class Db {
    public static array $queue = []; public static array $queries = []; public static int $depth = 0;
    public static function instance(): self { return new self(); }
    public function run(string $sql, array $bind = []): FixtureStatement {
        self::$queries[] = [$sql, $bind];
        $expected = array_shift(self::$queue);
        if (!$expected || !str_contains($sql, $expected[0])) { throw new RuntimeException('Unexpected fixture SQL'); }
        if (isset($expected[2])) { ($expected[2])($bind, $sql); }
        return new FixtureStatement($expected[1]);
    }
    public function begin(): void { self::$depth++; }
    public function commit(): void { if (self::$depth < 1) { throw new RuntimeException('No fixture transaction'); } self::$depth--; }
    public function rollback(): void { self::$depth = 0; }
}
function tbox_uuid_v4(): string { return '44444444-4444-4444-8444-444444444444'; }
require __DIR__ . '/../../src/community/Memories.php';
require __DIR__ . '/../../src/community/TravelPlans.php';
require __DIR__ . '/../../src/mcp/McpTools.php';
$n = 0;
function check(bool $ok, string $label): void { global $n; if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); } $n++; }
function out(string $name, array $args, ?array $context = null): array {
    global $auth;
    $result = McpTools::call($name, $args, $context ?? $auth);
    return json_decode($result['content'][0]['text'], true, 24, JSON_THROW_ON_ERROR);
}
function bad(string $name, array $args, int $status, string $label, ?array $context = null): void {
    try { out($name, $args, $context); } catch (McpToolError $e) { check($e->status === $status, $label); Db::instance()->rollback(); return; }
    check(false, $label);
}
function queued(): void { check(Db::$queue === [] && Db::$depth === 0, 'fixture fully consumed and transaction closed'); }
$auth = ['user' => ['id' => 17, 'username' => 'fixture', 'allowed' => true], 'scopes' => ['memories.read','memories.write','photos.read','travel.read','travel.write'], 'grant_id' => 'fixture'];
$id = '11111111-1111-4111-8111-111111111111'; $media = '22222222-2222-4222-8222-222222222222'; $planId = '33333333-3333-4333-8333-333333333333';
$old = ['id' => $id, 'owner_user_id' => 17, 'caption' => 'Current', 'original_caption' => 'Original immutable', 'location' => 'Place', 'trip' => 'Trip',
    'taken_on' => '2026-10-04', 'visibility' => 'community', 'discoverable' => true, 'revision' => 4,
    'media_ids' => json_encode([$media]), 'original_media_ids' => json_encode([$media]), 'story' => null, 'deleted_at' => null];
$owned = static function (array $bind): void { check(($bind[':owner'] ?? null) === 17, 'SQL owner restricted to trusted account'); };
$ready = ["to_regclass('community.memory_links')", true];
$travelReady = ["to_regclass('community.travel_plan_records')", true];

foreach (['qaredi_create_memory' => ['caption' => 'x', 'taken_on' => '2026-10-04', 'request_id' => $id],
    'qaredi_update_memory' => ['post_id' => $id, 'revision' => 4, 'caption' => 'x']] as $name => $base) {
    foreach (['visibility' => 'shared', 'discoverable' => true, 'media_ids' => [], 'original_caption' => 'changed', 'owner_user_id' => 18] as $field => $value) {
        bad($name, $base + [$field => $value], 400, 'reject unsupported write field ' . $field);
    }
}
foreach ([['post_id' => '../secret'], ['post_id' => strtoupper($id)], ['post_id' => $id, 'role' => 'Admin']] as $args) {
    // The synthetic id contains no a-f letters; uppercase it with an explicit invalid letter for this vector.
    if ($args === ['post_id' => strtoupper($id)]) { $args = ['post_id' => 'ABCDEFAB-1111-4111-8111-111111111111']; }
    bad('qaredi_read_memory', $args, 400, 'read identifiers and fields validated');
}
bad('qaredi_read_memory_photo', ['post_id'=>$id,'media_id'=>$media,'revision'=>4], 403, 'photos scope required on direct call', array_replace($auth, ['scopes'=>['memories.read']]));
Db::$queue = [$ready, ['SELECT * FROM community.memory_posts',
    false, $owned]];
bad('qaredi_read_memory', ['post_id'=>$id], 404, 'other owner or missing row indistinguishable'); queued();
Db::$queue = [$ready, ['SELECT * FROM community.memory_posts',
    $old, $owned], ['SELECT m.id,m.mime,m.bytes',
    [['id'=>$media,'mime'=>'image/jpeg','bytes'=>120]]]];
$read = out('qaredi_read_memory', ['post_id'=>$id])['memory'];
check($read['original_caption'] === 'Original immutable' && $read['media'][0]['id'] === $media, 'read projects original and current metadata');
check(!array_intersect(['owner_user_id','sha256','csrf_token'], array_keys($read))
    && !array_intersect(['url','filename'], array_keys($read['media'][0])), 'read omits private server/session/file fields'); queued();
Db::$queue = [$ready, ['SELECT id,left(caption,240)',
    [], static function ($bind, $sql) { check($bind[':query'] === "' OR TRUE--" && str_contains($sql, 'owner_user_id=:owner'), 'search bound and owner restricted'); }]];
out('qaredi_list_memories', ['query'=>"' OR TRUE--"]); queued();
Db::$queue = [$ready, ['SELECT * FROM community.memory_posts',
    $old]];
bad('qaredi_update_memory', ['post_id'=>$id,'revision'=>3,'caption'=>'New'], 409, 'stale revision rejected before mutation'); queued();
Db::$queue = [$ready, ['SELECT * FROM community.memory_posts',
    $old], ['SELECT id FROM public.users',
    false], ['SELECT id FROM community.memory_posts',
    false],
    ['SELECT p.*, u.username',
    array_replace($old, ['revision'=>5])]];
bad('qaredi_update_memory', ['post_id'=>$id,'revision'=>4,'caption'=>'New'], 409, 'revision rechecked under real domain save lock'); queued();
$new = array_replace($old, ['caption'=>'New', 'revision'=>5]);
Db::$queue = [$ready, ['SELECT * FROM community.memory_posts',
    $old], ['SELECT id FROM public.users',
    false], ['SELECT id FROM community.memory_posts',
    false],
    ['SELECT p.*, u.username',
    $old], ['SELECT owner_user_id,post_id',
    ['owner_user_id'=>17,'post_id'=>$id]],
    ['UPDATE community.memory_posts SET',
    false, static function ($bind, $sql) use ($old) {
        check($bind[':caption']==='New' && $bind[':visibility']==='community' && $bind[':discoverable']==='true', 'caption update preserves public audience/discovery');
        check($bind[':location']===$old['location'] && $bind[':trip']===$old['trip'] && $bind[':media']===$old['media_ids'], 'partial update preserves omitted fields and media');
        check(!str_contains($sql,'original_caption') && !str_contains($sql,'original_media_ids'), 'update never writes original snapshots');
    }], ['UPDATE community.memory_media SET',
    false], ['SELECT * FROM community.memory_posts',
    $new], ['SELECT m.id,m.mime,m.bytes',
    []]];
check(out('qaredi_update_memory', ['post_id'=>$id,'revision'=>4,'caption'=>'New'])['memory']['revision']===5, 'shared post update revision advanced'); queued();
Db::$queue = [$ready];
bad('qaredi_create_memory', ['caption'=>'x','taken_on'=>'2026-02-30','request_id'=>$id], 400, 'existing memory date validator reused'); queued();
Db::$queue = [$ready];
bad('qaredi_create_memory', ['caption'=>' ','taken_on'=>'2026-10-04','request_id'=>$id], 400, 'empty new memory rejected by shared validator'); queued();
$created = array_replace($old, ['id'=>tbox_uuid_v4(),'caption'=>'Private new','original_caption'=>'Private new','media_ids'=>'[]','original_media_ids'=>'[]','visibility'=>'private','discoverable'=>false,'revision'=>1]);
Db::$queue = [$ready, ['SELECT id FROM public.users',
    false], ['SELECT id FROM community.memory_posts WHERE owner_user_id',
    false],
    ['INSERT INTO community.memory_posts',
    false, static function ($bind) {
        check($bind[':visibility']==='private' && $bind[':discoverable']==='false' && $bind[':media']==='[]', 'creation always private without attached files');
        check($bind[':caption']===$bind[':original'] && $bind[':original_media']==='[]', 'creation preserves original snapshot');
    }], ['SELECT * FROM community.memory_posts',
    $created], ['SELECT m.id,m.mime,m.bytes',
    []]];
out('qaredi_create_memory', ['caption'=>'Private new','taken_on'=>'2026-10-04','request_id'=>$id]); queued();
Db::$queue = [$ready, ['SELECT id FROM public.users',
    false], ['SELECT id FROM community.memory_posts WHERE owner_user_id',
    $id],
    ['SELECT * FROM community.memory_posts',
    $old], ['SELECT m.id,m.mime,m.bytes',
    []]];
check(out('qaredi_create_memory', ['caption'=>'Retry','taken_on'=>'2026-10-04','request_id'=>$id])['replayed']===true, 'request UUID reuses existing save idempotency'); queued();
Db::$queue = [$ready, ['SELECT * FROM community.memory_posts',
    $old], ['SELECT m.id,m.mime,m.bytes,m.sha256',
    false, static function ($bind,$sql) {
    check($bind[':post_owner']===17 && $bind[':media_owner']===17 && str_contains($sql,'p.media_ids @>') && str_contains($sql,'p.id=m.post_id'), 'photo requires current own attachment on same post');
}]];
bad('qaredi_read_memory_photo', ['post_id'=>$id,'media_id'=>$media,'revision'=>4], 404, 'detached/original-only photo denied'); queued();
Db::$queue = [$travelReady, ['SELECT id,title,destination',
    [], $owned]];
check(out('qaredi_list_travel_plans', [])===['plans'=>[]], 'travel listing bypasses session CSRF entirely'); queued();
Db::$queue = [$travelReady];
bad('qaredi_save_travel_plan', ['plan'=>['title'=>'Trip'],'plan_id'=>$planId], 400, 'plan update requires revision'); queued();
Db::$queue = [$travelReady];
bad('qaredi_save_travel_plan', ['plan'=>['title'=>'Trip'],'request_id'=>$id,'revision'=>1], 400, 'creation cannot take update revision'); queued();
Db::$queue = [$travelReady];
bad('qaredi_save_travel_plan', ['plan'=>['title'=>'Trip','starts_on'=>'2026-10-06','ends_on'=>'2026-10-04'],'request_id'=>$id], 400, 'travel domain date-range validator reused'); queued();
$plan = ['id'=>$planId,'owner_user_id'=>17,'title'=>'Trip','steps'=>'[]','revision'=>7];
Db::$queue = [$travelReady, ['SELECT id FROM public.users',
    false], ['SELECT * FROM community.travel_plans',
    $plan, $owned]];
bad('qaredi_link_travel_record', ['plan_id'=>$planId,'post_id'=>$id,'revision'=>6], 409, 'link stale revision rejected under lock'); queued();
Db::$queue = [$travelReady, ['SELECT id FROM public.users',
    false], ['SELECT * FROM community.travel_plans',
    $plan],
    ['SELECT id FROM public.users',
    false], ['SELECT * FROM community.travel_plans',
    $plan], ['SELECT id FROM community.memory_posts',
    ['id'=>$id], $owned],
    ['SELECT count(*) FROM community.travel_plan_records',
    0], ['INSERT INTO community.travel_plan_records',
    false],
    ['UPDATE community.travel_plans SET revision=revision+1',
    array_replace($plan,['revision'=>8]), static function () { check(Db::$depth===2, 'domain link revision shares the outer transaction'); }],
    ['SELECT r.post_id,r.step_id',
    []]];
check(out('qaredi_link_travel_record', ['plan_id'=>$planId,'post_id'=>$id,'revision'=>7])['plan']['revision']===8, 'link advances revision atomically'); queued();
check(count(Audit::$events)>=3, 'domain mutations emit existing audit action');
echo "MCP_TOOLS_UNIT_PASS $n (scripted DB; real PostgreSQL integration separate)\n";
