<#
.SYNOPSIS
    Builds/stages and deploys jackbrain WordPress theme files from this local
    repo checkout to the live jackson-brain.com server.

.DESCRIPTION
    Copies theme files (all of them by default, or a specific subset via -Path)
    from this script's own directory to a local staging folder, uploads them to
    the server via scp, then runs deploy-theme-remote.sh there (over SSH, via
    sudo) to back up any existing live file under
    /storage/backups/wordpress-theme-jackbrain-bak/, deploy the new one, restart
    the `wordpress` podman-compose service (required so its SELinux :Z bind
    mount relabels correctly - see repo memory's "SELinux MCS mismatch" note),
    and verify the site still returns 200 afterward.

    Only files that exist in THIS local directory are ever touched - most of
    the live theme (images/, js/libs.js, other page templates, etc.) is
    untracked and lives only on the server; this script has no effect on files
    that don't exist locally. See README.md's "Which files are tracked" section
    for what's actually in this folder.

    This script only handles plain file copies. The cube engine under
    JS/cuber/ has its own separate build+deploy process (npm test/build, then
    a manual scp of dist/main.js + dist/style.css + jbAbout.php together) -
    see README.md's "Deployment" section; do not use this script for that.

.PARAMETER Path
    One or more file paths, relative to this script's directory, to deploy
    (e.g. "style.css" or "functions.php","js/jbrain.js"). Defaults to every
    file found in this directory (excluding this script itself, the remote
    helper script, and README.md).

.PARAMETER RemoteHost
    SSH host alias to deploy to. Defaults to "linus" (this repo's existing
    SSH config alias for jackson-brain.com) - uses your own already-configured
    SSH key/agent, same as every other manual deploy in this repo.

.PARAMETER NoRestart
    Skip restarting the `wordpress` container after deploying. Only use this
    when deploying multiple files back-to-back across several script runs and
    restarting once manually at the end - a restart is required for changes to
    actually take effect (and even to remain readable - see the SELinux note
    above), and is the default.

.PARAMETER DryRun
    Print which files would be deployed without copying or touching anything
    remote.

.EXAMPLE
    .\deploy-theme.ps1
    Deploy every local theme file.

.EXAMPLE
    .\deploy-theme.ps1 -Path style.css
    Deploy just style.css.

.EXAMPLE
    .\deploy-theme.ps1 -Path functions.php,js\jbrain.js -DryRun
    Show what would be deployed without doing anything.
#>
[CmdletBinding()]
param(
    [string[]] $Path,
    [string] $RemoteHost = "linus",
    [switch] $NoRestart,
    [switch] $DryRun
)

$ErrorActionPreference = "Stop"

$LocalThemeDir = $PSScriptRoot
$RemoteScriptName = "deploy-theme-remote.sh"
$RemoteScriptLocal = Join-Path $LocalThemeDir $RemoteScriptName

if (-not (Test-Path $RemoteScriptLocal)) {
    throw "Missing $RemoteScriptName next to this script - required for the remote deploy step."
}

# Files in this folder that are never themselves deployed as theme content.
$Exclude = @("README.md", "deploy-theme.ps1", $RemoteScriptName)

if ($Path) {
    $Files = $Path | ForEach-Object { $_ -replace '\\', '/' }
    foreach ($f in $Files) {
        $full = Join-Path $LocalThemeDir ($f -replace '/', '\')
        if (-not (Test-Path $full -PathType Leaf)) {
            throw "File not found: $full"
        }
    }
} else {
    $Files = Get-ChildItem -Path $LocalThemeDir -Recurse -File |
        Where-Object { $Exclude -notcontains $_.Name } |
        ForEach-Object { $_.FullName.Substring($LocalThemeDir.Length + 1) -replace '\\', '/' }
}

if (-not $Files) {
    Write-Host "No files to deploy."
    return
}

Write-Host "Files to deploy:"
$Files | ForEach-Object { Write-Host "  $_" }

if ($DryRun) {
    Write-Host "`n(DryRun - nothing copied or executed)"
    return
}

$Timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$StagingLeaf = "jackbrain-deploy-$Timestamp"
$LocalStagingDir = Join-Path $env:TEMP $StagingLeaf
$RemoteStagingDir = "/tmp/$StagingLeaf"

New-Item -ItemType Directory -Path $LocalStagingDir -Force | Out-Null
foreach ($f in $Files) {
    $src = Join-Path $LocalThemeDir ($f -replace '/', '\')
    $dst = Join-Path $LocalStagingDir ($f -replace '/', '\')
    New-Item -ItemType Directory -Path (Split-Path $dst) -Force | Out-Null
    Copy-Item -Path $src -Destination $dst -Force
}

Write-Host "`nUploading staged files to ${RemoteHost}:$RemoteStagingDir ..."
scp -r $LocalStagingDir "${RemoteHost}:/tmp/"
if ($LASTEXITCODE -ne 0) { throw "scp upload of staged files failed" }

scp $RemoteScriptLocal "${RemoteHost}:/tmp/$RemoteScriptName"
if ($LASTEXITCODE -ne 0) { throw "scp of remote deploy script failed" }

Write-Host "`nRunning remote deploy (sudo password may be prompted below) ..."
$restartFlag = if ($NoRestart) { "0" } else { "1" }
ssh -t $RemoteHost "sudo bash /tmp/$RemoteScriptName '$RemoteStagingDir' $restartFlag"
$deployExit = $LASTEXITCODE

Remove-Item -Recurse -Force $LocalStagingDir

if ($deployExit -ne 0) {
    Write-Error "Remote deploy reported a problem (exit $deployExit). Check the output above - backups are in /storage/backups/wordpress-theme-jackbrain-bak/ on the server if a manual rollback is needed."
    exit $deployExit
}

Write-Host "`nDeploy complete."
