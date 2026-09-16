#Requires -Version 5.1
<#
.SYNOPSIS
    t-box v2 암호화 백업을 이 외장하드만으로 푼다.

.DESCRIPTION
    서버도 없고 인터넷도 없는 상태를 전제한다.
    이 스크립트·gpg·복호화 키가 모두 이 드라이브에 있으므로, 외장하드를 꽂을 수 있는
    Windows PC 한 대만 있으면 백업을 평문으로 되돌릴 수 있다.

    백업 파일 자체는 이 드라이브에 없다(일부러 그렇게 했다 — RECOVER.md 「왜 백업은
    여기 없나」). 기본값은 이 PC 의 C:\tbox-backup 을 본다.

.EXAMPLE
    .\recover.ps1
    무엇을 풀 수 있는지 목록만 보여 준다.

.EXAMPLE
    .\recover.ps1 -Latest -Out D:\복구
    가장 최근 db 와 files 를 D:\복구 에 푼다.

.EXAMPLE
    .\recover.ps1 -File C:\tbox-backup\var\backups\tbox-v2\db\db-20260916-0300.dump.gpg -Out D:\복구
    특정 파일 하나만 푼다.

.NOTES
    F-55 / T-67. 만든 날 2026-09-16.
#>

[CmdletBinding()]
param(
    # 암호화 백업이 있는 곳. 기본은 매일 pull 이 쌓는 자리.
    [string] $Backup = 'C:\tbox-backup',

    # 특정 .gpg 파일 하나만 푼다.
    [string] $File,

    # db 와 files 의 가장 최근 것을 푼다.
    [switch] $Latest,

    # 평문을 쓸 폴더. 지정하지 않으면 목록만 보여 주고 끝난다.
    [string] $Out,

    # 복호화 키. 기본은 이 드라이브의 tbox-key\backup.key.
    [string] $Key
)

$ErrorActionPreference = 'Stop'

# ----------------------------------------------------------------------------
# 이 스크립트가 놓인 자리를 기준으로 도구와 키를 찾는다(드라이브 문자가 바뀌어도 된다)
# ----------------------------------------------------------------------------

$Here  = Split-Path -Parent $MyInvocation.MyCommand.Path      # ...\tbox-recovery
$Root  = Split-Path -Parent $Here                             # 드라이브 루트
$Gpg   = Join-Path $Here 'gpg\bin\gpg.exe'
if (-not $Key) { $Key = Join-Path $Root 'tbox-key\backup.key' }

function Say { param([string] $m) Write-Host $m }

function Fail { param([string] $m) Write-Host "FAIL | $m"; exit 1 }

if (-not (Test-Path -LiteralPath $Gpg)) {
    Fail "gpg 를 찾지 못했다: $Gpg — 이 드라이브의 tbox-recovery\gpg\bin 이 지워졌다."
}
if (-not (Test-Path -LiteralPath $Key)) {
    Fail "복호화 키를 찾지 못했다: $Key — 키가 없으면 백업은 열 수 없다."
}

Say "gpg : $Gpg"
Say "키   : $Key"
Say "백업 : $Backup"
Say ''

# ----------------------------------------------------------------------------
# 무엇이 있는지 본다
# ----------------------------------------------------------------------------

$dbDir    = Join-Path $Backup 'var\backups\tbox-v2\db'
$filesDir = Join-Path $Backup 'var\backups\tbox-v2\files'

function Get-Artifacts {
    param([string] $Dir, [string] $Pattern)
    if (-not (Test-Path -LiteralPath $Dir)) { return @() }
    @(Get-ChildItem -LiteralPath $Dir -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Sort-Object Name -Descending)
}

$dbs   = Get-Artifacts -Dir $dbDir    -Pattern 'db-*.dump.gpg'
$fileses = Get-Artifacts -Dir $filesDir -Pattern 'files-*.tar.gz.gpg'

Say '=== 풀 수 있는 것 ==='
if ($dbs.Count -eq 0 -and $fileses.Count -eq 0) {
    Say "  없음 — $Backup 아래에서 백업을 찾지 못했다."
    Say '  -Backup 으로 다른 위치를 지정하라(예: 다른 PC 에서 복사해 온 폴더).'
} else {
    foreach ($d in $dbs)     { Say ('  db    {0}  ({1:N0} 바이트)' -f $d.Name, $d.Length) }
    foreach ($f in $fileses) { Say ('  files {0}  ({1:N0} 바이트)' -f $f.Name, $f.Length) }
}
Say ''

# ----------------------------------------------------------------------------
# 풀 대상을 정한다
# ----------------------------------------------------------------------------

$targets = @()
if ($File) {
    if (-not (Test-Path -LiteralPath $File)) { Fail "지정한 파일이 없다: $File" }
    $targets = @(Get-Item -LiteralPath $File)
} elseif ($Latest) {
    if ($dbs.Count -gt 0)     { $targets += $dbs[0] }
    if ($fileses.Count -gt 0) { $targets += $fileses[0] }
    if ($targets.Count -eq 0) { Fail '풀 것이 없다.' }
}

if ($targets.Count -eq 0) {
    Say '풀려면 -Latest (최근 db·files) 또는 -File <경로> 와 -Out <폴더> 를 준다.'
    Say '예: .\recover.ps1 -Latest -Out D:\복구'
    exit 0
}
if (-not $Out) {
    Fail '-Out <폴더> 가 필요하다. 평문을 어디에 쓸지 정해야 한다.'
}

New-Item -ItemType Directory -Force -Path $Out | Out-Null

# 임시 gpg 홈. 외장하드가 쓰기 금지일 수도 있으니 사용자 임시 폴더에 만든다.
$gpgHome = Join-Path $env:TEMP ('tbox-recover-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Force -Path $gpgHome | Out-Null

$failed = 0

try {
    foreach ($t in $targets) {

        # 1) sha256 이 옆에 있으면 먼저 대조한다(썩은 사본을 풀어 봐야 소용없다)
        $shaFile = $t.FullName + '.sha256'
        if (Test-Path -LiteralPath $shaFile) {
            $expected = ((Get-Content -LiteralPath $shaFile -First 1) -split '\s+')[0]
            $actual   = (Get-FileHash -LiteralPath $t.FullName -Algorithm SHA256).Hash
            if ($expected -ieq $actual) {
                Say ('sha256 일치 : {0}' -f $t.Name)
            } else {
                Say ('sha256 불일치: {0} — 사본이 손상됐다. 그래도 풀어는 본다.' -f $t.Name)
            }
        } else {
            Say ('sha256 없음  : {0} — 무결성은 확인하지 못한다.' -f $t.Name)
        }

        # 2) 푼다. 확장자 .gpg 만 떼어낸 이름으로 쓴다.
        $outName = $t.Name -replace '\.gpg$', ''
        $outPath = Join-Path $Out $outName
        $errPath = Join-Path $gpgHome 'gpg.err'

        $argLine = '--homedir "{0}" --batch --quiet --yes --pinentry-mode loopback --passphrase-file "{1}" --decrypt "{2}"' -f $gpgHome, $Key, $t.FullName

        # PowerShell 파이프는 바이너리를 깨뜨린다. 파일 핸들로 직접 받는다.
        $p = Start-Process -FilePath $Gpg -ArgumentList $argLine -NoNewWindow -PassThru `
            -RedirectStandardOutput $outPath -RedirectStandardError $errPath
        $null = $p.Handle      # 캐시하지 않으면 ExitCode 가 빈 값이 된다
        $p.WaitForExit()

        if ($p.ExitCode -ne 0) {
            $failed++
            Say ('  풀기 실패 (gpg {0}): {1}' -f $p.ExitCode, $t.Name)
            foreach ($l in @(Get-Content -LiteralPath $errPath -ErrorAction SilentlyContinue)) { Say ('    gpg: ' + $l) }
            Remove-Item -LiteralPath $outPath -Force -ErrorAction SilentlyContinue
            continue
        }

        # 3) 제대로 풀렸는지 선두 바이트로 확인한다
        $fs = [IO.File]::OpenRead($outPath)
        $head = New-Object byte[] 5
        $null = $fs.Read($head, 0, 5)
        $fs.Close()

        $magic = -join ($head | ForEach-Object { [char]$_ })
        $ok =
            if ($outName -like '*.dump')   { $magic -eq 'PGDMP' }
            elseif ($outName -like '*.gz') { $head[0] -eq 0x1F -and $head[1] -eq 0x8B }
            else                           { $true }

        $size = (Get-Item -LiteralPath $outPath).Length
        if ($ok) {
            Say ('  풀었다: {0}  ({1:N0} 바이트)' -f $outPath, $size)
        } else {
            $failed++
            Say ('  풀리긴 했으나 내용이 예상과 다르다: {0} (선두 {1})' -f $outPath, $magic)
        }
    }
} finally {
    Remove-Item -LiteralPath $gpgHome -Recurse -Force -ErrorAction SilentlyContinue
}

Say ''
if ($failed -eq 0) {
    Say '완료. 다음은 서버에서:'
    Say '  db    : pg_restore -d tbox_re <푼 .dump 파일>'
    Say '  files : tar -xzf <푼 .tar.gz> -C /var/www/tbox-v2/'
    Say ''
    Say '푼 평문에는 실제 업무 데이터가 들어 있다. 복구가 끝나면 지워라.'
    exit 0
} else {
    Say ("실패 {0}건. 위 gpg 메시지를 보라." -f $failed)
    exit 1
}
