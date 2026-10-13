<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('mapa_mobile_enrollments',function(Blueprint $t){
            $t->uuid('id')->primary(); $t->uuid('company_uuid'); $t->string('action_id',100); $t->string('request_hash',64); $t->string('connect_hash',64)->unique();
            $t->string('status',20)->default('pending'); $t->unsignedInteger('revision')->default(1);
            $t->string('name',150); $t->string('email',200); $t->string('phone',20); $t->string('ambulance_registration',50); $t->string('ambulance_name',150); $t->string('hospital_name',200); $t->string('device_name',100);
            $t->decimal('latitude',10,7); $t->decimal('longitude',10,7); $t->float('accuracy_m'); $t->unsignedBigInteger('recorded_at'); $t->unsignedBigInteger('selfie_captured_at'); $t->uuid('selfie_media_id');
            $t->unsignedBigInteger('consented_at'); $t->unsignedBigInteger('connect_expires_at'); $t->unsignedBigInteger('revoked_at')->nullable();
            $t->uuid('user_uuid')->nullable(); $t->uuid('binding_id')->nullable(); $t->uuid('driver_uuid')->nullable(); $t->uuid('vehicle_uuid')->nullable(); $t->uuid('place_uuid')->nullable();
            $t->string('credential_hash',64)->nullable(); $t->string('scope_hash',64)->nullable(); $t->unsignedInteger('binding_revision')->nullable();
            $t->uuid('approved_by')->nullable(); $t->timestamp('approved_at')->nullable(); $t->string('review_reason',500)->nullable(); $t->timestamps();
            $t->unique(['company_uuid','action_id']); $t->index(['company_uuid','status','created_at']); $t->index(['company_uuid','email']);
        });
        Schema::create('mapa_mobile_private_media',function(Blueprint $t){
            $t->uuid('id')->primary(); $t->uuid('company_uuid'); $t->uuid('enrollment_id')->nullable(); $t->uuid('order_uuid')->nullable(); $t->uuid('user_uuid')->nullable(); $t->uuid('driver_uuid')->nullable(); $t->uuid('vehicle_uuid')->nullable();
            $t->string('kind',20); $t->string('mime',30); $t->string('sha256',64); $t->unsignedInteger('bytes'); $t->timestamp('created_at');
            $t->index(['company_uuid','enrollment_id']); $t->index(['company_uuid','order_uuid']);
        });
        Schema::create('mapa_mobile_trip_reports',function(Blueprint $t){
            $t->uuid('id')->primary(); $t->uuid('company_uuid'); $t->uuid('order_uuid'); $t->uuid('user_uuid'); $t->uuid('driver_uuid'); $t->uuid('vehicle_uuid');
            $t->string('action_id',100); $t->text('note'); $t->uuid('photo_media_id'); $t->decimal('latitude',10,7); $t->decimal('longitude',10,7); $t->float('accuracy_m');
            $t->unsignedBigInteger('recorded_at'); $t->unsignedBigInteger('photo_captured_at'); $t->unsignedBigInteger('received_at'); $t->timestamp('created_at');
            $t->unique(['company_uuid','order_uuid']); $t->unique(['company_uuid','user_uuid','action_id']);
        });
        Schema::create('mapa_mobile_enrollment_audit',function(Blueprint $t){
            $t->bigIncrements('id'); $t->uuid('company_uuid'); $t->uuid('enrollment_id'); $t->uuid('user_uuid')->nullable(); $t->string('operation',20); $t->unsignedInteger('revision'); $t->string('action_id',100); $t->string('request_hash',64); $t->json('result'); $t->timestamp('created_at');
            $t->unique(['company_uuid','enrollment_id','action_id'],'mapa_enrollment_action_unique');
        });
    }
    public function down(): void { foreach(['mapa_mobile_enrollment_audit','mapa_mobile_trip_reports','mapa_mobile_private_media','mapa_mobile_enrollments'] as $table) Schema::dropIfExists($table); }
};
