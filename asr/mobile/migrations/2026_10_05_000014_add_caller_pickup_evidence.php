<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('mapa_caller_locations',fn(Blueprint $table)=>$table->json('pickup_confirmation')->nullable());
    }
    public function down(): void {
        Schema::table('mapa_caller_locations',fn(Blueprint $table)=>$table->dropColumn('pickup_confirmation'));
    }
};
