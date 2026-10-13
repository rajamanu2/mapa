<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('mapa_source_journeys',function(Blueprint $t){
            $t->uuid('journey_id')->primary();$t->uuid('company_uuid');$t->uuid('order_uuid')->nullable()->unique();
            $t->uuid('driver_uuid');$t->uuid('vehicle_uuid');$t->string('source_driver_id',10);$t->string('source_ambulance_id',10);
            $t->unsignedInteger('driver_link_revision');$t->unsignedInteger('vehicle_link_revision');
            $t->uuid('actor_uuid');$t->uuid('binding_id');$t->unsignedInteger('binding_revision');$t->string('intent_hash',64);
            $t->json('reserve_command');$t->string('state',24);$t->json('reservation')->nullable();$t->timestamp('terminal_at')->nullable();$t->timestamps();
            $t->index(['company_uuid','vehicle_uuid','terminal_at'],'mapa_source_journey_vehicle');$t->index(['company_uuid','driver_uuid','terminal_at'],'mapa_source_journey_driver');
        });
        Schema::create('mapa_source_dispatch_outbox',function(Blueprint $t){
            $t->uuid('id')->primary();$t->uuid('company_uuid');$t->uuid('journey_id');$t->unsignedInteger('sequence');
            $t->uuid('actor_uuid');$t->uuid('binding_id');$t->unsignedInteger('binding_revision');$t->string('native_action_id',100);$t->string('native_status',24);
            $t->json('command');$t->string('state',24);$t->json('receipt')->nullable();$t->unsignedInteger('attempts')->default(0);$t->timestamps();
            $t->unique(['journey_id','sequence'],'mapa_source_outbox_sequence');$t->index(['state','created_at'],'mapa_source_outbox_pending');
        });
    }
    public function down():void {Schema::dropIfExists('mapa_source_dispatch_outbox');Schema::dropIfExists('mapa_source_journeys');}
};
