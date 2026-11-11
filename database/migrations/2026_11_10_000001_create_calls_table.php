<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->string('caller_name')->nullable();
            $table->string('caller_phone', 50)->nullable();
            $table->string('caller_email')->nullable();
            $table->string('direction', 20)->default('inbound');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->json('transcript')->nullable(); // empty while an outbound call is still dialing

            $table->string('status', 20)->default('received')->index();
            $table->text('summary')->nullable();
            $table->string('intent', 40)->nullable();
            $table->string('sentiment', 20)->nullable();
            $table->json('action_items')->nullable();
            $table->boolean('booking_requested')->default(false);
            $table->timestamp('requested_start')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
