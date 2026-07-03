<?php

namespace App\Enums;

/**
 * Signal appended at the end of a Verdict de Terrain sentence.
 *
 * Three levels — no school connotation, immediately readable on mobile.
 * The tolerance bands are defined in MetricTranslatorService::resolveVerdictStatus().
 */
enum VerdictStatus
{
    case OnTrack;
    case AtRisk;
    case BelowTarget;

    public function emoji(): string
    {
        return match ($this) {
            self::OnTrack => '✅',
            self::AtRisk => '🟡',
            self::BelowTarget => '🔴',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OnTrack => 'Objectif atteint',
            self::AtRisk => 'À surveiller',
            self::BelowTarget => 'À améliorer',
        };
    }
}
