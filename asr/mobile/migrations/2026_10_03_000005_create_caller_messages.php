<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('mapa_caller_messages',function(Blueprint $t){$t->uuid('company_uuid');$t->string('action_id',100);$t->primary(['company_uuid','action_id']);$t->uuid('call_id');$t->uuid('link_id')->unique();$t->uuid('created_by');$t->string('request_hash',64);$t->string('status',20);$t->string('provider_id',255)->nullable();$t->timestamp('consented_at');$t->timestamps();});}
 public function down():void {Schema::dropIfExists('mapa_caller_messages');}
};
