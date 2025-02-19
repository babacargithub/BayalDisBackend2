<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\Commercial;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeamController extends Controller
{
    public function index()
    {
        $teams = Team::with('manager', 'commercials')->paginate(10);
        return Inertia::render('Teams/Index', [
            'teams' => $teams,
            'users' => User::select('id', 'name')->get(),
            'commercials' => Commercial::select('id', 'name', 'team_id')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'user_id' => 'required|exists:users,id'
        ]);

        Team::create($validated);

        return redirect()->back()->with('success', 'Équipe créée avec succès');
    }

    public function update(Request $request, Team $team)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'user_id' => 'required|exists:users,id'
        ]);

        $team->update($validated);

        return redirect()->back()->with('success', 'Équipe mise à jour avec succès');
    }

    public function destroy(Team $team)
    {
        $team->delete();
        return redirect()->back()->with('success', 'Équipe supprimée avec succès');
    }

    public function addCommercial(Request $request, Team $team)
    {
        $validated = $request->validate([
            'commercial_id' => 'required|exists:commercials,id'
        ]);

        Commercial::where('id', $validated['commercial_id'])->update(['team_id' => $team->id]);

        return redirect()->back()->with('success', 'Commercial ajouté à l\'équipe avec succès');
    }

    public function removeCommercial(Request $request, Team $team)
    {
        $validated = $request->validate([
            'commercial_id' => 'required|exists:commercials,id'
        ]);

        Commercial::where('id', $validated['commercial_id'])->update(['team_id' => null]);

        return redirect()->back()->with('success', 'Commercial retiré de l\'équipe avec succès');
    }
} 