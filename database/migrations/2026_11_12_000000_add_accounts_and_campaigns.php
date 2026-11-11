<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the single-desk app into accounts: every contact, call and campaign
 * belongs to a user (the business). Existing rows stay, without an owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('business_name')->nullable()->after('name');
            $table->string('webhook_token', 64)->nullable()->unique()->after('remember_token');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->json('tags')->nullable()->after('notes');
            $table->unique(['user_id', 'phone']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('purpose', 180);
            $table->string('status', 20)->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('run_token', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'contact_id']);
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::dropIfExists('campaign_contact');
        Schema::dropIfExists('campaigns');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'phone']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('tags');
            $table->unique('phone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['business_name', 'webhook_token']);
        });
    }
};
