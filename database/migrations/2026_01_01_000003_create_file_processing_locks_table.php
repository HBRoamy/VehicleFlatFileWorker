<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('file_processing_locks', function (Blueprint $table) {
            $table->id();
            $table->string('file_name')->unique();
            $table->string('locked_by')->nullable();
            $table->string('status')->default('processing');
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->index('status');
            $table->index('locked_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_processing_locks');
    }
};
