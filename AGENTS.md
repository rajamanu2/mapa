# MAPA product conventions

The user has renamed this locally customized product to **MAPA**. ASR Health is the operating organization, not the software name.

- Use MAPA for new UI, first-party integration modules, public API contracts, launchers and documentation.
- Keep deployed compatibility aliases working until all consumers migrate. Do not destructively rename persistent databases or volumes to remove a word.
- Preserve upstream third-party package namespaces, upstream source paths and license/copyright notices. Track these as dependency or compatibility references, not MAPA product branding.
- All synthetic demo records must use an unmistakable DEMO name and `meta.mapa_demo=true`. Do not describe simulated fixes as live GPS, or send demo notifications to real people.
- The selected MAPA product is this console at localhost:4200 and its native API. The user explicitly stopped the separate `ambulance-command` interface at port 8791; do not develop, launch, or package it as MAPA. The unrelated `fruit-fly-lab` simulator is outside this task.
- Canonical integration: `/mapa/v1/fleet`, `mapa-v1`, `mapa_position_created_at`, `MAPA_ADAPTER_TOKEN`, `MAPA_COMPANY_UUID`. `/asr/v1/fleet` retains its original wire contract for compatibility.
