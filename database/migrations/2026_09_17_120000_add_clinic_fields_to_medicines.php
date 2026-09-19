<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_product_id')->nullable()->unique()->after('id');
            $table->string('clinic_external_id')->nullable()->after('clinic_product_id');
            $table->string('sku')->nullable()->after('generic_name');
            $table->string('strength')->nullable()->after('sku');
            $table->string('manufacturer')->nullable()->after('strength');
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn([
                'clinic_product_id',
                'clinic_external_id',
                'sku',
                'strength',
                'manufacturer',
            ]);
        });
    }
};
