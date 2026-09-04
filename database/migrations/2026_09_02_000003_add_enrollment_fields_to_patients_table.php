<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('address_street')->nullable()->after('address');
            $table->string('address_line2')->nullable()->after('address_street');
            $table->string('city')->nullable()->after('address_line2');
            $table->string('state')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('state');
            $table->boolean('taking_medications')->nullable()->after('current_medications');
            $table->string('emergency_contact_first_name')->nullable()->after('taking_medications');
            $table->string('emergency_contact_last_name')->nullable()->after('emergency_contact_first_name');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_last_name');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'address_street',
                'address_line2',
                'city',
                'state',
                'postal_code',
                'taking_medications',
                'emergency_contact_first_name',
                'emergency_contact_last_name',
                'emergency_contact_relationship',
                'emergency_contact_phone',
            ]);
        });
    }
};
