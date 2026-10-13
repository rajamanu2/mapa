<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('mapa_caller_pickup_confirmations',function(Blueprint $t){
   $t->uuid('id')->primary();$t->uuid('company_uuid');$t->uuid('call_id');$t->uuid('order_uuid');$t->uuid('link_id');$t->uuid('confirmed_by');
   $t->string('action_id',100);$t->string('body_hash',64);$t->string('request_hash',64);$t->string('expected_revision',64);
   $t->uuid('previous_pickup_uuid');$t->uuid('pickup_uuid');$t->decimal('latitude',10,7);$t->decimal('longitude',10,7);$t->float('accuracy_m');$t->unsignedBigInteger('recorded_at');
   $t->json('result');$t->timestamp('created_at');$t->unique(['company_uuid','action_id'],'mapa_pickup_confirmation_action');$t->index(['company_uuid','call_id']);
  });
 }
 public function down():void {Schema::dropIfExists('mapa_caller_pickup_confirmations');}
};
