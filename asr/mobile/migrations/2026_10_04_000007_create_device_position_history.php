<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('mapa_device_position_history',function(Blueprint $t){
            $t->bigIncrements('id');$t->uuid('device_id');$t->uuid('company_uuid');$t->uuid('vehicle_uuid');
            $t->double('latitude');$t->double('longitude');$t->double('accuracy_m');
            $t->unsignedBigInteger('recorded_at');$t->unsignedBigInteger('received_at');$t->timestamp('created_at');
            $t->unique(['device_id','recorded_at'],'mapa_device_history_fix');
            $t->index(['company_uuid','vehicle_uuid','recorded_at'],'mapa_device_history_vehicle');
        });
        Schema::create('mapa_vehicle_position_states',function(Blueprint $t){
            $t->uuid('company_uuid');$t->uuid('vehicle_uuid');$t->unsignedBigInteger('recorded_at');
            $t->string('source',32);$t->uuid('source_id')->nullable();$t->timestamps();
            $t->primary(['company_uuid','vehicle_uuid'],'mapa_vehicle_position_state');
        });
    }
    public function down(): void { Schema::dropIfExists('mapa_vehicle_position_states');Schema::dropIfExists('mapa_device_position_history'); }
};
