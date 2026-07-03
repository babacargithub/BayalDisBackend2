<?php

namespace App\Models;

use App\Enums\GoalAssigneeType;
use App\Enums\GoalMetric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    /** @use HasFactory<\Database\Factories\GoalFactory> */
    use HasFactory;

    protected $fillable = [
        'assignee_type',
        'assignee_id',
        'metric',
        'target_value',
        'period_start',
        'period_end',
        'parent_goal_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'assignee_type' => GoalAssigneeType::class,
            'metric' => GoalMetric::class,
            'target_value' => 'float',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class, 'assignee_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assignee_id');
    }

    public function parentGoal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'parent_goal_id');
    }

    public function childGoals(): HasMany
    {
        return $this->hasMany(Goal::class, 'parent_goal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompanyWide(): bool
    {
        return $this->assignee_type === GoalAssigneeType::Company;
    }
}
