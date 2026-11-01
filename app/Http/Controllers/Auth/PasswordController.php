<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Changement de son propre mot de passe ; obligatoire après un mot de passe provisoire.
 */
class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.password', ['forced' => $request->user()->must_change_password]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], ['current_password' => 'mot de passe actuel', 'password' => 'nouveau mot de passe']);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mot de passe actuel incorrect.']);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => "Choisissez un mot de passe différent de l'actuel."]);
        }

        $forced = $user->must_change_password;
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        // Les autres sessions ouvertes avec l'ancien mot de passe sont fermées
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        AuditLog::create(['pharmacy_id' => $user->pharmacy_id, 'user_id' => $user->id, 'action' => 'user.password_changed',
            'auditable_type' => 'user', 'auditable_id' => $user->id, 'ip_address' => $request->ip()]);

        return $forced
            ? redirect()->intended(route('pos'))->with('status', 'Mot de passe enregistré. Bienvenue !')
            : back()->with('status', 'Mot de passe modifié.');
    }
}
