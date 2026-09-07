<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_bill_items', function (Blueprint $table) {
            $table->boolean('included')->default(true)->after('status');
            $table->boolean('purchased')->default(false)->after('included');
        });
    }

    public function down(): void
    {
        Schema::table('visit_bill_items', function (Blueprint $table) {
            $table->dropColumn(['included', 'purchased']);
        });
    }
};
