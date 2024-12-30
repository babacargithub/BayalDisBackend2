<?php 
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commercial;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'secret_code' => 'required|string'
        ]);

        $commercial = Commercial::authenticate(
            $request->phone_number,
            $request->secret_code
        );

        if (!$commercial) {
            throw ValidationException::withMessages([
                'phone_number' => ['Les informations de connexion sont incorrectes.'],
            ]);
        }
        $commercial->load('user');

        // Create or update user account if needed
        if ($commercial->user == null) {
            if(User::where("email",$commercial->phone_number . '@bayal.com')->exists()){
                $user = User::where("email",$commercial->phone_number . '@bayal.com')->first();
                $commercial->update(['user_id' => $user->id]);
            }else{
                $user = User::create([
                    'name' => $commercial->name,
                    'email' => $commercial->phone_number . '@bayal.com',
                    'password' => Hash::make($request->secret_code),
                ]);

                $commercial->update(['user_id' => $user->id]);
            }

        }

        $user = $commercial->user;
        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'commercial' => [
                'id' => $commercial->id,
                'name' => $commercial->name,
                'phone_number' => $commercial->phone_number,
                'gender' => $commercial->gender,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        
        return response()->json([
            'message' => 'Déconnexion réussie',
        ]);
    }
} 