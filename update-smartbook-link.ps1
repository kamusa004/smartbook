# SmartBook - refresh the shareable links.
# Runs at every startup from start-smartbook.cmd.
#   1. finds the tunnel address that is actually answering right now
#   2. writes it into the app shell + plain-text link files
#   3. publishes the result to the permanent GitHub Pages address
# Any failure is logged and swallowed so the server itself keeps running.

$ErrorActionPreference = 'Stop'
$RT  = 'C:\Users\iTEK\Documents\Default Project\.runtime'
$APP = 'C:\Users\iTEK\Desktop\smartbook'
$logFile = Join-Path $RT 'smartbook-link-update.log'

function Write-Log([string]$message) {
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $message"
    Add-Content -Path $logFile -Value $line -Encoding UTF8
}

try {
    # ---------- 1. Find a tunnel address that responds to /api/health -------
    $url = $null
    $tunnelLog = Join-Path $RT 'tunnel.log'
    if (Test-Path $tunnelLog) {
        $found = @()
        Select-String -Path $tunnelLog -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches | ForEach-Object {
            foreach ($m in $_.Matches) { $found += $m.Value }
        }
        $unique = @($found | Select-Object -Unique)
        [array]::Reverse($unique)           # newest first
        foreach ($candidate in ($unique | Select-Object -First 8)) {
            try {
                $r = Invoke-WebRequest -UseBasicParsing -Uri ($candidate.TrimEnd('/') + '/api/health') -TimeoutSec 6
                if ($r.StatusCode -eq 200 -and $r.Content -match '"ok"\s*:\s*true') {
                    $url = $candidate.TrimEnd('/')
                    break
                }
            } catch { /* this address is dead - try the previous one */ }
        }
    }

    $jsFile = Join-Path $APP 'frontend\assets\js\server-url.js'
    if (-not $url -and (Test-Path $jsFile)) {
        $old = Get-Content $jsFile -Raw
        if ($old -match "SMARTBOOK_TUNNEL_BASE\s*=\s*'([^']+)'") { $url = $Matches[1] }
        Write-Log 'No live tunnel answered; kept the last known address.'
    }

    # Keep the LAN address current too (Wi-Fi IP can change).
    $lanBase = ''
    $lanIp = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object {
        $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*'
    } | Select-Object -First 1).IPAddress
    if ($lanIp) { $lanBase = "http://$lanIp`:8080" }
    Write-Log "Live address: $url   LAN address: $lanBase"

    # ---------- 2. Write the link files ------------------------------------
    $js = @"
/* SmartBook - current server address.
 * GENERATED FILE. Do not edit by hand: update-smartbook-link.ps1 rewrites it
 * every time the system starts so phones/tablets always find the live server
 * even though the quick-tunnel web address changes. */
window.SMARTBOOK_TUNNEL_BASE = '$url';
window.SMARTBOOK_LAN_BASE = '$lanBase';
"@
    Set-Content -Path $jsFile -Value $js -Encoding ASCII

    $links = @"
SMARTBOOK SOLUTIONS - CURRENT LINKS
==================================

1) PERMANENT LINK (use this one on phones and other gadgets)
   https://kamusa004.github.io/smartbook/
   This page now follows the live server automatically. Add it to the
   home screen once; it will keep working after every restart.

2) DIRECT LIVE LINK (changes whenever the computer restarts)
   $url

3) SAME WI-FI LINK (laptops/phones connected to this Wi-Fi)
   $lanBase

Keep this computer ON and connected to the internet. The server, database and
tunnel start automatically when Windows signs in.
"@
    Set-Content -Path (Join-Path $APP 'SMARTBOOK-LINKS.txt') -Value $links -Encoding UTF8
    Set-Content -Path (Join-Path $APP 'SMARTBOOK-CURRENT-LINK.txt') -Value "$url" -Encoding ASCII

    # ---------- 3. Publish to the hosted page ------------------------------
    $env:GIT_TERMINAL_PROMPT = '0'
    Push-Location $APP
    try {
        git fetch public gh-pages --quiet 2>$null

        # Main branch: GitHub Actions rebuilds the Pages site from frontend/.
        git add frontend/assets/js/server-url.js 2>$null
        git diff --cached --quiet 2>$null
        if ($LASTEXITCODE -ne 0) {
            git -c user.name='SmartBook Updater' -c user.email='updater@smartbook.ug' `
                commit -m "Live link: $url" --quiet 2>$null
            Write-Log 'Committed the new live link.'
        }
        git push public main:main --quiet 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Log 'WARNING: could not push the main branch to the public repo.' }
        else { Write-Log 'Published the new link to the hosted page (main).' }

        # gh-pages branch: legacy static source, kept in sync as a fallback.
        $wt = Join-Path $env:TEMP 'smartbook-pages-publish'
        git worktree remove --force $wt 2>$null | Out-Null
        if (Test-Path $wt) { Remove-Item $wt -Recurse -Force }
        git worktree add --detach $wt public/gh-pages --quiet 2>$null
        if ($LASTEXITCODE -eq 0) {
            Copy-Item (Join-Path $APP 'frontend\*') $wt -Recurse -Force
            Push-Location $wt
            git add -A 2>$null
            git diff --cached --quiet 2>$null
            if ($LASTEXITCODE -ne 0) {
                git -c user.name='SmartBook Updater' -c user.email='updater@smartbook.ug' `
                    commit -m "Live link: $url" --quiet 2>$null
                git push public HEAD:gh-pages --quiet 2>&1 | Out-Null
                if ($LASTEXITCODE -ne 0) { Write-Log 'WARNING: could not push the gh-pages branch.' }
                else { Write-Log 'Published the new link to the hosted page (gh-pages).' }
            }
            Pop-Location
            git worktree remove --force $wt 2>$null | Out-Null
        } else {
            Write-Log 'WARNING: could not create the gh-pages publishing worktree.'
        }
    } finally {
        Pop-Location
    }

    Write-Log 'Done.'
} catch {
    Write-Log "ERROR: $($_.Exception.Message)"
}
