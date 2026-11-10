<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt([...$data, 'active' => true])) {
            return back()->withErrors(['email' => 'Credenciales incorrectas o cuenta inactiva.'])->onlyInput('email');
        }
        $r->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function password(Request $r)
    {
        $data = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers(), 'different:current_password']], [
            'current_password.required' => 'Escribe tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual es incorrecta.',
            'password.required' => 'Escribe la nueva contraseña.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La nueva contraseña debe tener al menos 10 caracteres.',
            'password.password.letters' => 'La nueva contraseña debe contener al menos una letra.',
            'password.password.numbers' => 'La nueva contraseña debe contener al menos un número.',
            'password.different' => 'La nueva contraseña debe ser diferente de la actual.',
        ]);
        $r->user()->update(['password' => $data['password'], 'must_change_password' => false]);
        $r->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Contraseña actualizada.');
    }
}
