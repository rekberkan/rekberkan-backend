<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maker_checker_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('maker_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('checker_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->enum('approval_status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->json('payload_snapshot')->nullable();
            $table->text('maker_notes')->nullable();
            $table->text('checker_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['maker_id', 'approval_status']);
            $table->index('checker_id');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maker_checker_actions');
    }
};
