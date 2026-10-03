<?php
declare(strict_types=1);
$gate=community_form_gate($user,(string)$params[0],(string)$params[1]);
Http::json(['form'=>community_form_public_definition($gate)]);
