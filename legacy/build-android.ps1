param([switch]$Unsigned)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$socketDirectory = Join-Path $projectRoot 'tmp/java-sockets'
New-Item -ItemType Directory -Path $socketDirectory -Force | Out-Null
$previousOptions = $env:JAVA_TOOL_OPTIONS
try {
    $env:JAVA_TOOL_OPTIONS = ($previousOptions + ' "-Djdk.net.unixdomain.tmpdir=' + $socketDirectory + '"').Trim()
    Push-Location (Join-Path $projectRoot 'Android')
    try {
        $buildArgs = @(':app:bundleRelease', ':app:lintRelease', '--no-daemon', '--console=plain')
        if ($Unsigned) { $buildArgs += '-PtocaraulAllowUnsigned=true' }
        & .\gradlew.bat @buildArgs
        if ($LASTEXITCODE -ne 0) { throw "Build Android falhou: $LASTEXITCODE" }
    } finally { Pop-Location }
} finally { $env:JAVA_TOOL_OPTIONS = $previousOptions }
