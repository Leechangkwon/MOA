<?php
declare(strict_types=1);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'PATCH') {
    Http::json(WorkDocuments::update($user, (string) $params[0], Http::jsonBody(262144, 12)));
} else {
    Http::json(WorkDocuments::read($user, (string) $params[0]));
}
