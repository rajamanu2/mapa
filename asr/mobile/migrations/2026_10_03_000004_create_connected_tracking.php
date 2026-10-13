<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('mapa_tracking_links',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('company_uuid');$t->uuid('call_id');$t->string('token_hash',64)->unique();$t->uuid('created_by');$t->unsignedBigInteger('expires_at');$t->unsignedBigInteger('revoked_at')->nullable();$t->timestamps();$t->index(['company_uuid','call_id']);});
  Schema::create('mapa_caller_locations',function(Blueprint $t){$t->uuid('link_id')->primary();$t->uuid('company_uuid');$t->decimal('latitude',10,7);$t->decimal('longitude',10,7);$t->float('accuracy_m');$t->unsignedBigInteger('recorded_at');$t->unsignedBigInteger('consented_at');$t->timestamps();});
  Schema::create('mapa_tracking_devices',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('company_uuid');$t->uuid('vehicle_uuid');$t->string('label',150);$t->string('external_id',100);$t->string('secret_hash',64)->unique();$t->uuid('created_by');$t->unsignedBigInteger('revoked_at')->nullable();$t->timestamps();$t->unique(['company_uuid','external_id']);});
  Schema::create('mapa_device_positions',function(Blueprint $t){$t->uuid('device_id')->primary();$t->uuid('company_uuid');$t->uuid('vehicle_uuid');$t->decimal('latitude',10,7);$t->decimal('longitude',10,7);$t->float('accuracy_m');$t->unsignedBigInteger('recorded_at');$t->timestamps();});
 }
 public function down():void {foreach(['mapa_device_positions','mapa_tracking_devices','mapa_caller_locations','mapa_tracking_links'] as $name)Schema::dropIfExists($name);}
};
