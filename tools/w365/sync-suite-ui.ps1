<#
.SYNOPSIS
  Vendor one released version of the w365 package into a product app (PowerShell twin of sync-suite-ui.sh).

.EXAMPLE
  tools/sync-suite-ui.ps1 -Tag v0.1.0 -Dest portal/public/assets/w365
  tools/sync-suite-ui.ps1 -Tag v0.1.0 -Dest portal/public/assets/w365 -From ../8_west_suite_ui

.DESCRIPTION
  Without -From, downloads w365-<tag>.zip from the GitHub Release of Seckcey/8_west_suite_ui (needs
  gh signed in). With -From, copies dist/w365 from a local checkout. Every file is verified against
  w365-manifest.json before the destination is replaced. Fails closed on any mismatch.
#>
param(
  [Parameter(Mandatory = $true)][string]$Tag,
  [Parameter(Mandatory = $true)][string]$Dest,
  [string]$From = ''
)
$ErrorActionPreference = 'Stop'
$Repo = 'Seckcey/8_west_suite_ui'
if ($Tag -notmatch '^v\d+\.\d+\.\d+$') { throw 'tag must look like v0.1.0' }
$Version = $Tag.Substring(1)

$work = Join-Path ([System.IO.Path]::GetTempPath()) ("w365-sync-" + [guid]::NewGuid().ToString('n'))
New-Item -ItemType Directory -Path $work | Out-Null
try {
  $incoming = Join-Path $work 'w365'
  if ($From -ne '') {
    $src = Join-Path $From 'dist/w365'
    if (-not (Test-Path $src)) { throw "no dist/w365 under $From" }
    Copy-Item -Recurse -Path $src -Destination $incoming
  } else {
    gh release download $Tag -R $Repo -p "w365-$Tag.zip" -D $work
    if ($LASTEXITCODE -ne 0) { throw 'gh release download failed' }
    Expand-Archive -Path (Join-Path $work "w365-$Tag.zip") -DestinationPath $incoming
  }

  $manifestPath = Join-Path $incoming 'w365-manifest.json'
  if (-not (Test-Path $manifestPath)) { throw 'the package has no w365-manifest.json' }
  $manifest = Get-Content -Raw -Encoding UTF8 $manifestPath | ConvertFrom-Json
  if ($manifest.version -ne $Version) { throw "manifest version $($manifest.version) != requested $Version" }

  $listed = @{}
  foreach ($prop in $manifest.files.PSObject.Properties) { $listed[$prop.Name] = $prop.Value.sha256 }
  $present = Get-ChildItem -Recurse -File $incoming | ForEach-Object { $_.FullName.Substring($incoming.Length + 1).Replace('\', '/') } | Where-Object { $_ -ne 'w365-manifest.json' }
  foreach ($f in $present) { if (-not $listed.ContainsKey($f)) { throw "unexpected file not in manifest: $f" } }
  foreach ($f in $listed.Keys) {
    $p = Join-Path $incoming $f
    if (-not (Test-Path $p)) { throw "missing: $f" }
    $sha = (Get-FileHash -Algorithm SHA256 $p).Hash.ToLowerInvariant()
    if ($sha -ne $listed[$f]) { throw "sha256 mismatch: $f" }
  }
  Write-Host "verified w365 $($manifest.version): $($listed.Count) files"

  $parent = Split-Path -Parent $Dest
  if ($parent -and -not (Test-Path $parent)) { New-Item -ItemType Directory -Path $parent | Out-Null }
  $staged = Join-Path ($(if ($parent) { $parent } else { '.' })) ".w365-incoming-$PID"
  if (Test-Path $staged) { Remove-Item -Recurse -Force $staged }
  Copy-Item -Recurse -Path $incoming -Destination $staged
  if (Test-Path $Dest) {
    if (Test-Path "$Dest.previous") { Remove-Item -Recurse -Force "$Dest.previous" }
    Move-Item -Path $Dest -Destination "$Dest.previous"
  }
  Move-Item -Path $staged -Destination $Dest
  if (Test-Path "$Dest.previous") { Remove-Item -Recurse -Force "$Dest.previous" }
  $pin = (Get-FileHash -Algorithm SHA256 (Join-Path $Dest 'w365-manifest.json')).Hash.ToLowerInvariant()
  Write-Host "vendored w365 $Version into $Dest"
  Write-Host "manifest sha256: $pin  (pin this in the consumer guard test)"
} finally {
  Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}
