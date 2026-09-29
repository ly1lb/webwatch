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
$Version = "1"
$PollWait = 25

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls13

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
        $out = Join-Path $profile "dom.html"
        New-Item -ItemType Directory -Force -Path $profile | Out-Null
        $args = @("--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
            "--disable-extensions", "--mute-audio", "--hide-scrollbars", "--user-data-dir=$profile",
            "--user-agent=$ua", "--virtual-time-budget=15000", "--dump-dom", $url)
        $p = Start-Process -FilePath $script:BrowserExe -ArgumentList $args -NoNewWindow -PassThru -RedirectStandardOutput $out
        if (-not $p.WaitForExit(60000)) { $p.Kill(); return @{ status = 0; body = [byte[]]@(); ctype = ""; err = "narsykle neatsake laiku" } }
        $bytes = if (Test-Path $out) { [IO.File]::ReadAllBytes($out) } else { [byte[]]@() }
        $txt = [Text.Encoding]::UTF8.GetString($bytes)
        if ($bytes.Length -gt 200 -and $txt -notmatch $ChallengeRe) {
            return @{ status = 200; body = $bytes; ctype = "text/html; charset=utf-8"; err = "" }
        }
        return @{ status = 403; body = $bytes; ctype = "text/html"; err = "narsykle negavo turinio (galimai reikia CAPTCHA)" }
    } catch {
        return @{ status = 0; body = [byte[]]@(); ctype = ""; err = "narsykles klaida: $($_.Exception.Message)" }
    } finally {
        Remove-Item -Recurse -Force -Path $profile -ErrorAction SilentlyContinue
    }
}

Write-Host "WebWatch tikrinimo taskas '$Name' paleistas. Serveris: $Server"
Write-Host "Palikite si langa atidaryta (arba naudokite -Install automatiniam paleidimui)."

$backoff = 2
while ($true) {
    try {
        $browser = [Uri]::EscapeDataString((Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue).Caption)
        $pollUrl = "$($Server)agent.php?action=poll&wait=$PollWait&os=windows&v=$Version&browser=$browser"
        $resp = Invoke-RestMethod -Uri $pollUrl -Headers @{ "X-Agent-Token" = $Token } -TimeoutSec ($PollWait + 15)
        if (-not $resp.job) { $backoff = 2; continue }
        $job = $resp.job
        Write-Host "-> Tikrinu: $($job.url)"

        $h = @{ "User-Agent" = $job.ua; "Accept-Language" = "lt-LT,lt;q=0.9,en;q=0.8" }
        if ($job.headers) { $job.headers.PSObject.Properties | ForEach-Object { if ($_.Name) { $h[$_.Name] = $_.Value } } }

        $status = 0; $bodyBytes = [byte[]]@(); $finalUrl = $job.url; $ctype = ""; $err = ""
        if (-not $job.browser) {
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
        if ($job.browser -or $status -in 401,403,405,406,429,451,503 -or ($bodyTxt -match $ChallengeRe)) {
            if (-not $job.browser) { Write-Host "  uzblokuota (HTTP $status) - bandau per vietine narsykle..." }
            $b = Invoke-BrowserFetch $job.url $job.ua
            if ($b) { $status = $b.status; $bodyBytes = $b.body; $ctype = $b.ctype; $err = $b.err; $finalUrl = $job.url }
        }

        $headers = @{
            "X-Agent-Token" = $Token
            "X-Status" = "$status"
            "X-Final-Url" = [Uri]::EscapeDataString($finalUrl)
            "X-Content-Type" = $ctype
            "X-Via" = $Name
            "X-Error" = [Uri]::EscapeDataString($err)
            "Content-Type" = "application/octet-stream"
        }
        Invoke-RestMethod -Uri "$($Server)agent.php?action=result&id=$($job.id)" -Method Post -Headers $headers -Body $bodyBytes -TimeoutSec 60 | Out-Null
        Write-Host "  atsakyta (HTTP $status$(if($err){', klaida: '+$err}))"
        $backoff = 2
    } catch {
        $code = $null
        if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode }
        if ($code -eq 403) {
            Write-Host "KLAIDA: serveris nebeatpazysta sio kompiuterio (403) - raktas pakeistas arba taskas istrintas."
            Write-Host "       WebWatch nustatymuose prie sio tasko spauskite 'Idiegti' ir paleiskite komanda is naujo."
            Start-Sleep -Seconds 60
        } else {
            Write-Host "Nera rysio su serveriu ($($_.Exception.Message)) - bandau vel po $backoff s"
            Start-Sleep -Seconds $backoff
            $backoff = [Math]::Min($backoff * 2, 60)
        }
    }
}
