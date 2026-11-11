<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone', 20)->unique();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamp('last_called_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
