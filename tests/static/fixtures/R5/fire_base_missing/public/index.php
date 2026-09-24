<?php
declare(strict_types=1);

// 견본: 가짜 라우트 표 [메서드, 경로, 핸들러, 정책]
const SHOP_ROUTES = [
    ['GET',  '/',               'home.php',      'public'],
    ['GET',  '/api/ping',       'ping.php',      'public'],
    ['POST', '/api/items',      'items_new.php', 'user'],
    ['GET',  '/api/items/{id}', 'items_get.php', 'user'],
];
