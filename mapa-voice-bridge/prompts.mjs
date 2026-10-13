// These fixed prompts collect logistics only. No generated clinical advice,
// diagnosis, promises of dispatch, or instructions from caller text are spoken.
export const PROMPTS = {
  en: [
    'You have reached MAPA ambulance assistance. I am the automated intake assistant. A dispatcher will confirm the details. Press 2 for Telugu, or press 0 for an operator at any time. Please tell me the pickup village or area and a nearby landmark.',
    'Please describe what happened and why an ambulance is needed. A dispatcher will assess the request.',
    'Please tell me your name and any other information that will help the crew find you.',
    'Thank you. Please stay on the line for a dispatcher to confirm your location and ambulance request. An ambulance has not yet been assigned.',
  ],
  te: [
    'మీరు MAPA అంబులెన్స్ సహాయ కేంద్రానికి కాల్ చేశారు. నేను ఆటోమేటెడ్ సహాయకుడిని. డిస్పాచర్ వివరాలను నిర్ధారిస్తారు. ఇంగ్లీష్ కోసం 1 నొక్కండి. ఆపరేటర్ కోసం ఎప్పుడైనా 0 నొక్కండి. అంబులెన్స్ రావాల్సిన ఊరు లేదా ప్రాంతం, దగ్గరలోని గుర్తు చెప్పండి.',
    'ఏమి జరిగింది? అంబులెన్స్ ఎందుకు అవసరమో చెప్పండి. డిస్పాచర్ మీ అభ్యర్థనను పరిశీలిస్తారు.',
    'మీ పేరు చెప్పండి. సిబ్బంది మిమ్మల్ని సులభంగా గుర్తించేందుకు ఇంకేమైనా వివరాలు ఉంటే చెప్పండి.',
    'ధన్యవాదాలు. మీ చిరునామా, అంబులెన్స్ అభ్యర్థనను నిర్ధారించేందుకు డిస్పాచర్‌తో మాట్లాడండి. దయచేసి లైన్‌లో ఉండండి. అంబులెన్స్ ఇంకా కేటాయించలేదు.',
  ],
};
export function answerDraft(stage, text) {
  return stage === 0 ? { pickup_address: text.slice(0, 1000) }
    : stage === 1 ? { reported_problem: text.slice(0, 2000) } : {};
}
