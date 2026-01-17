<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Spatial Analyses Table Migration
 * 
 * Creates the database table for storing GeoMin analysis results.
 * This table tracks analysis type, status, parameters, and output paths.
 * 
 * @author Kazashim Kuzasuwat
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('spatial_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('status', 20)->default('pending');
            $table->json('parameters')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('result_path', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index('type');
            $table->index('status');
            $table->index(['type', 'status']);
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spatial_analyses');
    }
};
