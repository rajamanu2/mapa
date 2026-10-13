# MAPA caller tracker: English and Telugu

The caller-facing `/track/` page offers English and తెలుగు. It defaults to Telugu when the phone's browser language is Telugu; the caller can switch languages at any time. The link token stays in the URL fragment and is not copied into language settings or sent to a translation service.

For the existing New Call **Send tracker & get location** action, create and obtain Meta approval for a WhatsApp template whose body is exactly the text returned by `TrackerMessage::templateBody()` below. It has **one body variable**, `{{1}}`, for the HTTPS private tracker link. Do not put patient details in the template. Configure `MAPA_WHATSAPP_TEMPLATE` and `MAPA_WHATSAPP_LANGUAGE` to the approved name and language code only after Meta approval. The application cannot change the body of an approved Meta template at send time. A bilingual template can use the approved English language code because both languages are in the same body; verify the approved language setting in Meta before changing production configuration.

> MAPA ambulance support: If you agree, open the private link below. Allow location access on your phone and confirm where the ambulance should pick you up.
>
> MAPA అంబులెన్స్ సహాయం: మీరు అంగీకరిస్తే, కింద ఉన్న వ్యక్తిగత లింక్‌ను తెరవండి. మీ ఫోన్‌లో స్థాన అనుమతి ఇచ్చి, అంబులెన్స్ మిమ్మల్ని ఎక్కడి నుంచి తీసుకెళ్లాలో నిర్ధారించండి.
>
> {{1}}
>
> Do not forward this private link. / ఈ వ్యక్తిగత లింక్‌ను ఇతరులకు పంపవద్దు.

The separate MAPA WhatsApp Integration outbox queues English and Telugu stage text through `TrackerMessage::stage()`. Its WAHA/n8n sender remains disabled in production. This release does not pair a phone, enable sending, or send a message.
