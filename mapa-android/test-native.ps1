param([string]$JavaDirectory)
$ErrorActionPreference='Stop'
$mapaWorkspace=Split-Path $PSScriptRoot -Parent
$mapaTools=Join-Path $mapaWorkspace 'tmp/android-tools'
if(!(Test-Path -LiteralPath $mapaTools)){$mapaTools=Join-Path (Split-Path $mapaWorkspace -Parent) 'ASR health/tmp/android-tools'}
$mapaJava=$(if($JavaDirectory){$JavaDirectory}else{(Get-ChildItem (Join-Path $mapaTools 'java') -Directory | Select-Object -First 1).FullName})
$mapaJson=Join-Path $PSScriptRoot 'tests/lib/json-20240303.jar'
$mapaTests=Join-Path $PSScriptRoot 'build/native-tests'
New-Item -ItemType Directory -Force $mapaTests | Out-Null
$mapaFiles=@('CompletionEvidence.java','EnrollmentState.java','NativeAlertPolicy.java','NativeDispatchOffers.java','RouteData.java','ServerAddress.java','NativeConnection.java','NativeProtocol.java','NativeHospitalPolicy.java','NativeUi.java','NativeTravel.java','NativeGpsState.java','ActionQueue.java') | ForEach-Object { Join-Path $PSScriptRoot ('src/in/asr/ambulance/'+$_) }
& "$mapaJava/bin/javac.exe" -encoding UTF-8 --release 8 -classpath $mapaJson -d $mapaTests @mapaFiles "$PSScriptRoot/tests/NativeContractTest.java" "$PSScriptRoot/tests/NativeUiTest.java" "$PSScriptRoot/tests/RouteDataTest.java" "$PSScriptRoot/tests/EnrollmentAndAlertsTest.java" "$PSScriptRoot/tests/CompletionQueueTest.java" "$PSScriptRoot/tests/NativeDispatchOffersTest.java" "$PSScriptRoot/tests/NativeArrivalsTest.java"
if($LASTEXITCODE -ne 0){throw 'Native contract test compilation failed'}
& "$mapaJava/bin/java.exe" -classpath "$mapaTests;$mapaJson" in.asr.ambulance.NativeContractTest
if($LASTEXITCODE -ne 0){throw 'Native MAPA contract tests failed'}
& "$mapaJava/bin/java.exe" -classpath "$mapaTests;$mapaJson" in.asr.ambulance.NativeUiTest
if($LASTEXITCODE -ne 0){throw 'Native crew presentation tests failed'}
& "$mapaJava/bin/java.exe" -classpath "$mapaTests;$mapaJson" in.asr.ambulance.RouteDataTest
if($LASTEXITCODE -ne 0){throw 'Route data tests failed'}

& "$mapaJava/bin/java.exe" -classpath "$mapaTests;$mapaJson" in.asr.ambulance.EnrollmentAndAlertsTest
if($LASTEXITCODE -ne 0){throw 'Enrollment and alert behavior tests failed'}
& "$mapaJava/bin/java.exe" -classpath "$mapaTests;$mapaJson" in.asr.ambulance.CompletionQueueTest
if($LASTEXITCODE -ne 0){throw 'Trip-end evidence queue tests failed'}

& "$mapaJava/bin/java.exe" -classpath "$mapaTests;$mapaJson" in.asr.ambulance.NativeDispatchOffersTest
if($LASTEXITCODE -ne 0){throw 'Automatic dispatch phone checks failed'}

& "$mapaJava/bin/java.exe" -classpath "$mapaTests;$mapaJson" in.asr.ambulance.NativeArrivalsTest
if($LASTEXITCODE -ne 0){throw 'Native arrival checks failed'}
