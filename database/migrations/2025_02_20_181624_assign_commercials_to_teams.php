<?php

use App\Models\Commercial;
use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $commerciaux = Commercial::all();
        foreach ($commerciaux as $commercial) {
            if ($commercial->team_id === null) {
                $team = Team::first();
                if ($team) {
                    $commercial->team_id = $team->id;
                    $commercial->save();
                }
            }

        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

    }
};
