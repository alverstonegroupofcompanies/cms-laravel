<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_code')->unique();
            $table->foreignId('worksheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queue_token_id')->nullable()->constrained('queue_tokens')->nullOnDelete();
            $table->decimal('consultation_fee', 10, 2)->default(0);
            $table->decimal('pharmacy_subtotal', 10, 2)->default(0);
            $table->decimal('lab_subtotal', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2)->default(0);
            $table->string('status')->default('open'); // open | in_progress | completed
            $table->string('pharmacy_status')->default('none'); // none | pending | done
            $table->string('lab_status')->default('none'); // none | pending | done
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
            $table->index(['pharmacy_status', 'created_at']);
            $table->index(['lab_status', 'created_at']);
        });

        Schema::create('visit_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_bill_id')->constrained('visit_bills')->cascadeOnDelete();
            $table->string('section'); // consultation | pharmacy | lab
            $table->string('name');
            $table->string('detail')->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status')->default('pending'); // pending | done | none
            $table->foreignId('prescription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prescription_item_id')->nullable()->constrained('prescription_items')->nullOnDelete();
            $table->foreignId('lab_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lab_order_item_id')->nullable()->constrained('lab_order_items')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_bill_items');
        Schema::dropIfExists('visit_bills');
    }
};
