<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\MobileDraft;
use App\Services\MobileGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MobileAppController extends Controller
{
    public function draftPhoto(Request $request, string $draftId, string $photoId)
    {
        abort_unless(config('mobile.enabled') && $request->session()->get('mobile.owner'), 404);
        $draft = MobileDraft::where('owner_id', $request->session()->get('mobile.owner'))->findOrFail($draftId);
        $photo = collect($draft->photos)->firstWhere('uuid', $photoId);
        abort_unless($photo && Storage::disk('local')->exists($photo['path']), 404);

        return response()->file(Storage::disk('local')->path($photo['path']), ['Cache-Control' => 'no-store'])->setPrivate();
    }

    public function start(Request $request)
    {
        abort_unless(config('mobile.enabled'), 404);
        $destination = $this->destination($request);
        $state = $request->session()->get('mobile.app_state');
        if (! $state) {
            $state = Str::random(64);
            $request->session()->put('mobile.app_state', $state);
        }
        app(MobileGateway::class)->request();
        $target = rtrim(config('mobile.server_url'), '/').'/native/entry?'.http_build_query(['state' => $state, 'path' => $destination, ... (config('mobile.desktop_runtime') ? ['device'=>'desktop'] : [])]);

        return response()->view('mobile-entry', ['serverEntry' => $target])->header('Cache-Control', 'no-store');
    }

    public function entry(Request $request)
    {
        abort_if(config('mobile.enabled'), 404);
        $data = $request->validate(['state' => ['required', 'regex:/^[A-Za-z0-9]{64}$/']]);
        if ($request->query('device') === 'desktop') {
            abort_unless(config('mobile.desktop_origin'), 404);
            $request->session()->put('native_app_device', 'desktop');
        } else {
            $request->session()->forget('native_app_device');
        }
        $request->session()->put('native_app_state', $data['state']);

        return redirect($this->destination($request));
    }

    public function desktopBootstrap(Request $request)
    {
        abort_unless(config('mobile.enabled') && config('mobile.desktop_runtime'), 404);
        app(MobileGateway::class)->request();
        $state = $request->session()->get('mobile.app_state');
        if (! $state) {
            $state = Str::random(64);
            $request->session()->put('mobile.app_state', $state);
        }
        $url = parse_url(config('mobile.server_url'));
        $serverOrigin = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
        return response()->view('mobile-desktop-bootstrap', compact('state','serverOrigin'))->header('Cache-Control','no-store')->header('Content-Security-Policy', 'frame-ancestors '.$serverOrigin);
    }

    public function desktopPrepare(Request $request)
    {
        abort_if(config('mobile.enabled'), 404);
        abort_unless(config('mobile.desktop_origin') && $request->user()->role === 'tecnico', 403);
        $data = $request->validate(['state'=>['required','regex:/^[A-Za-z0-9]{64}$/']]);
        $request->session()->put(['native_app_state'=>$data['state'],'native_app_device'=>'desktop']);
        return $this->prepare($request);
    }

    public static function deviceOrigin(): string
    {
        return session('native_app_device') === 'desktop' && config('mobile.desktop_origin')
            ? rtrim(config('mobile.desktop_origin'), '/') : 'http://127.0.0.1';
    }

    private function destination(Request $request): string
    {
        $data = $request->validate(['path' => ['nullable', 'regex:#^/(?:proyectos(?:/[0-9]+)?|intervenciones/[0-9]+(?:/imprimir)?|administracion|password)?$#']]);

        return $data['path'] ?? '/';
    }

    public function prepare(Request $request)
    {
        abort_if(config('mobile.enabled'), 404);
        $user = $request->user();
        abort_unless($user->active && ! $user->must_change_password && $user->role === 'tecnico', 403);
        $state = $request->session()->get('native_app_state');
        abort_unless(is_string($state) && preg_match('/^[A-Za-z0-9]{64}$/', $state), 403);
        $code = Str::random(64);
        DB::table('mobile_handoffs')->where('expires_at', '<', now())->delete();
        DB::table('mobile_handoffs')->insert(['user_id' => $user->id, 'code_hash' => hash('sha256', $code), 'state_hash' => hash('sha256', $state), 'expires_at' => now()->addMinute()]);

        return response()->view('mobile-return', ['deviceUrl' => self::deviceOrigin().'/mobile/handoff?'.http_build_query(['code' => $code, 'state' => $state])])->header('Cache-Control', 'no-store');
    }

    public function exchange(Request $request)
    {
        abort_if(config('mobile.enabled'), 404);
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Za-z0-9]{64}$/'], 'state' => ['required', 'regex:/^[A-Za-z0-9]{64}$/']]);

        return DB::transaction(function () use ($request, $data) {
            $handoff = DB::table('mobile_handoffs')->where('code_hash', hash('sha256', $data['code']))->where('state_hash', hash('sha256', $data['state']))->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($handoff, 401);
            $user = User::find($handoff->user_id);
            abort_unless($user && $user->active && ! $user->must_change_password && $user->role === 'tecnico', 403);
            DB::table('mobile_handoffs')->where('id', $handoff->id)->delete();
            $request->setUserResolver(fn () => $user);
            $sync = app(MobileSyncController::class);

            return response()->json(array_merge($sync->credentials($user, $data['state']), $sync->catalog($request)->getData(true)))->header('Cache-Control', 'no-store');
        });
    }

    public function signout(Request $request)
    {
        abort_unless(config('mobile.enabled'), 404);
        $expected = $request->session()->get('mobile.app_state');
        abort_unless(is_string($expected) && is_string($request->query('state')) && hash_equals($expected, $request->query('state')), 403);
        $request->session()->forget(['mobile.owner', 'mobile.user', 'mobile.token', 'mobile.catalog', 'mobile.app_state']);
        $request->session()->regenerate();

        return redirect()->route('mobile.start');
    }

    public function receive(Request $request)
    {
        abort_unless(config('mobile.enabled'), 404);
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Za-z0-9]{64}$/'], 'state' => ['required', 'regex:/^[A-Za-z0-9]{64}$/']]);
        $expected = $request->session()->get('mobile.app_state');
        abort_unless(is_string($expected) && hash_equals($expected, $data['state']), 403);
        $phase = 'exchange';
        try {
            $response = app(MobileGateway::class)->request()->post('handoff', $data)->throw();
            $phase = 'validate_response';
            $auth = $response->json();
            if (! isset($auth['user']['id'], $auth['token'], $auth['projects'])) {
                throw new \RuntimeException('Respuesta incompleta.');
            }
            $phase = 'store_session';
            $request->session()->regenerate();
            $request->session()->put(['mobile.owner' => $auth['user']['id'], 'mobile.user' => $auth['user'], 'mobile.token' => Crypt::encryptString($auth['token']), 'mobile.catalog' => $auth['projects'], 'mobile.downloaded_at' => now('America/La_Paz')->format('d/m/Y H:i'), 'mobile.app_state' => $expected]);

            return redirect()->route('mobile.workspace')->with('mobile_ready', true);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('mobile_download_failed', [
                'exception_class' => get_class($exception),
                'phase' => $phase,
                'response_status' => isset($response) ? $response->status() : null,
                'response_is_json' => isset($response) ? is_array($response->json()) : null,
                'http_status' => $exception instanceof \Illuminate\Http\Client\RequestException ? $exception->response->status() : null,
            ]);
            return redirect()->route('mobile.workspace')->with('mobile_error', 'No se pudo descargar. Tus borradores siguen guardados; vuelve al sistema y prueba otra vez.');
        }
    }
}
