<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('mapa_mobile_hospital_handovers',function(Blueprint $t){
            $t->uuid('id')->primary(); $t->uuid('company_uuid'); $t->uuid('order_uuid'); $t->uuid('place_uuid');
            $t->uuid('driver_uuid'); $t->uuid('vehicle_uuid'); $t->uuid('created_by');
            $t->text('report_encrypted'); $t->string('report_hash',64); $t->string('status',24)->default('incoming');
            $t->unsignedInteger('revision')->default(1); $t->timestamps();
            $t->unique(['company_uuid','order_uuid'],'mapa_handover_order_unique');
            $t->index(['company_uuid','place_uuid','created_at'],'mapa_handover_incoming');
        });
        Schema::create('mapa_mobile_hospital_responses',function(Blueprint $t){
            $t->bigIncrements('id'); $t->uuid('handover_id'); $t->uuid('company_uuid'); $t->uuid('user_uuid');
            $t->string('status',24); $t->unsignedInteger('revision'); $t->timestamp('created_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('mapa_mobile_hospital_responses'); Schema::dropIfExists('mapa_mobile_hospital_handovers');
    }
};
