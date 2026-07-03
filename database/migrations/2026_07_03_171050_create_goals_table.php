<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();

            // Polymorphic assignee — who this goal belongs to.
            $table->string('assignee_type')->comment('commercial | team | company');
            $table->unsignedBigInteger('assignee_id')->nullable()->comment('Null for company-wide goals');

            // The KPI metric being targeted.
            $table->string('metric')->comment('GoalMetric enum value');

            // Numeric target (XOF for money metrics, percentage for rates, float for scores).
            $table->decimal('target_value', 15, 2);

            // Reporting period.
            $table->date('period_start');
            $table->date('period_end');

            // Optional parent — distributes a company goal down to teams/commercials.
            $table->foreignId('parent_goal_id')
                ->nullable()
                ->constrained('goals')
                ->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['assignee_type', 'assignee_id']);
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
