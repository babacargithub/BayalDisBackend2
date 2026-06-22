<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beat_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('planned_at');
            $table->string('week_day')->nullable();
            $table->foreignId('commercial_id')->nullable()->constrained('commercials')->nullOnDelete();
            $table->foreignId('beat_id')->constrained('beats')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['beat_id', 'planned_at']);
        });

        Schema::table('beat_stops', function (Blueprint $table) {
            $table->foreignId('beat_round_id')->nullable()->after('beat_id')
                ->constrained('beat_rounds')->cascadeOnDelete();
        });

        // Migrate existing occurrence stops: one BeatRound per unique (beat_id, visit_date) pair.
        $existingRoundGroups = DB::table('beat_stops')
            ->join('beats', 'beats.id', '=', 'beat_stops.beat_id')
            ->whereNotNull('beat_stops.visit_date')
            ->select(
                'beat_stops.beat_id',
                'beat_stops.visit_date',
                'beats.day_of_week',
                'beats.commercial_id',
                DB::raw('beats.name as beat_name'),
            )
            ->distinct()
            ->get();

        $now = now()->toDateTimeString();

        foreach ($existingRoundGroups as $group) {
            $roundId = DB::table('beat_rounds')->insertGetId([
                'beat_id' => $group->beat_id,
                'planned_at' => $group->visit_date,
                'week_day' => $group->day_of_week,
                'commercial_id' => $group->commercial_id,
                'name' => $group->beat_name.' - '.$group->visit_date,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('beat_stops')
                ->where('beat_id', $group->beat_id)
                ->where('visit_date', $group->visit_date)
                ->update(['beat_round_id' => $roundId]);
        }

        Schema::table('beat_stops', function (Blueprint $table) {
            $table->dropIndex('beat_stops_beat_id_visit_date_index');
            $table->dropColumn('visit_date');
        });
    }

    public function down(): void
    {
        Schema::table('beat_stops', function (Blueprint $table) {
            $table->date('visit_date')->nullable()->after('beat_round_id');
            $table->index(['beat_id', 'visit_date']);
        });

        foreach (DB::table('beat_stops')->whereNotNull('beat_round_id')->get() as $stop) {
            $round = DB::table('beat_rounds')->where('id', $stop->beat_round_id)->first();
            if ($round) {
                DB::table('beat_stops')->where('id', $stop->id)->update(['visit_date' => $round->planned_at]);
            }
        }

        Schema::table('beat_stops', function (Blueprint $table) {
            $table->dropForeign(['beat_round_id']);
            $table->dropColumn('beat_round_id');
        });

        Schema::dropIfExists('beat_rounds');
    }
};
