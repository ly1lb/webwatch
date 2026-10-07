# WebWatch tikrinimo taskas (agentas) - Windows
#
# Si programa veikia jusu kompiuteryje ir parsiuncia puslapius per sio kompiuterio
# interneto rysi, kai WebWatch to papraso.
#
# Paleisti:            powershell -ExecutionPolicy Bypass -File ww-agent.ps1
# Idiegti (autostart): powershell -ExecutionPolicy Bypass -File ww-agent.ps1 -Install
# Pasalinti:           powershell -ExecutionPolicy Bypass -File ww-agent.ps1 -Uninstall

param([switch]$Install, [switch]$Uninstall)

$Server = "__WW_SERVER__"
$Token  = "__WW_TOKEN__"
$Name   = "__WW_NAME__"
$Version = "7"
$PollWait = 25
$BrowserTimeoutMs = 35000

# Bendri narsykles parametrai: isjungti rysiai su Google (atnaujinimai, telemetrija) -
# ribotame tinkle jie kabo ir sukelia timeout'us.
$ChromeFlags = @(
    "--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
    "--disable-extensions", "--mute-audio", "--hide-scrollbars", "--disable-dev-shm-usage",
    "--disable-background-networking", "--disable-component-update", "--disable-default-apps",
    "--disable-sync", "--disable-translate", "--no-pings", "--metrics-recording-only",
    "--disable-crash-reporter", "--disable-breakpad", "--disable-renderer-backgrounding",
    "--disable-backgrounding-occluded-windows", "--disable-background-timer-throttling",
    "--disable-client-side-phishing-detection", "--password-store=basic",
    "--disable-features=Translate,BackForwardCache,InterestCohort,OptimizationHints"
)

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls13

$LogPath = Join-Path $env:APPDATA "WWAgent\agent.log"
$script:Recent = New-Object System.Collections.Generic.List[string]
function Log($msg) {
    $line = (Get-Date -Format "yyyy-MM-dd HH:mm:ss ") + $msg
    Write-Host $line
    $script:Recent.Add($line)
    while ($script:Recent.Count -gt 40) { $script:Recent.RemoveAt(0) }
    try {
        New-Item -ItemType Directory -Force -Path (Split-Path $LogPath) | Out-Null
        Add-Content -Path $LogPath -Value $line -ErrorAction SilentlyContinue
        if ((Get-Item $LogPath).Length -gt 1MB) { (Get-Content $LogPath -Tail 3000) | Set-Content $LogPath }
    } catch {}
}

function Kill-Tree($proc) {
    # Nuzudo VISA narsykles procesu medi (Chrome paleidzia daug vaikiniu procesu).
    if ($proc -and -not $proc.HasExited) {
        try { & taskkill /F /T /PID $proc.Id 2>$null | Out-Null } catch {}
        try { if (-not $proc.HasExited) { $proc.Kill($true) } } catch { try { $proc.Kill() } catch {} }
    }
}

function Get-OwnBrowserPids {
    # Tik sio agento paleisti narsykles procesai (pagal laikino profilio zyme wwagent-/wwshot-).
    # Jusu paciu narsykles langai NIEKADA neliečiami.
    try {
        # Tik senesni nei 60 s: veikiancio darbo narsykle visada nuzudoma po 35 s, tad senesnis
        # procesas garantuotai yra „naslaitis" (net jei kompiuteryje veiktu du agentai).
        $limit = (Get-Date).AddSeconds(-60)
        $found = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object {
            $_.CommandLine -match 'ww(agent|shot)-' -and $_.ProcessId -ne $PID -and $_.Name -notmatch '^(powershell|pwsh|python)' -and
            (-not $_.CreationDate -or $_.CreationDate -lt $limit)
        } | ForEach-Object { [int]$_.ProcessId })
        return ,$found   # kablelis: kad tuscias masyvas nevirstu $null
    } catch { return $null }
}

function Clear-OwnBrowsers {
    # Kvieciama TARP darbu: tuo metu agento narsykle neturi veikti, tad rasti procesai - pakibe likuciai.
    $ids = Get-OwnBrowserPids
    if ($null -eq $ids) { return -1 }
    foreach ($id in $ids) {
        try { & taskkill /F /T /PID $id 2>$null | Out-Null } catch {}
        try { Stop-Process -Id $id -Force -ErrorAction SilentlyContinue } catch {}
    }
    if ($ids.Count -gt 0) { Log "Isvalyta pakibusiu narsykles procesu: $($ids.Count)" }
    # Seni laikini profiliai (jei Chrome dar laike failus ir jie nebuvo istrinti)
    try {
        Get-ChildItem -Path $env:TEMP -Directory -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -match '^ww(agent|shot)-' -and $_.LastWriteTime -lt (Get-Date).AddMinutes(-5) } |
            ForEach-Object { Remove-Item -Recurse -Force -Path $_.FullName -ErrorAction SilentlyContinue }
    } catch {}
    return $ids.Count
}

function Send-Diag {
    try {
        $procs = Clear-OwnBrowsers
        $exe = if ($script:BrowserExe -and $script:BrowserExe -ne "?") { Split-Path $script:BrowserExe -Leaf } else { "nerasta" }
        $text = "narsykle: $exe - pakibusiu narsykles procesu rasta ir isvalyta: $procs`n" + ($script:Recent -join "`n")
        $bytes = [Text.Encoding]::UTF8.GetBytes($text)
        $ms = New-Object IO.MemoryStream
        $gz = New-Object IO.Compression.GZipStream($ms, [IO.Compression.CompressionMode]::Compress)
        $gz.Write($bytes, 0, $bytes.Length); $gz.Close()
        $payload = [Convert]::ToBase64String($ms.ToArray())
        $h = @{ "X-Agent-Token" = $Token; "X-Body-Encoding" = "gzip+base64"; "X-Procs" = "$procs"; "Content-Type" = "text/plain" }
        Invoke-RestMethod -Uri "$($Server)agent.php?action=diag" -Method Post -Headers $h -Body $payload -TimeoutSec 20 | Out-Null
    } catch {}
}

function Install-Agent {
    $dstDir = Join-Path $env:APPDATA "WWAgent"
    New-Item -ItemType Directory -Force -Path $dstDir | Out-Null
    $dst = Join-Path $dstDir "ww-agent.ps1"
    Copy-Item -Path $PSCommandPath -Destination $dst -Force
    $action = "powershell -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$dst`""
    # Suplanuota uzduotis: paleisti prisijungus, laikyti veikiancia
    schtasks /Create /TN "WebWatchAgent" /TR $action /SC ONLOGON /RL LIMITED /F | Out-Null
    schtasks /Run /TN "WebWatchAgent" | Out-Null
    Write-Host "Idiegta. Tikrinimo taskas veiks ir po perkrovimo. Sita langa galite uzdaryti."
    exit 0
}

function Uninstall-Agent {
    schtasks /End /TN "WebWatchAgent" 2>$null | Out-Null
    schtasks /Delete /TN "WebWatchAgent" /F 2>$null | Out-Null
    Write-Host "Pasalinta."
    exit 0
}

if ($Install) { Install-Agent }
if ($Uninstall) { Uninstall-Agent }

$ChallengeRe = 'cf-chl|challenge-platform|Just a moment|Attention Required|Checking your browser|_Incapsula_|px-captcha|captcha-delivery|datadome|ddos-guard|Pardon Our Interruption'

function Find-Browser {
    foreach ($n in @("chrome", "msedge", "brave", "chromium")) {
        $c = Get-Command $n -ErrorAction SilentlyContinue
        if ($c) { return $c.Source }
    }
    $bases = @($env:PROGRAMFILES, ${env:PROGRAMFILES(X86)}, $env:LOCALAPPDATA)
    foreach ($b in $bases) {
        foreach ($rel in @("Google\Chrome\Application\chrome.exe", "Microsoft\Edge\Application\msedge.exe", "BraveSoftware\Brave-Browser\Application\brave.exe")) {
            $p = Join-Path $b $rel
            if (Test-Path $p) { return $p }
        }
    }
    return $null
}

$script:BrowserExe = "?"
function Invoke-BrowserFetch($url, $ua) {
    if ($script:BrowserExe -eq "?") {
        $script:BrowserExe = Find-Browser
        if ($script:BrowserExe) { Write-Host "Rasta narsykle sudetingiems puslapiams: $($script:BrowserExe)" }
        else { Write-Host "Narsykle nerasta - sudetingoms svetainems idiekite Chrome arba Edge." }
    }
    if (-not $script:BrowserExe) { return $null }
    $profile = Join-Path $env:TEMP ("wwagent-" + [Guid]::NewGuid().ToString("N"))
    try {
        New-Item -ItemType Directory -Force -Path $profile | Out-Null
        # Turinį imam tiesiai iš narsykles srauto (stdout), be laikino failo –
        # kitaip Chrome dar laiko faila atidaryta ir gaunam „used by another process“.
        $q = (($ChromeFlags + @(
            "--blink-settings=imagesEnabled=false",
            "--user-data-dir=`"$profile`"", "--user-agent=`"$ua`"",
            "--virtual-time-budget=8000", "--dump-dom", "`"$url`""
        )) -join " ")
        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = $script:BrowserExe
        $psi.Arguments = $q
        $psi.UseShellExecute = $false
        $psi.CreateNoWindow = $true
        $psi.RedirectStandardOutput = $true
        $psi.RedirectStandardError = $true
        $psi.StandardOutputEncoding = [Text.Encoding]::UTF8
        $p = [System.Diagnostics.Process]::Start($psi)
        # Skaitom abu srautus asinchroniškai, kad vamzdis neužsipildytų ir neužstrigtų
        $so = $p.StandardOutput.ReadToEndAsync()
        $se = $p.StandardError.ReadToEndAsync()
        if (-not $p.WaitForExit($BrowserTimeoutMs)) {
            Kill-Tree $p
            return @{ status = 0; body = [byte[]]@(); ctype = ""; err = "narsykle neatsake per $([int]($BrowserTimeoutMs/1000))s (per letas/sunkus puslapis)" }
        }
        $html = $so.Result
        $bytes = [Text.Encoding]::UTF8.GetBytes($html)
        if ($bytes.Length -gt 200 -and $html -notmatch $ChallengeRe) {
            return @{ status = 200; body = $bytes; ctype = "text/html; charset=utf-8"; err = "" }
        }
        return @{ status = 403; body = $bytes; ctype = "text/html"; err = "narsykle negavo turinio (galimai reikia CAPTCHA)" }
    } catch {
        return @{ status = 0; body = [byte[]]@(); ctype = ""; err = "narsykles klaida: $($_.Exception.Message)" }
    } finally {
        Kill-Tree $p
        Remove-Item -Recurse -Force -Path $profile -ErrorAction SilentlyContinue
    }
}

function Invoke-BrowserScreenshot($url, $ua) {
    # Padaro puslapio ekrano nuotrauka (PNG) per vietine narsykle - vaizdiniam stebejimui.
    if ($script:BrowserExe -eq "?") {
        $script:BrowserExe = Find-Browser
        if ($script:BrowserExe) { Write-Host "Rasta narsykle sudetingiems puslapiams: $($script:BrowserExe)" }
        else { Write-Host "Narsykle nerasta - ekrano nuotraukai idiekite Chrome arba Edge." }
    }
    if (-not $script:BrowserExe) { return @{ status = 0; body = [byte[]]@(); ctype = ""; err = "narsykle nerasta (ekrano nuotraukai reikia Chrome/Edge)" } }
    $profile = Join-Path $env:TEMP ("wwshot-" + [Guid]::NewGuid().ToString("N"))
    $outPng = Join-Path $profile "shot.png"
    try {
        New-Item -ItemType Directory -Force -Path $profile | Out-Null
        $q = (($ChromeFlags + @(
            "--force-device-scale-factor=1", "--window-size=1280,2000",
            "--user-data-dir=`"$profile`"", "--user-agent=`"$ua`"",
            "--virtual-time-budget=8000", "--screenshot=`"$outPng`"", "`"$url`""
        )) -join " ")
        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = $script:BrowserExe
        $psi.Arguments = $q
        $psi.UseShellExecute = $false
        $psi.CreateNoWindow = $true
        $p = [System.Diagnostics.Process]::Start($psi)
        if (-not $p.WaitForExit($BrowserTimeoutMs)) {
            Kill-Tree $p
            return @{ status = 0; body = [byte[]]@(); ctype = ""; err = "narsykle neatsake per $([int]($BrowserTimeoutMs/1000))s (per letas/sunkus puslapis)" }
        }
        if (-not (Test-Path $outPng) -or (Get-Item $outPng).Length -lt 100) {
            # Senesne narsykle - bandome su senu headless rezimu (ne po timeout'o)
            $psi.Arguments = $psi.Arguments.Replace("--headless=new", "--headless")
            $p = [System.Diagnostics.Process]::Start($psi)
            if (-not $p.WaitForExit($BrowserTimeoutMs)) { Kill-Tree $p }
        }
        if ((Test-Path $outPng) -and (Get-Item $outPng).Length -ge 100) {
            $bytes = [IO.File]::ReadAllBytes($outPng)
            return @{ status = 200; body = $bytes; ctype = "image/png"; err = "" }
        }
        return @{ status = 0; body = [byte[]]@(); ctype = ""; err = "nepavyko padaryti ekrano nuotraukos" }
    } catch {
        return @{ status = 0; body = [byte[]]@(); ctype = ""; err = "narsykles klaida: $($_.Exception.Message)" }
    } finally {
        Kill-Tree $p
        Remove-Item -Recurse -Force -Path $profile -ErrorAction SilentlyContinue
    }
}

Log "WebWatch tikrinimo taskas '$Name' (v$Version) paleistas. Serveris: $Server"
$script:BrowserExe = Find-Browser
if ($script:BrowserExe) { Log "Rasta narsykle: $($script:BrowserExe)" }
else { Log "Narsykle nerasta - sudetingoms svetainems ir ekrano nuotraukoms idiekite Chrome arba Edge." }
Write-Host "Palikite si langa atidaryta (arba naudokite -Install automatiniam paleidimui)."

$backoff = 2
$lastDiag = [DateTime]::MinValue
Send-Diag
while ($true) {
    try {
        if (((Get-Date) - $lastDiag).TotalSeconds -gt 60) { Send-Diag; $lastDiag = Get-Date }
        $browser = [Uri]::EscapeDataString((Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue).Caption)
        $pollUrl = "$($Server)agent.php?action=poll&wait=$PollWait&os=windows&v=$Version&browser=$browser"
        $resp = Invoke-RestMethod -Uri $pollUrl -Headers @{ "X-Agent-Token" = $Token } -TimeoutSec ($PollWait + 15)
        if (-not $resp.job) { $backoff = 2; continue }
        $job = $resp.job
        Log "-> Tikrinu: $($job.url)$(if($job.shot){' (ekrano nuotrauka)'}elseif($job.browser){' (narsykle)'})"

        $h = @{ "User-Agent" = $job.ua; "Accept-Language" = "lt-LT,lt;q=0.9,en;q=0.8" }
        if ($job.headers) { $job.headers.PSObject.Properties | ForEach-Object { if ($_.Name) { $h[$_.Name] = $_.Value } } }

        $status = 0; $bodyBytes = [byte[]]@(); $finalUrl = $job.url; $ctype = ""; $err = ""
        if ($job.shot) {
            # Vaizdinis stebejimas - ekrano nuotrauka per vietine narsykle
            $s = Invoke-BrowserScreenshot $job.url $job.ua
            $status = $s.status; $bodyBytes = $s.body; $ctype = $s.ctype; $err = $s.err; $finalUrl = $job.url
        }
        elseif (-not $job.browser) {
            try {
                $r = Invoke-WebRequest -Uri $job.url -Headers $h -TimeoutSec 45 -MaximumRedirection 8 -UseBasicParsing -ErrorAction Stop
                $status = [int]$r.StatusCode
                $bodyBytes = if ($r.RawContentStream) { $ms = New-Object IO.MemoryStream; $r.RawContentStream.CopyTo($ms); $ms.ToArray() } else { [Text.Encoding]::UTF8.GetBytes([string]$r.Content) }
                $ctype = [string]$r.Headers["Content-Type"]
                if ($r.BaseResponse -and $r.BaseResponse.ResponseUri) { $finalUrl = $r.BaseResponse.ResponseUri.AbsoluteUri }
            } catch [System.Net.WebException] {
                $we = $_.Exception
                if ($we.Response) {
                    $status = [int]$we.Response.StatusCode
                    try { $sr = New-Object IO.StreamReader($we.Response.GetResponseStream()); $bodyBytes = [Text.Encoding]::UTF8.GetBytes($sr.ReadToEnd()); $ctype = [string]$we.Response.ContentType } catch {}
                } else { $err = $we.Message }
            } catch { $err = $_.Exception.Message }
        }

        # Uzblokuota arba reikia JS -> per vietine narsykle (tikras atspaudas)
        $bodyTxt = if ($bodyBytes.Length) { [Text.Encoding]::UTF8.GetString($bodyBytes, 0, [Math]::Min(30000, $bodyBytes.Length)) } else { "" }
        if (-not $job.shot -and ($job.browser -or $status -in 401,403,405,406,429,451,503 -or ($bodyTxt -match $ChallengeRe))) {
            if (-not $job.browser) { Log "  uzblokuota (HTTP $status) - bandau per vietine narsykle..." }
            $b = Invoke-BrowserFetch $job.url $job.ua
            if ($b) { $status = $b.status; $bodyBytes = $b.body; $ctype = $b.ctype; $err = $b.err; $finalUrl = $job.url }
        }

        # Suspaudziam + base64, kad hostingo WAF neatmestu HTML POST
        $ms = New-Object IO.MemoryStream
        $gz = New-Object IO.Compression.GZipStream($ms, [IO.Compression.CompressionMode]::Compress)
        $gz.Write($bodyBytes, 0, $bodyBytes.Length); $gz.Close()
        $payload = [Convert]::ToBase64String($ms.ToArray())
        $headers = @{
            "X-Agent-Token" = $Token
            "X-Status" = "$status"
            "X-Final-Url" = [Uri]::EscapeDataString($finalUrl)
            "X-Content-Type" = $ctype
            "X-Via" = $Name
            "X-Error" = [Uri]::EscapeDataString($err)
            "X-Body-Encoding" = "gzip+base64"
            "Content-Type" = "text/plain"
        }
        $sent = $false
        for ($try = 0; $try -lt 2 -and -not $sent; $try++) {
            try {
                Invoke-RestMethod -Uri "$($Server)agent.php?action=result&id=$($job.id)" -Method Post -Headers $headers -Body $payload -TimeoutSec 60 | Out-Null
                $sent = $true
            } catch { Start-Sleep -Seconds 2 }
        }
        if ($sent) { Log "  grazinta serveriui (HTTP $status$(if($err){', klaida: '+$err}), $($bodyBytes.Length) baitu)" }
        else { Log "  KLAIDA: nepavyko grazinti rezultato serveriui (gali blokuoti hostingo WAF)" }
        Send-Diag; $lastDiag = Get-Date
        $backoff = 2
    } catch {
        $code = $null
        if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode }
        if ($code -eq 403) {
            Log "KLAIDA: serveris nebeatpazysta sio kompiuterio (403) - raktas pakeistas arba taskas istrintas."
            Log "       WebWatch nustatymuose prie sio tasko spauskite 'Idiegti' ir paleiskite komanda is naujo."
            Start-Sleep -Seconds 60
        } else {
            Log "Nera rysio su serveriu ($($_.Exception.Message)) - bandau vel po $backoff s"
            Start-Sleep -Seconds $backoff
            $backoff = [Math]::Min($backoff * 2, 60)
        }
    }
}
