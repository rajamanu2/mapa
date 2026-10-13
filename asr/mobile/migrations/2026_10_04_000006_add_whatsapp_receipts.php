<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('mapa_caller_messages',function(Blueprint $t){$t->string('callback_hash',64)->nullable()->unique();$t->string('provider_phone_id',30)->nullable();$t->unsignedBigInteger('provider_timestamp')->nullable();$t->unsignedInteger('failure_code')->nullable();$t->index(['provider_phone_id','provider_id'],'mapa_caller_provider_idx');});}
 public function down():void {Schema::table('mapa_caller_messages',function(Blueprint $t){$t->dropIndex('mapa_caller_provider_idx');$t->dropUnique(['callback_hash']);$t->dropColumn(['callback_hash','provider_phone_id','provider_timestamp','failure_code']);});}
};
