<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicle_data', function (Blueprint $table) {
            $table->id();

            // Identity + VIN-derived fields
            $table->string('vin', 17)->unique();
            $table->string('wmi', 3);
            $table->string('vin_region');
            $table->string('vin_country');

            // Core descriptors
            $table->string('model');
            $table->unsignedSmallInteger('model_year');
            $table->string('trim')->nullable();
            $table->string('body_style');

            // Pricing
            $table->decimal('current_price_usd', 12, 2);
            $table->decimal('msrp_usd', 12, 2);

            // Provenance / status
            $table->boolean('carfax_certified');
            $table->string('pincode');
            $table->boolean('is_new');
            $table->unsignedInteger('mileage');
            $table->unsignedInteger('previous_owners');

            // Appearance
            $table->string('exterior_color');
            $table->string('interior_color');

            // Powertrain
            $table->string('engine_type');
            $table->string('transmission');
            $table->decimal('fuel_efficiency_mpg', 5, 1)->nullable();

            // Dates
            $table->date('manufacture_date');
            $table->date('registration_date')->nullable();

            // Feature payload
            $table->json('features');

            $table->timestamp('last_updated');

            $table->index('model');
            $table->index('model_year');
            $table->index('vin_country');
            $table->index('engine_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_data');
    }
};
