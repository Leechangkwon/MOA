<?php
declare(strict_types=1);

$result = WorkDocuments::create($user, Http::jsonBody(262144, 12));
Http::json($result, $result['created'] ? 201 : 200);
