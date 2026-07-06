<?php

namespace App\Data\Grade;

/**
 * Represents a metric value expressed as a grade on the Senegalese 0–20 school scale.
 *
 * The mention is derived automatically from the grade at construction time so that every
 * GradeDTO is self-contained and consistent — no risk of mention/grade mismatch.
 *
 * Mention scale:
 *   0  – 9.9  → Insuffisant
 *   10 – 11.9 → Passable
 *   12 – 13.9 → Assez Bien
 *   14 – 15.9 → Bien
 *   16 – 17.9 → Très Bien
 *   18 – 20   → Excellent
 */
readonly class GradeDTO
{
    public string $mention;

    public function __construct(public float $grade)
    {
        $this->mention = self::resolveMention($grade);
    }

    /** Returns the grade in the format "14.5/20" or "14/20" for whole numbers. */
    public function formatted(): string
    {
        $rounded = round($this->grade, 1);
        $display = fmod($rounded, 1.0) === 0.0 ? (int) $rounded : $rounded;

        return "{$display}/20";
    }

    public function toArray(): array
    {
        return [
            'grade' => $this->grade,
            'formatted' => $this->formatted(),
            'mention' => $this->mention,
        ];
    }

    private static function resolveMention(float $grade): string
    {
        return match (true) {
            $grade >= 18.0 => 'Excellent',
            $grade >= 16.0 => 'Très Bien',
            $grade >= 14.0 => 'Bien',
            $grade >= 12.0 => 'Assez Bien',
            $grade >= 10.0 => 'Passable',
            default => 'Insuffisant',
        };
    }
}
