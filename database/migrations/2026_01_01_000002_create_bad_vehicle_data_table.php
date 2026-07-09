<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bad_vehicle_data', function (Blueprint $table) {
            $table->id();
            $table->string('vin', 32)->nullable();
            $table->unsignedInteger('row_number');
            $table->string('file_name');
            $table->text('raw_row_data');
            $table->text('error_reason');
            $table->timestamp('created_at')->nullable();

            $table->index('file_name');
            $table->index('vin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bad_vehicle_data');
    }
};
