<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt([...$data, 'active' => true], remember: true)) {
            return back()->withErrors(['email' => 'Credenciales incorrectas o cuenta inactiva.'])->onlyInput('email');
        }
        $r->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $r)
    {
        $nativeState = $r->session()->get('native_app_state');
        $deviceOrigin = MobileAppController::deviceOrigin();
        if ($nativeState && $r->user()) {
            DB::table('mobile_tokens')->where('user_id', $r->user()->id)->where('app_state_hash', hash('sha256', $nativeState))->delete();
        }
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        if ($nativeState) {
            return response()->view('mobile-return', ['deviceUrl' => $deviceOrigin.'/mobile/signout?state='.urlencode($nativeState)])->header('Cache-Control', 'no-store');
        }

        return redirect()->route('login');
    }

    public function password(Request $r)
    {
        $data = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', Password::min(10)->letters()->numbers(), 'different:current_password'], 'password_confirmation' => 'required|same:password'], [
            'current_password.required' => 'Escribe tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual es incorrecta.',
            'password.required' => 'Escribe la nueva contraseña.',
            'password_confirmation.required' => 'Confirma la nueva contraseña.',
            'password_confirmation.same' => 'Las contraseñas no coinciden.',
            'password.min' => 'La nueva contraseña debe tener al menos 10 caracteres.',
            'password.password.letters' => 'La nueva contraseña debe contener al menos una letra.',
            'password.password.numbers' => 'La nueva contraseña debe contener al menos un número.',
            'password.different' => 'La nueva contraseña debe ser diferente de la actual.',
        ]);
        $r->user()->update(['password' => $data['password'], 'must_change_password' => false]);
        $r->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
