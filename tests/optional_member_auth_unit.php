<?php
declare(strict_types=1);
// Run: php tests/optional_member_auth_unit.php (no DB or credentials).
// Deliberately small permission matrix. Refuse unexpected SQL so this unit test
// never silently substitutes an answer for an unrelated database dependency.
final class Db
{
    public static function instance(): self { return new self(); }
    public function run(string $sql): self
    {
        if (trim($sql) !== 'SELECT role, access_level, can_read, can_upload FROM permissions') {
            throw new RuntimeException('Unexpected unit-test database query');
        }
        return $this;
    }
    public function fetchAll(): array
    {
        return [
            ['role'=>'Employee', 'access_level'=>'public', 'can_read'=>true, 'can_upload'=>true],
            ['role'=>'Guest', 'access_level'=>'public', 'can_read'=>true, 'can_upload'=>false],
        ];
    }
}
require dirname(__DIR__) . '/src/Env.php';
require dirname(__DIR__) . '/src/Auth.php';
require dirname(__DIR__) . '/src/Authz.php';
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException($name);
    $passed++;
    echo "PASS {$name}\n";
};
$invited = ['role'=>'Employee', 'invited_account'=>true, 'mfa_setup_required'=>true, 'mfa_enabled'=>false];
$check('old invitation setup hint does not force OTP', Auth::nextStep($invited) === null);
$check('new invitation does not force OTP', Auth::nextStep(array_replace($invited, ['mfa_setup_required'=>false])) === null);
$check('admin role still requires OTP', Auth::nextStep(array_replace($invited, ['role'=>'Admin'])) === 'mfa_setup');
$check('acting admin remains protected', Auth::nextStep($invited + ['real_role'=>'Admin']) === 'mfa_setup');
$check('explicit administrator recovery is required', Auth::nextStep($invited + ['mfa_recovery_required'=>true]) === 'mfa_setup');
$check('password recovery stays first', Auth::nextStep($invited + ['must_change_password'=>true,'mfa_recovery_required'=>true]) === 'password_change');
$check('enabled OTP satisfies recovery gate', Auth::nextStep(array_replace($invited, ['mfa_recovery_required'=>true, 'mfa_enabled'=>true])) === null);
$check('password requirement independent of invitation', Auth::nextStep($invited + ['must_change_password'=>true]) === 'password_change');
$check('travel write includes travel read', Authz::normalizeScope('travel.write') === 'travel.read+travel.write');
$check('travel read does not allow writes', !Authz::tokenScopeAllows('travel.read', 'travel.write'));
$check('travel write allows own-domain reads', Authz::tokenScopeAllows('travel.write', 'travel.read'));
$check('document token gains no travel read', !Authz::tokenScopeAllows('community.read+community.write', 'travel.read'));
$check('file token gains no travel read', !Authz::tokenScopeAllows('read+upload', 'travel.read'));
$check('travel token gains no documents', !Authz::tokenScopeAllows('travel.write', 'community.read'));
$check('member can choose travel permissions', in_array('travel.write', Authz::tokenScopesFor($invited), true));
$check('guest cannot choose travel permissions', !in_array('travel.read', Authz::tokenScopesFor(['role'=>'Guest']), true));
$check('unknown scope remains closed', !Authz::tokenScopeAllows('travel.read+unknown', 'travel.read'));
try { Authz::normalizeScope(['assistant.run','travel.read']); $mixed = true; }
catch (InvalidArgumentException $error) { $mixed = false; }
$check('assistant runtime token remains independent', !$mixed);
echo "OPTIONAL_MEMBER_AUTH_UNIT {$passed} PASS\n";
