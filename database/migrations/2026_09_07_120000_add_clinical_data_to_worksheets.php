<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->json('clinical_data')->nullable()->after('clinical_notes');
            $table->string('diagnosis')->nullable()->after('clinical_data');
            $table->text('advice')->nullable()->after('diagnosis');
            $table->string('follow_up')->nullable()->after('advice');
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropColumn(['clinical_data', 'diagnosis', 'advice', 'follow_up']);
        });
    }
};
