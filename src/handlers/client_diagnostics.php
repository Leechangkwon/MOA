<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/ClientDiagnostics.php';
if (Http::method()==='GET') { Http::json(ClientDiagnostics::own($user));return; }
if (Http::method()==='POST') { Http::json(ClientDiagnostics::receive($user,Http::jsonBody(131072)),201);return; }
ClientDiagnostics::forget($user,Http::jsonBody(256));
Http::json(['ok'=>true]);
