<?php
namespace App\MapaText;

/** Caller-facing copy. Keep the private URL separate from stored message bodies. */
final class TrackerMessage {
 public const LINK_PLACEHOLDER='{{1}}';

 public static function templateBody(): string {
  return "MAPA ambulance support: If you agree, open the private link below. Allow location access on your phone and confirm where the ambulance should pick you up.\n\n"
   ."MAPA అంబులెన్స్ సహాయం: మీరు అంగీకరిస్తే, కింద ఉన్న వ్యక్తిగత లింక్‌ను తెరవండి. మీ ఫోన్‌లో స్థాన అనుమతి ఇచ్చి, అంబులెన్స్ మిమ్మల్ని ఎక్కడి నుంచి తీసుకెళ్లాలో నిర్ధారించండి.\n\n"
   .self::LINK_PLACEHOLDER."\n\n"
   ."Do not forward this private link. / ఈ వ్యక్తిగత లింక్‌ను ఇతరులకు పంపవద్దు.";
 }

 public static function withLink(string $url): string {
  return str_replace(self::LINK_PLACEHOLDER,$url,self::templateBody());
 }

 public static function stage(string $key): string {
  [$english,$telugu]=match($key){
   'ack'=>['Your MAPA ambulance request has been recorded. Open the private tracker link sent with this message and confirm your pickup point.','మీ MAPA అంబులెన్స్ అభ్యర్థన నమోదైంది. ఈ సందేశంతో పంపిన వ్యక్తిగత ట్రాకర్ లింక్‌ను తెరిచి, పికప్ స్థలాన్ని నిర్ధారించండి.'],
   'accepted'=>['An ambulance crew has accepted your request.','అంబులెన్స్ సిబ్బంది మీ అభ్యర్థనను అంగీకరించారు.'],
   'pickup_arrived'=>['The ambulance has arrived at the pickup point.','అంబులెన్స్ పికప్ స్థలానికి చేరుకుంది.'],
   'patient_onboard'=>['The patient is in the ambulance.','రోగి అంబులెన్స్‌లో ఉన్నారు.'],
   'hospital_arrived'=>['The ambulance has arrived at the hospital.','అంబులెన్స్ ఆసుపత్రికి చేరుకుంది.'],
   'completed'=>['The ambulance trip has been completed.','అంబులెన్స్ ప్రయాణం పూర్తయింది.'],
   'cancelled'=>['The ambulance request was closed by the dispatcher.','డిస్పాచర్ అంబులెన్స్ అభ్యర్థనను ముగించారు.'],
   default=>throw new \InvalidArgumentException('Unknown tracker message stage.'),
  };
  return $english."\n".$telugu;
 }
}
