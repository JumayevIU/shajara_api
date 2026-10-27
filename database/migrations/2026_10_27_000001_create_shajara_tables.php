<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daraxt (bitta oila shajarasi)
        Schema::create('trees', function (Blueprint $table) {
            $table->id();
            $table->string('invite_code', 16)->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('root_person_id')->nullable(); // asoschining kartasi
            $table->timestamps();
        });

        // Daraxtdagi odamlar (kartalar)
        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tree_id')->constrained('trees')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('gender', 1); // m | f
            $table->smallInteger('birth_year')->nullable();
            $table->smallInteger('death_year')->nullable();
            $table->boolean('alive')->default(true);
            $table->foreignId('father_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->foreignId('mother_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Er-xotin bog'lari (a_id < b_id qilib saqlanadi)
        Schema::create('spouse_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tree_id')->constrained('trees')->cascadeOnDelete();
            $table->foreignId('a_id')->constrained('persons')->cascadeOnDelete();
            $table->foreignId('b_id')->constrained('persons')->cascadeOnDelete();
            $table->unique(['a_id', 'b_id']);
        });

        // Foydalanuvchi ↔ daraxt a'zoligi (MVP: har foydalanuvchi bitta daraxtda)
        Schema::create('tree_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tree_id')->constrained('trees')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete(); // "Bu — men"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_members');
        Schema::dropIfExists('spouse_links');
        Schema::dropIfExists('persons');
        Schema::dropIfExists('trees');
    }
};
