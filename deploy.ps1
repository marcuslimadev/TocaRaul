<#
.SYNOPSIS
Publica o TocaRaul no servidor remoto a partir da branch main.

.DESCRIPTION
O script valida, versiona e envia a branch main para o GitHub e atualiza o
checkout remoto via SSH/rsync. A senha nunca é gravada neste arquivo: ela é
lida de TOCARAUL_SSH_PASSWORD (ou BAUHAUS_SSH_PASSWORD) no .env.local.

.EXAMPLE
  .\deploy.ps1 -DeployPath '/var/www/tocaraul' -DeployRepoPath '/var/www/.tocaraul-git'

.EXAMPLE
  .\deploy.ps1 -RemoteOnly -DeployPath '/var/www/tocaraul' -HealthUrl 'https://tocaraul.lojadaesquina.store/api/health'
#>
[CmdletBinding()]
param(
  [string]$CommitMessage = "chore: deploy TocaRaul $(Get-Date -Format 'yyyy-MM-dd HH:mm')",
  [string]$DeployPath,
  [string]$DeployRepoPath,
  [string]$HealthUrl = 'https://tocaraul.lojadaesquina.store/api/health',
  [string]$SshHost = '179.199.129.224',
  [int]$SshPort = 22,
  [string]$SshUser = 'root',
  [string]$SshHostKey = 'ssh-ed25519 255 SHA256:jDm0EETU3mnrAT/lxjiunu3CJLeQf8iDBSHKlMWpt9s',
  [string]$Branch = 'main',
  [switch]$SkipCommit,
  [switch]$RemoteOnly,
  [switch]$Force
)

$ErrorActionPreference = 'Stop'
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$ProjectRoot = $PSScriptRoot

function Step([string]$Message) { Write-Host "`n==> $Message" -ForegroundColor Cyan }
function AssertExit([string]$Action) { if ($LASTEXITCODE -ne 0) { throw "$Action falhou (código $LASTEXITCODE)." } }
function EnvValue([string]$Name) {
  $file = Join-Path $ProjectRoot '.env.local'
  if (-not (Test-Path -LiteralPath $file)) { return $null }
  $line = Get-Content -LiteralPath $file | Where-Object { $_ -match "^\s*$([regex]::Escape($Name))\s*=" } | Select-Object -Last 1
  if (-not $line) { return $null }
  $value = ($line -replace "^\s*$([regex]::Escape($Name))\s*=\s*", '').Trim()
  if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) { $value = $value.Substring(1, $value.Length - 2) }
  return $value
}
function Bash([string]$Value) { return "'" + $Value.Replace("'", "'`"'`"'") + "'" }
function OriginSshUrl {
  $url = (& git remote get-url origin).Trim(); AssertExit 'Leitura do remote origin'
  if ($url -match '^git@github\.com:(.+)$') { return "https://github.com/$($Matches[1])" }
  if ($url -match '^https://github\.com/.+$') { return $url }
  throw "Remote origin não suportado: $url"
}

if ([string]::IsNullOrWhiteSpace($DeployPath)) { $DeployPath = EnvValue 'TOCARAUL_DEPLOY_PATH' }
if ([string]::IsNullOrWhiteSpace($DeployPath)) { throw 'Informe -DeployPath ou TOCARAUL_DEPLOY_PATH no .env.local.' }
if ([string]::IsNullOrWhiteSpace($DeployRepoPath)) { $DeployRepoPath = EnvValue 'TOCARAUL_DEPLOY_REPO_PATH' }
if ([string]::IsNullOrWhiteSpace($DeployRepoPath)) { throw 'Informe -DeployRepoPath ou TOCARAUL_DEPLOY_REPO_PATH no .env.local.' }
$Password = EnvValue 'TOCARAUL_SSH_PASSWORD'
if ([string]::IsNullOrWhiteSpace($Password)) { $Password = EnvValue 'BAUHAUS_SSH_PASSWORD' }
if ([string]::IsNullOrWhiteSpace($Password)) {
  $sshLine = Get-Content -LiteralPath (Join-Path $ProjectRoot '.env.local') | Where-Object { $_ -match '^\s*ssh\s+-p\s+\d+\s+\S+\s+\S+\s*$' } | Select-Object -Last 1
  if ($sshLine -and $sshLine -match '^\s*ssh\s+-p\s+(\d+)\s+([^@\s]+)@([^\s]+)\s+(\S+)\s*$') {
    $SshPort = [int]$Matches[1]
    $SshUser = $Matches[2]
    $SshHost = $Matches[3]
    $Password = $Matches[4]
  }
}
if ([string]::IsNullOrWhiteSpace($Password)) { throw 'Defina TOCARAUL_SSH_PASSWORD no .env.local.' }

$plink = @(
  (Join-Path ${env:ProgramFiles} 'PuTTY\plink.exe'),
  (Join-Path ${env:ProgramFiles(x86)} 'PuTTY\plink.exe'),
  (Join-Path ${env:LOCALAPPDATA} 'PuTTY\plink.exe')
) | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
if (-not $plink) { throw 'PuTTY plink.exe não foi encontrado.' }
$hostKey = $SshHostKey

try {
  if (-not $RemoteOnly) {
    Step 'Validando o projeto'
    & git diff --check; AssertExit 'Verificação de formatação do Git'
    if (-not $SkipCommit) {
      & git fetch origin $Branch; AssertExit 'Atualização das referências remotas'
      $behind = [int](& git rev-list --count "HEAD..origin/$Branch"); AssertExit 'Verificação da branch remota'
      if ($behind -gt 0 -and -not $Force) { throw "A branch local está $behind commit(s) atrás de origin/$Branch. Use -Force conscientemente." }
      & git add -A; AssertExit 'Preparação das alterações'
      & git diff --cached --quiet
      if ($LASTEXITCODE -eq 1) { & git commit -m $CommitMessage; AssertExit 'Commit do deploy' }
      elseif ($LASTEXITCODE -gt 1) { throw 'Não foi possível verificar as alterações preparadas.' }
      & git push origin $Branch; AssertExit 'Envio para o GitHub'
    }
  }

  Step 'Publicando no servidor remoto'
  $repoUrl = Bash (OriginSshUrl)
  $remotePath = Bash $DeployPath
  $repoPath = Bash $DeployRepoPath
  $branchName = Bash $Branch
  $remoteScript = @"
set -euo pipefail
deploy_path=$remotePath
repo_path=$repoPath
repo_url=$repoUrl
branch=$branchName
command -v git >/dev/null
command -v rsync >/dev/null
mkdir -p "`$(dirname "`$repo_path")"
if [ ! -d "`$repo_path/.git" ]; then git clone --branch "`$branch" "`$repo_url" "`$repo_path"; else git -C "`$repo_path" fetch origin "`$branch"; git -C "`$repo_path" checkout -B "`$branch" "origin/`$branch"; git -C "`$repo_path" reset --hard "origin/`$branch"; fi
mkdir -p "`$deploy_path"
rsync -a --delete --exclude '.git/' --exclude '.env*' "`$repo_path/" "`$deploy_path/"
printf '%s\n' "`$(git -C "`$repo_path" rev-parse HEAD)" > "`$deploy_path/.tocaraul-release"
echo "Deploy concluído: `$(cat "`$deploy_path/.tocaraul-release")"
"@
  ($remoteScript -replace "`r`n", "`n") | & $plink -batch -P $SshPort -hostkey $hostKey -pw $Password "$SshUser@$SshHost" 'bash -s'
  AssertExit 'Deploy remoto'

  Step 'Verificando a aplicação publicada'
  $health = Invoke-WebRequest -Uri $HealthUrl -UseBasicParsing -TimeoutSec 20
  if ($health.StatusCode -lt 200 -or $health.StatusCode -ge 400) { throw "Health check retornou HTTP $($health.StatusCode)." }
  Write-Host "Health check OK: HTTP $($health.StatusCode)" -ForegroundColor Green
}
catch { Write-Host "`nFalha no deploy: $($_.Exception.Message)" -ForegroundColor Red; exit 1 }
finally { $Password = $null }
