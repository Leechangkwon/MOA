<?php
declare(strict_types=1);

/**
 * tests/passkey_unit.php — T:F-72 PK-T1 해석기 단위 시험 (DB·네트워크 0)
 *
 * 쓰는 법: php tests/passkey_unit.php <표본 JSON 파일…>
 *   첫 파일은 `python3 tests/lib/passkey_forge.py --samples localhost <origin>` 의 출력 — **파이썬 cryptography 가 만든** 표본과 거부 목록
 *   (구현과 다른 코드 — 교차 확인). 시험 틀(tests/passkey.sh)은 **가상 인증기(크로미움 CDP)에서 뽑은** 등록·로그인 표본 파일을 더 넘긴다.
 *   (이 파일은 명령을 부르지 않는다 — 표본은 부르는 쪽이 만든다. 보안 린트 S-09 「코드 실행 함수」 0.)
 * 보는 것(지시서 4장 PK-T1)
 *   · 설정 검사 configFrom — https 이름 · http://localhost:<포트> 만 · IP·경로·쿼리·사용자정보·다른 포트·대문자 → 꺼짐
 *   · 표본 등록 3(+가상 인증기) — verifyAttestation 통과 · 공개 키 SPKI 91바이트 = 표본의 공개 키 · BE/BS · 열쇠 id
 *   · 표본 로그인 3(+가상 인증기) — parseAuthData · rpIdHash · 서명 === 1 · 서명 한 바이트 뒤집기 거짓 · 다른 열쇠 거짓
 *   · CBOR 거부 목록(무한 길이 · 태그 · 실수 · 8바이트 길이 · 깊이 5 · 중복 키 · 남는 바이트 · UTF-8 깨짐 · 길이 넘침 · 4KB 넘음) 전부 PasskeyError 하나
 *   · COSE 거부(곡선 밖 점 · x 31바이트 · alg -257 · kty 3 …) · attestation 거부(fmt packed · 남는 바이트 …)
 *   · clientDataJSON 거부(type · challenge 길이 · crossOrigin true · topOrigin · 2KB 넘음 · 깊이)
 *   · 무작위 바이트 1만 건에 예외 밖 결과(PasskeyError 가 아닌 예외·경고) 0 · PHP 경고·알림 0(오류 처리기가 모두 실패로 센다)
 * 나가는 값: 마지막 줄 `통과 n · 실패 m` · 종료 코드 0/1
 */

$root = dirname(__DIR__);
require $root . '/src/Env.php';
require $root . '/src/Passkey.php';

$pass = 0;
$fail = 0;
$warnings = 0;
set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
    $warnings++;
    fwrite(STDOUT, "  ✖ PHP 경고·알림: {$str}\n");
    return true;
});
$ok = static function (string $name, bool $cond, string $detail = '') use (&$pass, &$fail): void {
    if ($cond) {
        $pass++;
        echo "  ✔ {$name}\n";
    } else {
        $fail++;
        echo "  ✖ {$name}" . ($detail !== '' ? "  — {$detail}" : '') . "\n";
    }
};
$throws = static function (callable $fn): string {
    try {
        $fn();
        return 'none';
    } catch (PasskeyError $e) {
        return 'PasskeyError:' . $e->getMessage();
    } catch (Throwable $e) {
        return 'other:' . get_class($e) . ':' . $e->getMessage();
    }
};
$b64u = static fn(string $s): string => (string) WebPush::b64uDecode($s);

// ─── 설정 검사 ───────────────────────────────────────────────────
echo "── 설정 검사 (Passkey::configFrom — APP_URL 한 값)\n";
$cfgCases = [
    ['https://changkwon.mso.so', ['changkwon.mso.so', 'https://changkwon.mso.so']],
    ['https://changkwon.mso.so/', ['changkwon.mso.so', 'https://changkwon.mso.so']],
    ['https://changkwon.mso.so:443', ['changkwon.mso.so', 'https://changkwon.mso.so']],
    ['http://localhost:8301', ['localhost', 'http://localhost:8301']],
    ['https://changkwon.mso.so:8443', null],
    ['https://changkwon.mso.so/tbox', null],
    ['https://changkwon.mso.so/?a=1', null],
    ['https://user:pw@changkwon.mso.so', null],
    ['https://127.0.0.1', null],
    ['http://127.0.0.1:8301', null],
    ['https://[::1]', null],
    ['https://10.0.0.5', null],
    ['https://Changkwon.mso.so', null],
    ['http://changkwon.mso.so', null],
    ['http://localhost', null],
    ['https://localhost', null],
    ['https://intranet', null],
    ['https://1.2.3.4.5', null],
    ['ftp://changkwon.mso.so', null],
    ['', null],
    ['https://changkwon.mso.so#x', null],
    ["https://changkwon.mso.so\n", null],
];
foreach ($cfgCases as [$url, $want]) {
    $got = Passkey::configFrom($url);
    $gotPair = $got === null ? null : [$got['rp_id'], $got['origin']];
    $ok('configFrom(' . json_encode($url) . ') → ' . json_encode($want), $gotPair === $want, json_encode($gotPair));
}

// 시험 설정 — 표본의 RP·origin 으로 Env 를 채운다(임시 .env · 이 프로세스 안에서만)
$samplesFiles = array_slice($argv, 1);
if ($samplesFiles === []) {
    echo "쓰는 법: php tests/passkey_unit.php <표본 JSON 파일…> — 첫 파일은 python3 tests/lib/passkey_forge.py --samples localhost <origin> 의 출력\n";
    exit(2);
}
$sampleSets = [];
$forged = null;
foreach ($samplesFiles as $f) {
    $d = json_decode((string) file_get_contents($f), true);
    if (!is_array($d)) {
        echo "  ✖ 표본 파일을 읽지 못했다: {$f}\n";
        exit(1);
    }
    $sampleSets[] = [basename($f), $d];
    if ($forged === null && isset($d['bad_cbor'])) {
        $forged = $d;   // 거부 목록은 파이썬이 만든 파일에서
    }
}
if ($forged === null) {
    echo "  ✖ 거부 목록(bad_cbor …)이 든 표본 파일이 없다 — passkey_forge.py --samples 출력을 함께 넘긴다\n";
    exit(1);
}
$rpId   = (string) $sampleSets[0][1]['rp_id'];
$origin = (string) $sampleSets[0][1]['origin'];
$envFile = tempnam(sys_get_temp_dir(), 'pkunit');
file_put_contents($envFile, "APP_URL={$origin}\nAPP_SECRET=unit-test-only-" . bin2hex(random_bytes(8)) . "\n");
Env::load($envFile);
unlink($envFile);
$ok('시험 설정 — config() = 표본의 RP ID·origin', Passkey::config() === ['rp_id' => $rpId, 'origin' => $origin], json_encode(Passkey::config()));

// ─── 표본 등록 · 로그인 ───────────────────────────────────────────
foreach ($sampleSets as [$label, $S]) {
    echo "── 표본 [{$label}] 등록 " . count($S['register'] ?? []) . " · 로그인 " . count($S['login'] ?? []) . "\n";
    foreach (($S['register'] ?? []) as $i => $r) {
        $att = null;
        $err = $throws(static function () use (&$att, $r, $b64u): void {
            $att = Passkey::verifyAttestation($b64u($r['attestationObject']));
        });
        $ok("등록 {$i} — verifyAttestation 통과", $err === 'none', $err);
        if ($att === null) {
            continue;
        }
        $ok("등록 {$i} — 공개 키 SPKI 91바이트", strlen($att['public_key']) === 91);
        if (isset($r['spki_der_hex'])) {
            $ok("등록 {$i} — 공개 키 = 표본의 공개 키(다른 코드가 만든 것과 같다)", bin2hex($att['public_key']) === $r['spki_der_hex']);
        }
        if (isset($r['credential_id'])) {
            $ok("등록 {$i} — 열쇠 id = rawId", $att['credential_id'] === $b64u($r['credential_id']));
        }
        if (isset($r['be'])) {
            $ok("등록 {$i} — BE·BS", $att['backup_eligible'] === $r['be'] && $att['backed_up'] === $r['bs']);
        }
        $cd = null;
        $err = $throws(static function () use (&$cd, $r, $b64u): void {
            $cd = Passkey::parseClientData($b64u($r['clientDataJSON']), 'webauthn.create');
        });
        $ok("등록 {$i} — clientDataJSON(create) · origin 일치", $err === 'none' && Passkey::originOk((string) ($cd['origin'] ?? '')), $err);
        if (isset($r['challenge']) && is_array($cd)) {
            $ok("등록 {$i} — 챌린지 32바이트 = 표본", $cd['challenge'] === $b64u($r['challenge']));
        }
    }
    foreach (($S['login'] ?? []) as $i => $l) {
        $der = (string) hex2bin($l['spki_der_hex']);
        $ad  = $b64u($l['authenticatorData']);
        $cdj = $b64u($l['clientDataJSON']);
        $sig = $b64u($l['signature']);
        $a = null;
        $err = $throws(static function () use (&$a, $ad): void {
            $a = Passkey::parseAuthData($ad);
        });
        $ok("로그인 {$i} — authData 37바이트 해석 · UP·UV · AT 없음", $err === 'none' && $a['up'] && $a['uv'] && !$a['at'] && strlen($ad) === 37, $err);
        $ok("로그인 {$i} — rpIdHash = sha256(RP ID)", is_array($a) && Passkey::rpIdHashOk($a['rp_id_hash']));
        $ok("로그인 {$i} — 서명 === 1", Passkey::verifySignature($der, $ad, $cdj, $sig));
        $flip = substr($sig, 0, -1) . chr(ord($sig[strlen($sig) - 1]) ^ 1);
        $ok("로그인 {$i} — 서명 한 바이트 뒤집으면 거짓", !Passkey::verifySignature($der, $ad, $cdj, $flip));
        $ok("로그인 {$i} — clientDataJSON 한 글자 바꾸면 거짓", !Passkey::verifySignature($der, $ad, $cdj . ' ', $sig));
        $ok("로그인 {$i} — authData 한 비트 바꾸면 거짓", !Passkey::verifySignature($der, $ad ^ str_pad("\x01", 37, "\x00", STR_PAD_LEFT), $cdj, $sig));
        $ok("로그인 {$i} — 빈 서명·73바이트 서명 거짓", !Passkey::verifySignature($der, $ad, $cdj, '') && !Passkey::verifySignature($der, $ad, $cdj, str_repeat("\x30", 73)));
    }
    // 서로 다른 열쇠로 확인 — 거짓. 가상 인증기 표본은 로그인이 전부 **같은 열쇠**일 수 있어(한 휴대폰을 여러 탭으로 흉내) 순번이 아니라
    // 공개 키가 **다른** 것을 고른다 — 다른 로그인 표본의 키 → 없으면 등록 표본에서 꺼낸 키(verifyAttestation). 그래도 없으면 그 표본 묶음은 건너뜀을 적는다.
    if (count($S['login'] ?? []) >= 1) {
        $l0 = $S['login'][0];
        $mine = (string) $l0['spki_der_hex'];
        $other = null;
        foreach ($S['login'] as $l) {
            if ((string) $l['spki_der_hex'] !== $mine) { $other = (string) hex2bin((string) $l['spki_der_hex']); break; }
        }
        if ($other === null) {
            foreach (($S['register'] ?? []) as $r) {
                try {
                    $pkr = Passkey::verifyAttestation($b64u($r['attestationObject']))['public_key'];
                } catch (Throwable $e) {
                    continue;
                }
                if (bin2hex($pkr) !== $mine) { $other = $pkr; break; }
            }
        }
        if ($other !== null) {
            $ok('다른 사람의 공개 키로는 서명이 거짓', !Passkey::verifySignature($other, $b64u($l0['authenticatorData']), $b64u($l0['clientDataJSON']), $b64u($l0['signature'])));
        } else {
            echo "  · 다른 공개 키가 표본에 없다 — 이 묶음은 「다른 사람의 공개 키」 확인을 건너뜀\n";
        }
    }
}

// ─── CBOR 거부 목록 ─────────────────────────────────────────────
echo "── CBOR 거부 목록 (전부 PasskeyError passkey_invalid 하나)\n";
foreach (($forged['bad_cbor'] ?? []) as $b) {
    $bin = (string) hex2bin($b['hex']);
    $r = $throws(static function () use ($bin): void {
        [$v, $used] = Passkey::cborDecode($bin);
        if ($used !== strlen($bin)) {
            throw new PasskeyError('passkey_invalid');   // 부르는 쪽의 「남는 바이트 0」
        }
    });
    $ok('CBOR ' . $b['name'], $r === 'PasskeyError:passkey_invalid', $r);
}
$r = $throws(static function (): void {
    [$v, $used] = Passkey::cborDecode("\x01\x02");
    if ($used !== 2) {
        throw new PasskeyError('passkey_invalid');
    }
});
$ok('CBOR 남는 바이트(소비 1 · 입력 2 — 부르는 쪽이 거부)', $r === 'PasskeyError:passkey_invalid', $r);
[$v4] = Passkey::cborDecode("\x81\x81\x81\x81\x01");
$ok('CBOR 깊이 4 는 통과(경계)', is_array($v4));
[$m] = Passkey::cborDecode("\xa2\x01\x02\x61\x31\x03");
$ok('CBOR 정수 1 과 글자 "1" 은 다른 키(중복 아님)', $m instanceof PasskeyMap && $m->get('i:1') === 2 && $m->get('s:1') === 3);

echo "── COSE 거부\n";
foreach (($forged['bad_cose'] ?? []) as $b) {
    $r = $throws(static function () use ($b): void {
        [$c] = Passkey::cborDecode((string) hex2bin($b['hex']));
        Passkey::coseToSpki($c);
    });
    $ok('COSE ' . $b['name'], $r === 'PasskeyError:passkey_invalid', $r);
}
echo "── attestation 거부\n";
foreach (($forged['bad_attestation'] ?? []) as $b) {
    $r = $throws(static function () use ($b): void {
        Passkey::verifyAttestation((string) hex2bin($b['hex']));
    });
    $ok('attestation ' . $b['name'], $r === 'PasskeyError:passkey_invalid', $r);
}

echo "── clientDataJSON 거부\n";
$ch = WebPush::b64uEncode(random_bytes(32));
$cdCases = [
    ['type 다름', json_encode(['type' => 'webauthn.create', 'challenge' => $ch, 'origin' => $origin])],
    ['challenge 31바이트', json_encode(['type' => 'webauthn.get', 'challenge' => WebPush::b64uEncode(random_bytes(31)), 'origin' => $origin])],
    ['challenge 없음', json_encode(['type' => 'webauthn.get', 'origin' => $origin])],
    ['challenge 가 base64url 아님', json_encode(['type' => 'webauthn.get', 'challenge' => str_repeat('*', 43), 'origin' => $origin])],
    ['origin 없음', json_encode(['type' => 'webauthn.get', 'challenge' => $ch])],
    ['crossOrigin true', json_encode(['type' => 'webauthn.get', 'challenge' => $ch, 'origin' => $origin, 'crossOrigin' => true])],
    ['crossOrigin 글자', json_encode(['type' => 'webauthn.get', 'challenge' => $ch, 'origin' => $origin, 'crossOrigin' => 'false'])],
    ['topOrigin 있음', json_encode(['type' => 'webauthn.get', 'challenge' => $ch, 'origin' => $origin, 'topOrigin' => 'https://evil.example'])],
    ['배열', json_encode([1, 2])],
    ['깊이 넘침', json_encode(['type' => 'webauthn.get', 'challenge' => $ch, 'origin' => $origin, 'x' => ['y' => ['z' => 1]]])],
    ['2KB 넘음', json_encode(['type' => 'webauthn.get', 'challenge' => $ch, 'origin' => $origin, 'pad' => str_repeat('a', 2100)])],
    ['UTF-8 깨짐', "{\"type\":\"webauthn.get\",\"x\":\"\xc3\x28\"}"],
];
foreach ($cdCases as [$name, $json]) {
    $r = $throws(static function () use ($json): void {
        Passkey::parseClientData((string) $json, 'webauthn.get');
    });
    $ok('clientDataJSON ' . $name, $r === 'PasskeyError:passkey_invalid', $r);
}
$okCd = Passkey::parseClientData((string) json_encode(['type' => 'webauthn.get', 'challenge' => $ch, 'origin' => 'http://localhost:9999', 'crossOrigin' => false]), 'webauthn.get');
$ok('다른 origin 은 해석은 되고 originOk 가 거짓', Passkey::originOk($okCd['origin']) === false);
$ok('rpIdHash 가 다른 이름이면 거짓', Passkey::rpIdHashOk(hash('sha256', 'evil.example', true)) === false);

echo "── authData 거부\n";
$rp = hash('sha256', $rpId, true);
$adCases = [
    ['36바이트', substr($rp . "\x05\x00\x00\x00\x00", 0, 36)],
    ['남는 바이트', $rp . "\x05\x00\x00\x00\x00\x00"],
    ['BS 인데 BE 없음', $rp . "\x15\x00\x00\x00\x00"],
    ['AT 인데 열쇠 없음', $rp . "\x45\x00\x00\x00\x00"],
    ['ED 인데 확장 없음', $rp . "\x85\x00\x00\x00\x00"],
    ['ED 확장이 맵이 아님', $rp . "\x85\x00\x00\x00\x00\x01"],
];
foreach ($adCases as [$name, $bin]) {
    $r = $throws(static fn() => Passkey::parseAuthData($bin));
    $ok('authData ' . $name, $r === 'PasskeyError:passkey_invalid', $r);
}
$a = Passkey::parseAuthData($rp . "\x85\x00\x00\x00\x09\xa1\x61\x61\x01");
$ok('authData ED 확장은 읽고 버림 · 서명 횟수 9', $a['ed'] && $a['sign_count'] === 9);

echo "── 무작위 바이트 1만 건 (예외 밖 결과·경고 0)\n";
$other = 0;
$accepted = 0;
mt_srand(20260927);
for ($i = 0; $i < 10000; $i++) {
    $len = random_int(0, 80);
    $bin = $len === 0 ? '' : random_bytes($len);
    if ($i % 3 === 0 && $len > 0) {
        $bin[0] = chr([0xa1, 0xa3, 0x58, 0x81, 0x9f, 0xbf, 0x5a][random_int(0, 6)]);   // 맵·배열·긴 바이트 머리로 시작하는 것도 섞는다
    }
    foreach ([static fn() => Passkey::cborDecode($bin), static fn() => Passkey::parseAuthData($bin),
              static fn() => Passkey::verifyAttestation($bin), static fn() => Passkey::coseToSpki(Passkey::cborDecode($bin)[0])] as $fn) {
        try {
            $fn();
            $accepted++;
        } catch (PasskeyError $e) {
            // 기대한 거부
        } catch (Throwable $e) {
            $other++;
            if ($other <= 3) {
                echo '    예외 밖: ' . get_class($e) . ' ' . $e->getMessage() . "\n";
            }
        }
    }
}
$ok("무작위 4만 호출 — PasskeyError 밖 예외 0 (통과 {$accepted}건은 우연히 맞는 CBOR 값)", $other === 0, (string) $other);
$ok('PHP 경고·알림 0', $warnings === 0, (string) $warnings);

printf("통과 %d · 실패 %d\n", $pass, $fail);
exit($fail === 0 && $warnings === 0 ? 0 : 1);
