<?php
declare(strict_types=1);
// Extend only a brand-new synthetic fixture with private password/TOTP material.
if (PHP_SAPI !== 'cli') exit(2);
require_once dirname(__DIR__, 2) . '/src/Env.php';
$config = getenv('FILE_RENAME_FIXTURE_CONFIG');
if (!is_string($config) || $config === '') exit(3);
Env::load($config);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
if (!str_starts_with(Env::get('DB_NAME'), 'tbox_stage89_test_')) exit(3);
$path = trim(stream_get_contents(STDIN));
$data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$db = Db::instance();
foreach ($data['users'] as &$entry) {
    $password = bin2hex(random_bytes(18)) . 'Aa!7';
    $row = $db->run(
        'SELECT username,team_id,mfa_secret FROM users WHERE id=:id',
        [':id'=>$entry['id']]
    )->fetch();
    if (!is_array($row) || !str_starts_with($row['username'], 'claude_')) exit(3);
    $db->run('UPDATE users SET password_hash=:hash, must_change_password=false, failed_count=0 WHERE id=:id',
        [':hash'=>password_hash($password, PASSWORD_ARGON2ID), ':id'=>$entry['id']]);
    $entry['password']=$password; $entry['username']=$row['username'];
    $entry['mfa_secret']=$row['mfa_secret']; $entry['team_id']=$row['team_id'];
}
unset($entry);
file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "PRIVATE_SYNTHETIC_LOGIN_FIXTURE_READY=1\n";
