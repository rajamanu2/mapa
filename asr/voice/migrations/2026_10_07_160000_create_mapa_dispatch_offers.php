<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('mapa_dispatch_runs',function(Blueprint $t){
            $t->uuid('call_id')->primary();$t->uuid('company_uuid');$t->uuid('operator_uuid');
            $t->uuid('binding_id');$t->unsignedInteger('binding_revision');$t->unsignedInteger('call_revision');
            $t->string('state',24);$t->string('reason',80)->nullable();$t->timestamps();
            $t->index(['company_uuid','state']);
        });
        Schema::create('mapa_dispatch_offers',function(Blueprint $t){
            $t->uuid('id')->primary();$t->uuid('company_uuid');$t->uuid('call_id');
            $t->uuid('driver_uuid');$t->uuid('vehicle_uuid');$t->unsignedInteger('revision')->default(1);
            $t->string('state',24);$t->unsignedInteger('road_seconds');$t->timestamp('expires_at');
            $t->timestamp('responded_at')->nullable();$t->timestamps();
            $t->unique(['call_id','driver_uuid']);$t->index(['company_uuid','state','expires_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('mapa_dispatch_offers');Schema::dropIfExists('mapa_dispatch_runs');
    }
};
