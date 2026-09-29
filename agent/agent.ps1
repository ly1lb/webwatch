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
            Write-Host "KLAIDA: serveris atmete rakta (403). Sugeneruokite nauja rakta WebWatch nustatymuose."
            Start-Sleep -Seconds 30
        } else {
            Write-Host "Nera rysio su serveriu ($($_.Exception.Message)) - bandau vel po $backoff s"
            Start-Sleep -Seconds $backoff
            $backoff = [Math]::Min($backoff * 2, 60)
        }
    }
}
