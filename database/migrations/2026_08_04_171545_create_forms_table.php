<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('schema')->nullable();
            $table->uuid('public_uuid')->unique();
            $table->string('status', 32)->default('draft');
            $table->timestamps();

            // Lookups by UUID (public form URL resolution)
            // $table->unique('public_uuid') — already declared above
            // Listing forms by status + recency
            $table->index(['status', 'created_at']);
            // Full-text search on title
            $table->fullText('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
