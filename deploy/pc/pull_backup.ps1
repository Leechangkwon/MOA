#Requires -Version 5.1
<#
.SYNOPSIS
    t-box v2 의 암호화 백업 산출물과 앱 로그를 서버에서 이 PC 로 끌어온다(pull).

.DESCRIPTION
    PRD 7.8 S-08 의 3-2-1 원칙(사본 3 · 매체 2 · 원격 1)에서 빠져 있던 "원격 1개"를
    사용자 PC 로 임시 대체한다(SECURITY 8.4-4 조치, 잔여 위험 R-88).

    방향은 PC -> 서버 pull 이다. 서버에는 이 PC 의 자격증명을 하나도 두지 않는다.
    서버 root 를 잡은 공격자가 원격 사본까지 지우지 못하게 하기 위함이다(SECURITY R-08 / 8.4-4).

    서버에서 실행하는 것은 읽기 전용 명령뿐이다.
      - 기본 동작 : sudo -n tar ... -cf -            (표준 출력으로 흘려보내기만 한다)
      - -Key      : sudo -n cat /etc/tbox-v2/backup.key
    rm / psql / pg_dump / gpg -d 는 절대 실행하지 않는다.

    받아오는 것 (ARCHITECTURE 11.1 의 산출물 이름 그대로)
      var/backups/tbox-v2/db      db-YYYYMMDD-HHMM.dump.gpg  + .sha256
      var/backups/tbox-v2/files   files-YYYYMMDD.tar.gz.gpg  + .sha256
      var/backups/tbox-v2/weekly  주간 보관분
      var/backups/tbox-v2/monthly 월간 보관분
      var/log/tbox-v2             app.log / audit.log / alerts.log (회전본 포함)

    받아오지 않는 것
      files-mirror/ (평문 미러) 와 백업 루트의 평문 덤프 - 평문 데이터를 PC 로 옮기지 않는다.

.EXAMPLE
    .\pull_backup.ps1
    기본값으로 내려받고 검증하고 오래된 사본을 지운다.

.EXAMPLE
    .\pull_backup.ps1 -Install
    작업 스케줄러에 매일 05:00 실행으로 등록한다.

.EXAMPLE
    .\pull_backup.ps1 -Key E:\tbox-key\backup.key
    백업 복호화 키를 USB 에 한 번만 저장한다(백업 폴더 안에는 두지 않는다).

.NOTES
    F-55 / T-67. 자세한 사용법은 같은 폴더의 README.md 를 본다.
#>

[CmdletBinding()]
param(
    # 서버 주소. 기본값이 있으므로 사용자가 스크립트를 편집할 필요가 없다.
    [string] $Server = '192.168.105.44',

    # 서버 SSH 계정.
    [string] $User = 'changkwon',

    # PC 쪽 저장 위치. 아래에 서버 경로(var\backups\..., var\log\...)가 그대로 재현된다.
    [string] $Dest = 'C:\tbox-backup',

    # 서버 tar 의 --newer-mtime. 최근 며칠분만 받는다(하루 약 140MB).
    [ValidateRange(1, 365)]
    [int] $Days = 3,

    # PC 사본 보관 일수. PRD 7.8 "어떤 백업도 45일 넘게 보관하지 않음".
    [ValidateRange(1, 3650)]
    [int] $KeepDays = 45,

    # backup.key 를 이 경로에 한 번만 저장하고 종료한다(백업 pull 은 하지 않는다).
    [string] $Key,

    # 작업 스케줄러 등록 후 종료.
    [switch] $Install,

    # 작업 스케줄러 등록 해제 후 종료.
    [switch] $Uninstall
)

$ErrorActionPreference = 'Stop'

# ----------------------------------------------------------------------------
# 상수
# ----------------------------------------------------------------------------

# 작업 스케줄러에 등록되는 이름.
$TaskName = 'tbox-v2 backup pull'

# 서버에서 tar 로 묶을 경로들(루트 / 기준 상대 경로). 평문 미러와 평문 덤프는 뺀다.
$RemotePaths = 'var/backups/tbox-v2/db var/backups/tbox-v2/files var/backups/tbox-v2/weekly var/backups/tbox-v2/monthly var/log/tbox-v2'

# 이름에 날짜가 들어가는 산출물 폴더(보관 기간 정리 대상).
$DatedDirs = @('var\backups\tbox-v2\db', 'var\backups\tbox-v2\files', 'var\backups\tbox-v2\weekly', 'var\backups\tbox-v2\monthly')

# 수정 시각 기준으로 정리할 폴더.
$LogDir = 'var\log\tbox-v2'

# pull.log 경로. Dest 를 만든 뒤에 채워진다.
$script:LogPath = $null

# ----------------------------------------------------------------------------
# 공통 함수
# ----------------------------------------------------------------------------

function Write-Log {
    <#
        화면과 pull.log 두 곳에 같은 줄을 남긴다.
        -NoFile 은 파일에 남기지 않는다(-Key 처럼 파일에 남기면 안 되는 안내에 쓴다).
    #>
    param(
        [Parameter(Mandatory = $true)][AllowEmptyString()][string] $Message,
        [switch] $NoFile
    )

    $line = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Write-Host $line

    if ((-not $NoFile) -and $script:LogPath) {
        try {
            Add-Content -LiteralPath $script:LogPath -Value $line -Encoding UTF8
        } catch {
            Write-Host ('(pull.log 기록 실패: {0})' -f $_.Exception.Message)
        }
    }
}

function Get-ToolPath {
    <#
        Windows 내장 도구의 절대 경로를 찾는다. 외부 모듈이나 설치는 쓰지 않는다.
        PATH 에서 먼저 찾고, 없으면 System32 의 표준 위치를 본다.
    #>
    param(
        [Parameter(Mandatory = $true)][string] $Name,
        [Parameter(Mandatory = $true)][string[]] $Fallback
    )

    $cmd = @(Get-Command -Name $Name -CommandType Application -ErrorAction SilentlyContinue)
    if ($cmd.Count -gt 0) { return $cmd[0].Source }

    foreach ($path in $Fallback) {
        if (Test-Path -LiteralPath $path -PathType Leaf) { return $path }
    }
    return $null
}

function Invoke-SshToFile {
    <#
        ssh 의 표준 출력을 파일로 "바이트 그대로" 받는다.

        PowerShell 의 파이프(|)와 리다이렉트(>)는 출력을 문자열로 바꾸기 때문에
        바이너리(tar, 키)가 깨진다. Start-Process -RedirectStandardOutput 은
        OS 수준의 파일 핸들을 자식 프로세스에 넘기므로 변환이 일어나지 않는다.

        반환값: ssh 종료 코드.
    #>
    param(
        [Parameter(Mandatory = $true)][string] $SshExe,
        [Parameter(Mandatory = $true)][string] $RemoteCommand,
        [Parameter(Mandatory = $true)][string] $OutFile,
        [Parameter(Mandatory = $true)][string] $ErrFile
    )

    # BatchMode=yes  : 비밀번호를 묻지 않고 즉시 실패한다(스케줄 실행이 멈추지 않게).
    # ConnectTimeout : 서버가 죽었을 때 오래 매달리지 않는다.
    # ServerAlive*   : 전송 중 연결이 끊기면 2분 안에 포기한다.
    # 원격 명령에는 작은따옴표만 들어가므로 큰따옴표로 감싸도 안전하다.
    $argLine = '-o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=8 {0}@{1} "{2}"' -f $User, $Server, $RemoteCommand

    $proc = Start-Process -FilePath $SshExe -ArgumentList $argLine -NoNewWindow -PassThru `
        -RedirectStandardOutput $OutFile -RedirectStandardError $ErrFile

    # Handle 을 한 번 읽어 프로세스 핸들을 캐시해 둔다.
    # 이것을 하지 않으면 자식이 끝난 뒤 .NET 이 핸들을 놓아 버려 ExitCode 가 빈 값이 된다.
    # 빈 값은 아래 호출부의 -ne 0 에 걸려 성공한 전송까지 실패로 처리된다.
    $null = $proc.Handle

    $proc.WaitForExit()
    return $proc.ExitCode
}

function Get-SshErrorHint {
    <#
        ssh 실패 원문을 보고 사용자가 할 일을 한 줄로 알려 준다.
    #>
    param([string] $StdErr)

    if ($StdErr -match 'Permission denied|publickey|Too many authentication failures') {
        return '키 등록 필요 - 이 PC 의 공개키가 서버 ~/.ssh/authorized_keys 에 없다. README 1단계를 먼저 하라.'
    }
    if ($StdErr -match 'Host key verification failed|REMOTE HOST IDENTIFICATION') {
        return '서버 호스트 키가 known_hosts 와 다르다. 손으로 ssh 접속해 확인하라(중간자 공격일 수도 있다).'
    }
    if ($StdErr -match 'sudo:.*password|sudo: a terminal is required|not allowed to execute') {
        return '서버 sudo 가 NOPASSWD 로 tar 를 허용하지 않는다. 서버 관리자에게 확인하라.'
    }
    if ($StdErr -match 'Connection timed out|No route to host|Network is unreachable|Connection refused') {
        return '서버에 닿지 않는다. 사내망/VPN 연결과 서버 전원을 확인하라.'
    }
    if ($StdErr -match 'Operation timed out|Timeout, server') {
        return '전송 도중 연결이 끊겼다. 다시 실행하라.'
    }
    return '위 ssh 오류 원문을 그대로 보고 판단하라.'
}

function Test-BackupArtifact {
    <#
        폴더에서 가장 최근 산출물 하나를 골라 .sha256 과 대조한다.
        .sha256 형식은 서버 sha256sum 출력과 같은 "<해시>  <파일이름>" (경로 없음)이다.
    #>
    param(
        [Parameter(Mandatory = $true)][string] $Dir,
        [Parameter(Mandatory = $true)][string] $Pattern,
        [Parameter(Mandatory = $true)][string] $Label
    )

    $result = [pscustomobject]@{ Label = $Label; Name = '(없음)'; Ok = $false; Reason = '' }

    if (-not (Test-Path -LiteralPath $Dir -PathType Container)) {
        $result.Reason = '폴더가 없다: ' + $Dir
        return $result
    }

    # 이름이 db-YYYYMMDD-HHMM / files-YYYYMMDD 라 이름 역순 = 최신순이다.
    $latest = @(Get-ChildItem -LiteralPath $Dir -Filter $Pattern -File -ErrorAction SilentlyContinue |
        Sort-Object -Property Name -Descending)
    if ($latest.Count -eq 0) {
        $result.Reason = '산출물이 하나도 없다 (' + $Pattern + ')'
        return $result
    }

    $file = $latest[0]
    $result.Name = $file.Name

    $shaPath = $file.FullName + '.sha256'
    if (-not (Test-Path -LiteralPath $shaPath -PathType Leaf)) {
        $result.Reason = '.sha256 이 없다'
        return $result
    }

    $firstLine = (Get-Content -LiteralPath $shaPath -TotalCount 1)
    if (-not $firstLine) {
        $result.Reason = '.sha256 이 비어 있다'
        return $result
    }

    # "<해시>  <파일이름>" 의 첫 칸만 쓴다.
    $fields = @(($firstLine.Trim()) -split '\s+')
    $expected = $fields[0]
    if ($expected -notmatch '^[0-9a-fA-F]{64}$') {
        $result.Reason = '.sha256 첫 칸이 SHA-256 해시가 아니다'
        return $result
    }
    if ($fields.Count -ge 2 -and $fields[1] -ne $file.Name) {
        Write-Log ('  주의: .sha256 안의 이름({0})이 파일 이름({1})과 다르다' -f $fields[1], $file.Name)
    }

    $actual = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
    if ($actual -ieq $expected) {
        $result.Ok = $true
        $result.Reason = 'sha256 일치'
    } else {
        $result.Reason = 'sha256 불일치 (기대 {0}... / 실제 {1}...)' -f $expected.Substring(0, 12), $actual.Substring(0, 12)
    }
    return $result
}

function Remove-OldDatedFile {
    <#
        파일 이름 안의 YYYYMMDD 가 보관 기간을 넘으면 지운다.
        .sha256 도 이름에 같은 날짜가 있으므로 함께 지워진다.
    #>
    param(
        [Parameter(Mandatory = $true)][string] $Dir,
        [Parameter(Mandatory = $true)][int] $KeepDays
    )

    if (-not (Test-Path -LiteralPath $Dir -PathType Container)) { return 0 }

    $cutoff = (Get-Date).Date.AddDays(-$KeepDays)
    $removed = 0

    foreach ($file in @(Get-ChildItem -LiteralPath $Dir -File -ErrorAction SilentlyContinue)) {
        $m = [regex]::Match($file.Name, '(20\d{6})')
        if (-not $m.Success) { continue }   # 날짜가 없는 파일은 건드리지 않는다.

        $stamp = [datetime]::MinValue
        $parsed = [datetime]::TryParseExact($m.Groups[1].Value, 'yyyyMMdd',
            [System.Globalization.CultureInfo]::InvariantCulture,
            [System.Globalization.DateTimeStyles]::None, [ref] $stamp)
        if (-not $parsed) { continue }

        if ($stamp -lt $cutoff) {
            Remove-Item -LiteralPath $file.FullName -Force
            Write-Log ('  보관 초과({0}일) 삭제: {1}' -f $KeepDays, $file.Name)
            $removed++
        }
    }
    return $removed
}

function Remove-OldLogFile {
    <#
        로그는 이름에 날짜가 없는 것도 있어 수정 시각(LastWriteTime)으로 정리한다.
    #>
    param(
        [Parameter(Mandatory = $true)][string] $Dir,
        [Parameter(Mandatory = $true)][int] $KeepDays
    )

    if (-not (Test-Path -LiteralPath $Dir -PathType Container)) { return 0 }

    $cutoff = (Get-Date).AddDays(-$KeepDays)
    $removed = 0

    foreach ($file in @(Get-ChildItem -LiteralPath $Dir -File -Recurse -ErrorAction SilentlyContinue)) {
        if ($file.LastWriteTime -lt $cutoff) {
            Remove-Item -LiteralPath $file.FullName -Force
            Write-Log ('  보관 초과({0}일) 삭제: {1}' -f $KeepDays, $file.Name)
            $removed++
        }
    }
    return $removed
}

# ----------------------------------------------------------------------------
# -Key : 백업 복호화 키 사본 만들기 (PRD 7.8 "키는 백업과 다른 장소")
# ----------------------------------------------------------------------------

function Save-BackupKey {
    param([Parameter(Mandatory = $true)][string] $KeyPath)

    # 키가 어디 있는지를 백업 폴더의 pull.log 에 남기지 않는다(백업+키 동시 유출 방지).
    # 그래서 이 함수의 출력은 전부 화면 전용(-NoFile)이다.

    $full = $KeyPath
    if (-not [System.IO.Path]::IsPathRooted($full)) {
        $full = Join-Path (Get-Location).Path $full
    }
    $full = [System.IO.Path]::GetFullPath($full)

    # 백업 폴더 "안"인지 본다. 끝에 구분자를 붙여 C:\tbox-backup-key 같은 이웃 폴더를 오판하지 않는다.
    $destFull = [System.IO.Path]::GetFullPath($Dest).TrimEnd('\') + '\'
    if ($full.StartsWith($destFull, [System.StringComparison]::OrdinalIgnoreCase)) {
        Write-Log -NoFile ('거부: 키를 백업 폴더({0}) 안에 저장할 수 없다. USB 등 다른 매체를 지정하라 (PRD 7.8, R-88).' -f $Dest)
        return 2
    }

    if (Test-Path -LiteralPath $full) {
        Write-Log -NoFile ('이미 있다: {0} - 덮어쓰지 않는다. 다시 받으려면 그 파일을 먼저 치워라.' -f $full)
        return 2
    }

    $parent = Split-Path -Path $full -Parent
    if ($parent -and -not (Test-Path -LiteralPath $parent -PathType Container)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }

    $sshExe = Get-ToolPath -Name 'ssh.exe' -Fallback @((Join-Path $env:SystemRoot 'System32\OpenSSH\ssh.exe'))
    if (-not $sshExe) {
        Write-Log -NoFile 'ssh.exe 를 찾지 못했다. Windows 10 1809 이상이면 [설정] > [앱] > [선택적 기능] 에서 OpenSSH 클라이언트를 켜라.'
        return 1
    }

    $errFile = Join-Path $env:TEMP ('tbox-key-{0}.err' -f $PID)
    $code = Invoke-SshToFile -SshExe $sshExe -RemoteCommand 'sudo -n cat /etc/tbox-v2/backup.key' -OutFile $full -ErrFile $errFile

    $stdErr = ''
    if (Test-Path -LiteralPath $errFile) {
        $stdErr = (Get-Content -LiteralPath $errFile -Raw -ErrorAction SilentlyContinue)
        Remove-Item -LiteralPath $errFile -Force -ErrorAction SilentlyContinue
    }

    if ($code -ne 0) {
        # 내용이 아니라 흔적만 지운다. 실패한 빈 파일을 남기지 않는다.
        Remove-Item -LiteralPath $full -Force -ErrorAction SilentlyContinue
        Write-Log -NoFile ('키 내려받기 실패 (ssh 종료 코드 {0})' -f $code)
        if ($stdErr) { Write-Log -NoFile ('ssh: ' + $stdErr.Trim()) }
        Write-Log -NoFile ('조치: ' + (Get-SshErrorHint -StdErr $stdErr))
        return 1
    }

    $size = (Get-Item -LiteralPath $full).Length
    if ($size -le 0) {
        Remove-Item -LiteralPath $full -Force -ErrorAction SilentlyContinue
        Write-Log -NoFile '키가 비어 있다. 서버의 /etc/tbox-v2/backup.key 를 확인하라.'
        return 1
    }

    # 키 "내용"은 화면에도 로그에도 찍지 않는다. 경로와 바이트 수만 알린다.
    Write-Log -NoFile ('키를 저장했다: {0} ({1} 바이트)' -f $full, $size)
    Write-Log -NoFile '이 매체는 백업 사본과 다른 장소에 보관하라. 키를 잃으면 백업 전체가 무용지물이다 (SECURITY R-08).'
    return 0
}

# ----------------------------------------------------------------------------
# -Install / -Uninstall : 작업 스케줄러
# ----------------------------------------------------------------------------

function Install-PullTask {
    $scriptPath = $PSCommandPath
    if (-not $scriptPath) { $scriptPath = $MyInvocation.MyCommand.Path }
    if (-not $scriptPath) {
        Write-Log -NoFile '스크립트 경로를 알 수 없어 등록할 수 없다. 파일로 저장한 뒤 실행하라.'
        return 1
    }

    if (-not (Get-Command -Name 'Register-ScheduledTask' -ErrorAction SilentlyContinue)) {
        Write-Log -NoFile 'Register-ScheduledTask 를 쓸 수 없는 Windows 다. 작업 스케줄러 GUI 로 매일 05:00 실행을 직접 등록하라.'
        return 1
    }

    $psExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

    # 지금 실행에 쓴 값을 그대로 박아 둔다(등록 후 스크립트를 편집할 필요가 없게).
    $argLine = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "{0}" -Server {1} -User {2} -Dest "{3}" -Days {4} -KeepDays {5}' -f `
        $scriptPath, $Server, $User, $Dest, $Days, $KeepDays

    try {
        $action   = New-ScheduledTaskAction -Execute $psExe -Argument $argLine
        $trigger  = New-ScheduledTaskTrigger -Daily -At ([datetime]'05:00')
        # StartWhenAvailable: PC 가 꺼져 있어 05:00 을 놓쳤으면 켜진 뒤 실행한다.
        $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
            -ExecutionTimeLimit (New-TimeSpan -Hours 2)

        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
            -User $env:USERNAME `
            -Description 't-box v2 서버의 암호화 백업과 로그를 매일 이 PC 로 내려받는다 (F-55 / PRD 7.8 S-08)' `
            -Force | Out-Null
    } catch {
        Write-Log -NoFile ('등록 실패: {0}' -f $_.Exception.Message)
        Write-Log -NoFile '조치: PowerShell 을 "관리자 권한으로 실행" 한 뒤 -Install 을 다시 하라.'
        return 1
    }

    Write-Log -NoFile ("등록했다: '{0}' - 매일 05:00, 놓치면 켜진 뒤 실행(StartWhenAvailable), 실행 계정 {1}" -f $TaskName, $env:USERNAME)
    Write-Log -NoFile ("확인: Get-ScheduledTask '{0}' | Format-List TaskName, State" -f $TaskName)
    return 0
}

function Uninstall-PullTask {
    if (-not (Get-Command -Name 'Unregister-ScheduledTask' -ErrorAction SilentlyContinue)) {
        Write-Log -NoFile 'Unregister-ScheduledTask 를 쓸 수 없는 Windows 다. 작업 스케줄러 GUI 에서 지워라.'
        return 1
    }
    try {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    } catch {
        Write-Log -NoFile ('제거 실패(등록돼 있지 않을 수 있다): {0}' -f $_.Exception.Message)
        return 1
    }
    Write-Log -NoFile ("제거했다: '{0}'" -f $TaskName)
    return 0
}

# ----------------------------------------------------------------------------
# 기본 동작 : 내려받기 -> 풀기 -> 검증 -> 보관 정리
# ----------------------------------------------------------------------------

function Invoke-Pull {
    # 1) 저장 위치와 로그 준비
    if (-not (Test-Path -LiteralPath $Dest -PathType Container)) {
        New-Item -ItemType Directory -Path $Dest -Force | Out-Null
    }
    $script:LogPath = Join-Path $Dest 'pull.log'

    Write-Log '---- pull 시작 ----'
    Write-Log ('서버 {0}@{1} / 최근 {2}일분 / 저장 {3} / 보관 {4}일' -f $User, $Server, $Days, $Dest, $KeepDays)

    $sshExe = Get-ToolPath -Name 'ssh.exe' -Fallback @((Join-Path $env:SystemRoot 'System32\OpenSSH\ssh.exe'))
    if (-not $sshExe) {
        Write-Log 'FAIL | ssh.exe 없음 - [설정] > [앱] > [선택적 기능] 에서 "OpenSSH 클라이언트" 를 설치하라.'
        return 1
    }
    $tarExe = Get-ToolPath -Name 'tar.exe' -Fallback @((Join-Path $env:SystemRoot 'System32\tar.exe'))
    if (-not $tarExe) {
        Write-Log 'FAIL | tar.exe 없음 - Windows 10 1803 이상에서만 내장된다. Windows 를 올리거나 bsdtar 를 PATH 에 두라.'
        return 1
    }

    $tarPath = Join-Path $Dest '_incoming.tar'
    $errPath = Join-Path $Dest '_incoming.err'
    Remove-Item -LiteralPath $tarPath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $errPath -Force -ErrorAction SilentlyContinue

    # 2) 서버에서 읽기 전용 tar 를 받아 파일로 저장 (PowerShell 파이프를 쓰지 않는다)
    $remoteCmd = "sudo -n tar -C / --newer-mtime='$Days days ago' -cf - $RemotePaths"
    Write-Log ('서버 명령: ' + $remoteCmd)

    $code = Invoke-SshToFile -SshExe $sshExe -RemoteCommand $remoteCmd -OutFile $tarPath -ErrFile $errPath

    $stdErr = ''
    if (Test-Path -LiteralPath $errPath) {
        $stdErr = (Get-Content -LiteralPath $errPath -Raw -ErrorAction SilentlyContinue)
    }
    if ($stdErr) {
        foreach ($l in @($stdErr.Trim() -split "`r?`n")) { Write-Log ('  ssh: ' + $l) }
    }

    if ($code -ne 0) {
        Write-Log ('FAIL | ssh 종료 코드 {0} | 조치: {1}' -f $code, (Get-SshErrorHint -StdErr $stdErr))
        Remove-Item -LiteralPath $tarPath -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $errPath -Force -ErrorAction SilentlyContinue
        return 1
    }
    Remove-Item -LiteralPath $errPath -Force -ErrorAction SilentlyContinue

    if (-not (Test-Path -LiteralPath $tarPath -PathType Leaf)) {
        Write-Log 'FAIL | 받은 파일이 없다.'
        return 1
    }
    $bytes = (Get-Item -LiteralPath $tarPath).Length
    if ($bytes -le 0) {
        Write-Log 'FAIL | 받은 tar 가 0 바이트다. 서버 백업 폴더가 비었거나 sudo tar 가 아무것도 내보내지 못했다.'
        Remove-Item -LiteralPath $tarPath -Force -ErrorAction SilentlyContinue
        return 1
    }
    Write-Log ('받음: {0:N0} 바이트' -f $bytes)

    # 3) 목록 확인 후 풀기
    $entries = @(& $tarExe -tf $tarPath 2>$null)
    if ($LASTEXITCODE -ne 0 -or $entries.Count -eq 0) {
        Write-Log 'FAIL | 받은 파일이 올바른 tar 가 아니다(전송 중 깨졌거나 서버가 오류 문구를 내보냈다). _incoming.tar 를 남겨 두니 내용을 확인하라.'
        return 1
    }
    $itemCount = $entries.Count

    # tar 가 화면에 내보내는 말이 함수 반환값에 섞이지 않게 따로 받는다.
    $extractOut = @(& $tarExe -xf $tarPath -C $Dest 2>&1)
    foreach ($l in $extractOut) { Write-Log ('  tar: ' + $l) }
    if ($LASTEXITCODE -ne 0) {
        Write-Log ('FAIL | tar 풀기 실패 (종료 코드 {0}). _incoming.tar 를 남겨 둔다.' -f $LASTEXITCODE)
        return 1
    }
    Remove-Item -LiteralPath $tarPath -Force -ErrorAction SilentlyContinue
    Write-Log ('풀었음: 항목 {0}개' -f $itemCount)

    # 4) 검증 - 최신 db / files 산출물을 .sha256 과 대조 (서버 verify_backup.sh 1단계와 같은 기준)
    $status = 'OK'

    $dbCheck = Test-BackupArtifact -Dir (Join-Path $Dest 'var\backups\tbox-v2\db') -Pattern 'db-*.dump.gpg' -Label 'db'
    Write-Log ('검증 db    : {0} - {1}' -f $dbCheck.Name, $dbCheck.Reason)

    $fileCheck = Test-BackupArtifact -Dir (Join-Path $Dest 'var\backups\tbox-v2\files') -Pattern 'files-*.tar.gz.gpg' -Label 'files'
    Write-Log ('검증 files : {0} - {1}' -f $fileCheck.Name, $fileCheck.Reason)

    if (-not ($dbCheck.Ok -and $fileCheck.Ok)) {
        $status = 'FAIL'
    } else {
        # 오늘 날짜 산출물이 없으면 서버 백업이 아직 안 돌았거나 실패한 것이다.
        $today = Get-Date -Format 'yyyyMMdd'
        $missing = @()
        if ($dbCheck.Name -notmatch $today) { $missing += 'db' }
        if ($fileCheck.Name -notmatch $today) { $missing += 'files' }
        if ($missing.Count -gt 0) {
            $status = 'WARN'
            Write-Log ('WARN | 오늘({0}) 산출물이 없다: {1} - 서버 백업(03:00/03:30)이 돌았는지 확인하라.' -f $today, ($missing -join ', '))
        }
    }

    # 5) 보관 정리 - PC 사본도 45일을 넘기지 않는다 (PRD 7.8)
    $removed = 0
    foreach ($rel in $DatedDirs) {
        $removed += Remove-OldDatedFile -Dir (Join-Path $Dest $rel) -KeepDays $KeepDays
    }
    $removed += Remove-OldLogFile -Dir (Join-Path $Dest $LogDir) -KeepDays $KeepDays
    Write-Log ('보관 정리: {0}개 삭제 ({1}일 초과)' -f $removed, $KeepDays)

    # 6) 요약 한 줄 - 매일 이 줄만 보면 된다
    Write-Log ('{0} | 항목 {1}개 | {2:N0} 바이트 | db={3} | files={4}' -f `
        $status, $itemCount, $bytes, $dbCheck.Name, $fileCheck.Name)

    if ($status -eq 'FAIL') { return 1 }
    return 0
}

# ----------------------------------------------------------------------------
# 진입점
# ----------------------------------------------------------------------------

$modes = 0
if ($PSBoundParameters.ContainsKey('Key')) { $modes++ }
if ($Install)   { $modes++ }
if ($Uninstall) { $modes++ }

if ($modes -gt 1) {
    Write-Log -NoFile '-Key / -Install / -Uninstall 은 한 번에 하나만 쓴다.'
    exit 1
}

try {
    if ($PSBoundParameters.ContainsKey('Key')) {
        $exitCode = Save-BackupKey -KeyPath $Key
    } elseif ($Install) {
        $exitCode = Install-PullTask
    } elseif ($Uninstall) {
        $exitCode = Uninstall-PullTask
    } else {
        $exitCode = Invoke-Pull
    }
} catch {
    Write-Log ('FAIL | 예기치 못한 오류: {0}' -f $_.Exception.Message)
    Write-Log ('       위치: {0}' -f $_.InvocationInfo.PositionMessage)
    $exitCode = 1
}

# 함수가 예상 밖의 출력을 함께 돌려줬더라도 종료 코드는 마지막 값 하나만 쓴다.
if ($exitCode -is [array]) { $exitCode = $exitCode[-1] }
if ($null -eq $exitCode) { $exitCode = 1 }

exit ([int] $exitCode)
