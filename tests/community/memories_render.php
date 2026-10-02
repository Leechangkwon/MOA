<?php
declare(strict_types=1);
// Render the real PHP template, including the real URL helper, without production bootstrap or DB.
const TBOX_BASE = '/tbox';
const COMMUNITY_BASE = '/community';
const TBOX_ROUTE = 'GET /community/';
require __DIR__ . '/../../src/Http.php';
$params = [];
$_GET = [];
require __DIR__ . '/../../src/community/handlers/memories_page.php';
