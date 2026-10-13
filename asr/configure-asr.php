<?php
// Run inside the API container; only touches the configured ASR company.
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$company = Fleetbase\Models\Company::where('uuid', config('asr.company_uuid'))->firstOrFail();
if ($company->name !== 'ASR Health') { throw new RuntimeException('Unexpected organization'); }
$config = Fleetbase\FleetOps\Support\FleetOps::createTransportConfig($company);
$backup = storage_path('app/asr-transport-config-before-customization.json');
if (!file_exists($backup)) { file_put_contents($backup, $config->toJson(JSON_PRETTY_PRINT)); }
Illuminate\Support\Facades\DB::transaction(function () use ($company, $config) {
    $flow = json_decode(json_encode($config->flow), true);
    $labels = [
        'created' => ['Call received', 'Ambulance transport call recorded.'],
        'dispatched' => ['Ambulance dispatched', 'Assigned driver has been dispatched.'],
        'started' => ['Response started', 'Ambulance response has started.'],
        'enroute' => ['Transport in progress', 'Transport to the receiving destination is in progress.'],
        'completed' => ['Hospital handover complete', 'Transport and receiving hospital handover completed.'],
    ];
    foreach ($labels as $key => [$label, $details]) {
        if (!isset($flow[$key])) { throw new RuntimeException('Unexpected transport workflow'); }
        $flow[$key]['status'] = $label;
        $flow[$key]['details'] = $details;
    }
    $config->update(['name' => 'ASR Ambulance Response', 'description' => 'ASR Health district ambulance dispatch and hospital handover', 'tags' => ['asr', 'ambulance'], 'flow' => $flow]);
    $group = Fleetbase\Models\Category::firstOrCreate([
        'company_uuid' => $company->uuid, 'owner_uuid' => $config->uuid, 'for' => 'custom_field_group', 'name' => 'ASR dispatch & handover',
    ], ['owner_type' => 'order-config', 'meta' => ['grid_size' => 2]]);
    $fields = [
        ['asr_call_reference', 'ASR call reference', 'input', [], 'Operational reference number.'],
        ['asr_priority', 'Dispatch priority', 'select', ['Emergency', 'Urgent', 'Scheduled transfer'], 'Set by the dispatcher using ASR procedures; this does not automate clinical triage.'],
        ['asr_mandal', 'Response mandal', 'select', ['Paderu', 'Araku Valley', 'Ananthagiri', 'Dumbriguda', 'Hukumpeta', 'Pedabayalu', 'Munchingiputtu', 'G. Madugula', 'Chintapalli', 'G. K. Veedhi', 'Koyyuru'], 'Select the response location.'],
        ['asr_crew_readiness', 'Crew readiness', 'select', ['Pending confirmation', 'Confirmed ready', 'Unavailable'], 'Confirm driver and clinical crew availability before dispatch.'],
        ['asr_crew_reference', 'Crew / team reference', 'input', [], 'Use the ASR team identifier. Assign the actual driver in call details.'],
        ['asr_handover', 'Hospital handover', 'select', ['Not started', 'Receiving hospital contacted', 'Arrived at hospital', 'Handover complete'], 'Record the confirmed handover stage.'],
        ['asr_handover_reference', 'Handover reference', 'input', [], 'Receiving team or handover reference; avoid patient clinical details.'],
    ];
    foreach ($fields as $index => [$name, $label, $component, $options, $help]) {
        Fleetbase\Models\CustomField::firstOrCreate([
            'company_uuid' => $company->uuid, 'subject_uuid' => $config->uuid, 'name' => $name,
        ], ['subject_type' => 'order-config', 'category_uuid' => $group->uuid, 'label' => $label, 'type' => $component, 'component' => $component, 'options' => $options, 'required' => false, 'editable' => true, 'help_text' => $help, 'order' => $index]);
    }
});
echo json_encode(['organization' => $company->name, 'workflow' => $config->fresh()->name, 'custom_fields' => Fleetbase\Models\CustomField::where('subject_uuid', $config->uuid)->count(), 'backup' => $backup], JSON_PRETTY_PRINT) . PHP_EOL;
