<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('factbook_access_attempts', function (Blueprint $table) {
            $table->string('client', 64)->primary();
            $table->integer('attempts')->default(0);
            $table->bigInteger('reset_at')->index();
        });
    }
    public function down(): void { Schema::dropIfExists('factbook_access_attempts'); }
};

