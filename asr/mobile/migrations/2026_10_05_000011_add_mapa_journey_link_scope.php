<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('mapa_tracking_links',function(Blueprint $t){
            // Existing links keep their original pickup-only disclosure scope.
            $t->string('scope',24)->default('pickup');
            $t->string('callback_hash',64)->nullable();
            $t->uuid('order_uuid')->nullable();
        });
    }
    public function down(): void {
        Schema::table('mapa_tracking_links',fn(Blueprint $t)=>$t->dropColumn(['scope','callback_hash','order_uuid']));
    }
};
