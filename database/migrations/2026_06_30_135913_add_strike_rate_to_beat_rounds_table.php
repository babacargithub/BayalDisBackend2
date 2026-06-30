<?php

use App\Models\BeatRound;
use App\Services\BeatService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beat_rounds', function (Blueprint $table) {
            $table->decimal('strike_rate', 5, 1)->nullable()->after('odometer_end_km');
        });

        $this->backfillStrikeRateForPastRounds();
    }

    public function down(): void
    {
        Schema::table('beat_rounds', function (Blueprint $table) {
            $table->dropColumn('strike_rate');
        });
    }

    /**
     * Compute and store strike_rate for every existing BeatRound using the
     * canonical BeatService::calculateStrikeRateForBeatRound() method — the
     * single source of truth for strike rate (covers both new invoices and
     * payment-only customers).
     *
     * Processed in chunks of 100 to avoid loading the full table into memory.
     */
    private function backfillStrikeRateForPastRounds(): void
    {
        $beatService = app(BeatService::class);

        BeatRound::query()->orderBy('id')->chunk(100, function ($rounds) use ($beatService): void {
            foreach ($rounds as $round) {
                $computedStrikeRate = $beatService->calculateStrikeRateForBeatRound($round);

                $round->update(['strike_rate' => $computedStrikeRate]);
            }
        });
    }
};
