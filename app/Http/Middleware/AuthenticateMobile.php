<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthenticateMobile
{
    public function handle(Request $request, Closure $next)
    {
        $plain = $request->bearerToken();
        abort_unless($plain && strlen($plain) <= 200, 401);
        $token = DB::table('mobile_tokens')->where('token_hash', hash('sha256', $plain))->where('expires_at', '>', now())->first();
        abort_unless($token, 401);
        $user = User::find($token->user_id);
        abort_unless($user && $user->active && ! $user->must_change_password && $user->role === 'tecnico', 403);
        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('mobile_token_id', $token->id);

        return $next($request);
    }
}
