<?php
declare(strict_types=1);

// C:F-42: no cache, notifications, reads, recent-page records or other writes here.
Http::json(Briefing::collect($user, new DateTimeImmutable('now')));
