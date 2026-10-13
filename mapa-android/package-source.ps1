$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$mapaBase=[IO.Path]::GetFullPath($PSScriptRoot)
$mapaOutput=Join-Path $mapaBase 'outputs'
New-Item -ItemType Directory -Force $mapaOutput | Out-Null
$mapaArchivePath=Join-Path $mapaOutput 'MAPA-Console-Android-Source.zip'
$mapaActive=@('CompletionEvidence.java','EnrollmentState.java','NativeEnrollment.java','NativePhoto.java','NativeAlertPolicy.java','NativeDispatchOffers.java','NativeAlerts.java','NativePush.java','NativePushPolicy.java','NavigationActivity.java','RouteData.java','RouteClient.java','NativeConnection.java','NativeProtocol.java','NativeHospitalPolicy.java','NativeUi.java','NativeTravel.java','NativeGpsState.java','NativeApi.java','NativeMainActivity.java','NativeDutyService.java','NativeRecoveryReceiver.java','NativeOutbox.java','ServerAddress.java','PrivateStore.java','ActionQueue.java','TripMap.java','PhotoProvider.java')
# Source, build instructions and synthetic tests only. Private release reports stay outside this archive.
$mapaNames=@('package-crew-trial.py','CREW-DETAILED-GUIDE.html','LIVE-READINESS.md','SELF-REGISTRATION-GUIDE.html','tests/EnrollmentAndAlertsTest.java','tests/CompletionQueueTest.java','tests/NativeDispatchOffersTest.java','tests/NativeArrivalsTest.java','fcm/BUILD.txt','fcm/AndroidManifest.xml','fcm/settings.gradle','fcm/build.gradle','fcm/gradle.properties','fcm/src/in/asr/ambulance/NativeFcmClient.java','fcm/src/in/asr/ambulance/NativeMessagingService.java','tests/NativePushPolicyTest.java','AndroidManifest.xml','build.ps1','test-native.ps1','test-reliability.ps1','package-source.ps1','README.md','NATIVE-ACCEPTANCE.md','MAPA-ANDROID-SETUP.md','tests/NativeContractTest.java','tests/map-render.cjs','tests/RouteDataTest.java','tests/NativeUiTest.java','tests/ActionQueueTest.java','tests/DraftGuard.java','tests/lib/README.txt')
$mapaNames+=($mapaActive | ForEach-Object {'src/in/asr/ambulance/'+$_})
$mapaFiles=@($mapaNames | ForEach-Object {Get-Item -LiteralPath (Join-Path $mapaBase $_)})
$mapaFiles+=Get-ChildItem -LiteralPath (Join-Path $mapaBase 'res') -Recurse -File | Where-Object {$_.Extension -in '.xml','.js','.css','.txt' -and $_.Name -ne 'ic_asr.xml'}
$mapaStream=[IO.File]::Open($mapaArchivePath,[IO.FileMode]::Create)
$mapaZip=[IO.Compression.ZipArchive]::new($mapaStream,[IO.Compression.ZipArchiveMode]::Create)
try {
  foreach($mapaFile in $mapaFiles){
    $mapaRelative=$mapaFile.FullName.Substring($mapaBase.Length+1).Replace('\','/')
    if($mapaRelative -match '(^|/)(private|history|build|outputs|node_modules|tmp|\.git|\.env)(/|$)|(^|/)[^/]*(BUILD-REPORT|VALIDATION)[^/]*$|(^|/)(google-services|service-account)\.json$|\.(keystore|jks|p12|pem|jar|apk)$'){throw 'Unexpected private, legacy or generated archive member'}
    [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($mapaZip,$mapaFile.FullName,'MAPA-Console-Android/'+$mapaRelative,[IO.Compression.CompressionLevel]::Optimal)|Out-Null
  }
}finally{$mapaZip.Dispose();$mapaStream.Dispose()}
Write-Output ('Native source archive: '+$mapaArchivePath+' ('+$mapaFiles.Count+' allowlisted files)')


