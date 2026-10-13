param([string]$JavaDirectory)
$ErrorActionPreference='Stop'
$asrWorkspace=Split-Path $PSScriptRoot -Parent
$mapaTools=Join-Path $asrWorkspace 'tmp/android-tools'
if(!(Test-Path -LiteralPath $mapaTools)){$mapaTools=Join-Path (Split-Path $asrWorkspace -Parent) 'ASR health/tmp/android-tools'}
$asrJava=$(if($JavaDirectory){$JavaDirectory}else{(Get-ChildItem (Join-Path $mapaTools 'java') -Directory | Select-Object -First 1).FullName})
$asrJson=Join-Path $PSScriptRoot 'tests/lib/json-20240303.jar'
$asrTests=Join-Path $PSScriptRoot 'build/reliability-tests'
New-Item -ItemType Directory -Force $asrTests | Out-Null
& "$asrJava/bin/javac.exe" -encoding UTF-8 --release 8 -classpath $asrJson -d $asrTests "$PSScriptRoot/src/in/asr/ambulance/ActionQueue.java" "$PSScriptRoot/tests/DraftGuard.java" "$PSScriptRoot/tests/ActionQueueTest.java"
if($LASTEXITCODE -ne 0){throw 'Reliability test compilation failed'}
& "$asrJava/bin/java.exe" -classpath "$asrTests;$asrJson" in.asr.ambulance.ActionQueueTest
if($LASTEXITCODE -ne 0){throw 'Reliability tests failed'}
