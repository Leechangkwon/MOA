<?php
declare(strict_types=1);

/** 외부 추론0. 테스트 DB 이중 관문을 통과한 테스트 요청에만 쓰는 결정적 어댑터. */
final class QarediAssistantTestAdapter
{
    public static function tool(array $request): array
    {
        if (!\QarediAssistant\Provider::testAvailable()) \QarediAssistant\Provider::unavailable();
        if ($request['message']==='test-error') throw new \QarediAssistant\Error(503,'test_adapter_error','테스트 어댑터 실패입니다.',true);
        return ['name'=>'files.search','arguments'=>['query'=>iconv_substr(trim($request['message']),0,200,'UTF-8'),'limit'=>20]];
    }
}
