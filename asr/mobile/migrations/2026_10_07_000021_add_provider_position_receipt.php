<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        foreach (['mapa_device_positions','mapa_device_position_history'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->unsignedBigInteger('provider_received_at')->nullable();
            });
        }
    }
    public function down(): void {
        throw new RuntimeException('Provider receipt rollback requires a reviewed history migration.');
    }
};
