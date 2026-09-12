<#
.SYNOPSIS
Publica o TocaRaul (PHP) no public_html do Hostinger via SFTP.

.DESCRIPTION
Copia deploy/hostinger/tocaraul-api/ para o servidor. Nunca envia api_config.php,
que guarda os segredos de producao e vive fora do public_html.
A senha vem de TOCARAUL_SSH_PASSWORD no .env.local.

.EXAMPLE
  powershell -File ./deploy.ps1
  powershell -File ./deploy.ps1 -Only owner.php,assets/player.js
  powershell -File ./deploy.ps1 -DryRun
#>
[CmdletBinding()]
param(
  [string[]]$Only,
  [switch]$DryRun,
  [string]$SshHost = '145.223.105.168',
  [int]$Port = 65002,
  [string]$User = 'u815655858',
  [string]$RemoteBase = '/home/u815655858/domains/tocaraul.lojadaesquina.store/public_html'
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$localDir = Join-Path $root 'deploy/hostinger/tocaraul-api'
if (-not (Test-Path $localDir)) { throw "Origem nao encontrada: $localDir" }

$password = $env:TOCARAUL_SSH_PASSWORD
if (-not $password) {
  $envFile = Join-Path $root '.env.local'
  if (Test-Path $envFile) {
    $line = Select-String -Path $envFile -Pattern '^TOCARAUL_SSH_PASSWORD=(.+)$' | Select-Object -First 1
    if ($line) { $password = $line.Matches[0].Groups[1].Value.Trim() }
  }
}
if (-not $password) { throw 'Defina TOCARAUL_SSH_PASSWORD no .env.local ou no ambiente.' }

# api_config.php mora fora do public_html: enviar seria sobrescrever os segredos de producao.
# assets/logos guarda as logos que os bares enviaram pelo painel: e conteudo do servidor, nao do repo.
$files = Get-ChildItem $localDir -Recurse -File |
  Where-Object { $_.Name -ne 'api_config.php' } |
  ForEach-Object { $_.FullName.Substring($localDir.Length + 1).Replace('\', '/') } |
  Where-Object { -not $_.StartsWith('assets/logos/') }

if ($Only) { $files = $files | Where-Object { $Only -contains $_ } }
if (-not $files) { throw 'Nenhum arquivo a enviar.' }

Write-Host "$($files.Count) arquivo(s) para $RemoteBase" -ForegroundColor Cyan
if ($DryRun) { $files | ForEach-Object { Write-Host "  [dry-run] $_" }; return }

Import-Module Posh-SSH -ErrorAction Stop
$secure = ConvertTo-SecureString $password -AsPlainText -Force
$cred = New-Object System.Management.Automation.PSCredential($User, $secure)
$sftp = New-SFTPSession -ComputerName $SshHost -Port $Port -Credential $cred -AcceptKey -Force

try {
  foreach ($rel in $files) {
    $remoteDir = $RemoteBase
    if ($rel.Contains('/')) {
      $remoteDir = "$RemoteBase/" + ($rel -replace '/[^/]+$', '')
      if (-not (Test-SFTPPath -SFTPSession $sftp -Path $remoteDir)) {
        New-SFTPItem -SFTPSession $sftp -Path $remoteDir -ItemType Directory | Out-Null
      }
    }
    Set-SFTPItem -SFTPSession $sftp -Destination $remoteDir -Path (Join-Path $localDir $rel) -Force
    Write-Host "  enviado $rel" -ForegroundColor DarkGray
  }
} finally {
  Remove-SFTPSession -SFTPSession $sftp | Out-Null
}

Write-Host 'Verificando /api/health...' -ForegroundColor Cyan
try {
  $health = Invoke-RestMethod -Uri 'https://tocaraul.lojadaesquina.store/api/health' -TimeoutSec 20
  Write-Host ("  ok={0} database={1} paymentConfigured={2}" -f $health.ok, $health.database, $health.paymentConfigured) -ForegroundColor Green
} catch {
  Write-Warning "Nao consegui ler /api/health: $_"
}
