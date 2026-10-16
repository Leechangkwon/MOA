<?php
declare(strict_types=1);
$args = $_GET;
if (isset($args['limit'])) {
    if (!is_string($args['limit']) || !preg_match('/^[1-9][0-9]{0,2}$/D', $args['limit'])) { Http::error(400, Http::MSG_BAD_REQUEST); }
    $args['limit'] = (int) $args['limit'];
}
Http::json(WorkDocuments::listing($user, $args, TBOX_ROUTE === 'GET ' . COMMUNITY_BASE . '/api/work/projects'));
