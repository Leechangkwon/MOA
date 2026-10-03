<?php
declare(strict_types=1);
$gate=community_form_gate($user,(string)$params[0],(string)$params[1]);
$body=Http::jsonBody();
if (array_keys($body)!==['values'] || !is_array($body['values'])) { Http::error(400,Http::MSG_BAD_REQUEST); }
$db=Db::instance(); $db->begin();
try {
    $lock=<<<'SQL'
        SELECT id FROM community.databases WHERE id=CAST(:id AS bigint) FOR UPDATE
        SQL;
    $db->run($lock,[':id'=>(int)$gate['database']['id']]);
    $gate=community_form_gate($user,(string)$params[0],(string)$params[1]);
    $clean=community_form_values($gate['view'],$gate['schema'],$body['values']);
    $result=community_form_create_response($gate['database'],$gate['page'],$gate['view'],$clean,$user,'matrix');
    $db->commit();
    Http::json($result,201);
} catch (InvalidArgumentException $e) {
    $db->rollback(); Http::json(['error'=>'답변의 형식을 확인해 주세요','reason'=>$e->getMessage()],400);
} catch (OverflowException $e) {
    $db->rollback(); Http::json(['error'=>'응답 한도를 넘었습니다. 잠시 뒤 다시 시도하세요','reason'=>$e->getMessage()],429);
} catch (DomainException $e) {
    $db->rollback(); Http::json(['error'=>'데이터베이스의 행 한도를 넘었습니다','reason'=>$e->getMessage()],400);
} catch (Throwable $e) { $db->rollback(); throw $e; }
