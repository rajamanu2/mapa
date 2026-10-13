<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('mapa_mobile_hospital_push_devices',function(Blueprint $t){
   $t->string('token_hash',64)->primary();$t->uuid('installation_id');$t->text('token_encrypted');
   $t->uuid('company_uuid');$t->uuid('binding_id');$t->string('session_hash',64)->index();$t->uuid('place_uuid');
   $t->unsignedBigInteger('expires_at');$t->unsignedBigInteger('revoked_at')->nullable();$t->timestamps();
   $t->unique(['company_uuid','installation_id'],'mapa_hpush_installation');$t->index(['company_uuid','place_uuid'],'mapa_hpush_place');
  });
  Schema::create('mapa_mobile_hospital_push_deliveries',function(Blueprint $t){
   $t->string('id',64)->primary();$t->string('event_id',64);$t->string('token_hash',64);$t->string('status',20)->default('pending');
   $t->unsignedBigInteger('claimed_at')->nullable();$t->unsignedBigInteger('accepted_at')->nullable();$t->timestamps();
  });
 }
 public function down(): void {Schema::dropIfExists('mapa_mobile_hospital_push_deliveries');Schema::dropIfExists('mapa_mobile_hospital_push_devices');}
};
