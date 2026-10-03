<?php
declare(strict_types=1);
namespace QarediAssistant;

final class Provider
{
    public static function enabled(): bool
    {
        return \Env::getOrEmpty('ASSISTANT_ENABLED') === '1';
    }

    public static function testAvailable(): bool
    {
        return \Env::getOrEmpty('ASSISTANT_TEST_ADAPTER') === '1'
            && str_starts_with(\Env::getOrEmpty('DB_NAME'), 'tbox_assistant_test_');
    }

    public static function ensureEnabled(): void
    {
        if (!self::enabled()) throw new Error(503, 'service_disabled', '비서 실행 서비스가 활성화되지 않았습니다.');
    }

    public static function unavailable(): never
    {
        throw new Error(503, 'provider_unavailable', 'AI 공급자가 연결되지 않았습니다. 파일 검색·근거 조회를 명시적으로 실행하세요.');
    }

    public static function kind(array $request): string
    {
        if ($request['mode'] === 'tool') return 'native';
        if (!self::testAvailable()) self::unavailable();
        return 'test';
    }

    public static function plan(array $request): array
    {
        if ($request['mode'] === 'tool') $tool = $request['tool'];
        else {
            if (!self::testAvailable()) self::unavailable();
            // 테스트 DB/설정 이중 관문. 운영 경로에는 공급자나 외부 호출이 없다.
            require_once dirname(__DIR__, 2) . '/tests/assistant/TestAdapter.php';
            $tool = \QarediAssistantTestAdapter::tool($request);
        }
        if (!in_array($tool['name'], $request['allowed_tools'], true)) throw new Error(403, 'tool_not_permitted', '허용되지 않은 도구입니다.');
        $steps = [['step_id' => \tbox_uuid_v4(), 'tool' => $tool['name'], 'arguments' => $tool['arguments']]];
        foreach ($request['attachments'] as $item) $steps[] = ['step_id' => \tbox_uuid_v4(), 'tool' => 'files.evidence', 'arguments' => $item];
        return ['steps' => $steps];
    }

    public static function capabilities(): array
    {
        return ['provider' => self::testAvailable() ? 'test' : 'unconfigured',
            'inference_available' => false, 'tools' => Request::TOOLS, 'worker_required' => true];
    }
}
