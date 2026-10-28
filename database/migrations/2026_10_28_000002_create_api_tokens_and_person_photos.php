<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mobil ilova tokenlari: kod → Telegram bot orqali bog'lash → Bearer token
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->text('token_plain')->nullable(); // faqat birinchi so'rovgacha saqlanadi
            $table->string('device', 80)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });

        // Har bir odamga bir nechta rasm
        Schema::create('person_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tree_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('path');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_photos');
        Schema::dropIfExists('api_tokens');
    }
};
