param(
  [string]$JavaDirectory,
  [string]$BuildToolsDirectory,
  [string]$PlatformDirectory,
  [string]$KeyStorePath
)
$ErrorActionPreference='Stop'
$asrRoot=$PSScriptRoot
$asrWorkspace=Split-Path $asrRoot -Parent
$mapaToolRoot=Join-Path $asrWorkspace 'tmp/android-tools'
# This retained toolchain signs the existing installed MAPA package. Keep the key outside source bundles.
if(!(Test-Path -LiteralPath $mapaToolRoot)) {
  $mapaRetainedTools=Join-Path (Split-Path $asrWorkspace -Parent) 'ASR health/tmp/android-tools'
  if(Test-Path -LiteralPath $mapaRetainedTools){$mapaToolRoot=$mapaRetainedTools}
}
if (!$JavaDirectory) { $JavaDirectory=(Get-ChildItem (Join-Path $mapaToolRoot 'java') -Directory | Select-Object -First 1).FullName }
if (!$BuildToolsDirectory) { $BuildToolsDirectory=Join-Path $mapaToolRoot 'build-tools/android-15' }
if (!$PlatformDirectory) { $PlatformDirectory=Join-Path $mapaToolRoot 'platform/android-35' }
if (!$KeyStorePath) { $KeyStorePath=Join-Path $mapaToolRoot 'asr-pilot.keystore' }
foreach($mapaRequired in @("$JavaDirectory/bin/javac.exe","$JavaDirectory/bin/java.exe","$JavaDirectory/bin/jar.exe","$JavaDirectory/bin/keytool.exe","$BuildToolsDirectory/aapt2.exe","$BuildToolsDirectory/zipalign.exe","$BuildToolsDirectory/lib/d8.jar","$BuildToolsDirectory/lib/apksigner.jar","$PlatformDirectory/android.jar")) {
  if(!(Test-Path -LiteralPath $mapaRequired -PathType Leaf)){throw "Required Android tool missing: $mapaRequired. Supply the documented toolchain parameters."}
}
if(!(Test-Path -LiteralPath $KeyStorePath -PathType Leaf)){throw 'Existing MAPA signing key is missing. Build stopped; a replacement key would break installed-app upgrades.'}
$mapaSignerExpected='6fa24fd0e67495eac8023c36ea2d2ee64faf6b5f040fd63c10f38e09048a4fea'
$mapaKeyDescription=(& "$JavaDirectory/bin/keytool.exe" -list -v -keystore $KeyStorePath -storepass android -alias asr-pilot 2>&1 | Out-String)
if($LASTEXITCODE -ne 0){throw 'Unable to inspect existing MAPA signing identity.'}
$mapaFingerprint=[regex]::Match($mapaKeyDescription,'SHA256:\s*([A-Fa-f0-9:]+)').Groups[1].Value.Replace(':','').ToLowerInvariant()
if($mapaFingerprint -ne $mapaSignerExpected){throw 'Signing certificate does not match the installed MAPA pilot. Build stopped without replacing the key.'}
$asrOut=Join-Path $asrRoot 'build'
$asrDelivery=Join-Path $asrRoot 'outputs'
$mapaClasses=[IO.Path]::GetFullPath((Join-Path $asrOut 'classes'))
$mapaExpected=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot 'build\classes'))
if($mapaClasses -ne $mapaExpected -or !$mapaClasses.StartsWith([IO.Path]::GetFullPath($PSScriptRoot)+[IO.Path]::DirectorySeparatorChar)){throw 'Unexpected build class directory'}
if(Test-Path -LiteralPath $mapaClasses){Remove-Item -LiteralPath $mapaClasses -Recurse -Force}
New-Item -ItemType Directory -Force $asrOut,"$asrOut/classes","$asrOut/generated","$asrOut/dex",$asrDelivery | Out-Null
function Run-AsrTool([string]$tool,[string[]]$arguments) { & $tool @arguments; if($LASTEXITCODE -ne 0){throw "Build command failed: $tool (exit $LASTEXITCODE)"} }
Run-AsrTool "$BuildToolsDirectory/aapt2.exe" @('compile','--dir',"$asrRoot/res",'-o',"$asrOut/resources.zip")
Run-AsrTool "$BuildToolsDirectory/aapt2.exe" @('link','-o',"$asrOut/resources.apk",'-I',"$PlatformDirectory/android.jar",'--manifest',"$asrRoot/AndroidManifest.xml",'--java',"$asrOut/generated",'--auto-add-overlay',"$asrOut/resources.zip")
$mapaActiveFiles=@('CompletionEvidence.java','EnrollmentState.java','NativeEnrollment.java','NativePhoto.java','NativeAlertPolicy.java','NativeDispatchOffers.java','NativeAlerts.java','NativePush.java','NativePushPolicy.java','NavigationActivity.java','RouteData.java','RouteClient.java','NativeConnection.java','NativeProtocol.java','NativeHospitalPolicy.java','NativeUi.java','NativeTravel.java','NativeGpsState.java','NativeApi.java','NativeMainActivity.java','NativeDutyService.java','NativeRecoveryReceiver.java','NativeOutbox.java','ServerAddress.java','PrivateStore.java','ActionQueue.java','TripMap.java','PhotoProvider.java','R.java')
$asrSources=Get-ChildItem "$asrRoot/src","$asrOut/generated" -Filter '*.java' -Recurse | Where-Object { $_.Name -in $mapaActiveFiles } | ForEach-Object { '"'+$_.FullName.Replace('\','/')+'"' }
[IO.File]::WriteAllLines("$asrOut/sources.txt",$asrSources,[Text.UTF8Encoding]::new($false))
Run-AsrTool "$JavaDirectory/bin/javac.exe" @('-encoding','UTF-8','--release','8','-classpath',"$PlatformDirectory/android.jar",'-d',"$asrOut/classes","@$asrOut/sources.txt")
Run-AsrTool "$JavaDirectory/bin/jar.exe" @('cf',"$asrOut/classes.jar",'-C',"$asrOut/classes",'.')
Run-AsrTool "$JavaDirectory/bin/java.exe" @('-cp',"$BuildToolsDirectory/lib/d8.jar",'com.android.tools.r8.D8','--min-api','26','--lib',"$PlatformDirectory/android.jar",'--output',"$asrOut/dex","$asrOut/classes.jar")
Copy-Item -LiteralPath "$asrOut/resources.apk" -Destination "$asrOut/unsigned.apk" -Force
Run-AsrTool "$JavaDirectory/bin/jar.exe" @('uf',"$asrOut/unsigned.apk",'-C',"$asrOut/dex",'classes.dex')
Run-AsrTool "$BuildToolsDirectory/zipalign.exe" @('-f','-p','4',"$asrOut/unsigned.apk","$asrOut/aligned.apk")
$asrApk=Join-Path $asrDelivery 'MAPA-Console-Android-pilot.apk'
Run-AsrTool "$JavaDirectory/bin/java.exe" @('-jar',"$BuildToolsDirectory/lib/apksigner.jar",'sign','--ks',$KeyStorePath,'--ks-key-alias','asr-pilot','--ks-pass','pass:android','--key-pass','pass:android','--out',$asrApk,"$asrOut/aligned.apk")
Run-AsrTool "$JavaDirectory/bin/java.exe" @('-jar',"$BuildToolsDirectory/lib/apksigner.jar",'verify','--verbose','--print-certs',$asrApk)
Run-AsrTool "$BuildToolsDirectory/aapt2.exe" @('dump','badging',$asrApk)
$asrHash=Get-FileHash -LiteralPath $asrApk -Algorithm SHA256
[IO.File]::WriteAllText("$asrDelivery/SHA256.txt",$asrHash.Hash.ToLower()+'  MAPA-Console-Android-pilot.apk'+[Environment]::NewLine)
Write-Output "APK ready: $asrApk"

