<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PersistentLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_remembered_access_restores_expired_session_and_logout_revokes_it(): void
    {
        $user = User::factory()->create(['active' => true, 'must_change_password' => false, 'role' => 'tecnico']);
        $name = Auth::guard()->getRecallerName();
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $response->assertRedirect(route('dashboard'))->assertCookie($name);
        $cookie = $response->getCookie($name)->getValue();
        $this->app['session']->flush();
        Auth::forgetGuards();
        $this->withCookie($name, $cookie)->get('/')->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Auth::viaRemember());
        $token = $user->fresh()->remember_token;
        $this->post('/logout')->assertRedirect(route('login'))->assertCookieExpired($name);
        $this->assertGuest();
        $this->assertNotSame($token, $user->fresh()->remember_token);
        $this->app['session']->flush();
        Auth::forgetGuards();
        $this->withCookie($name, $cookie)->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_inactive_account_cannot_get_persistent_access(): void
    {
        $user = User::factory()->create(['active' => false]);
        $name = Auth::guard()->getRecallerName();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['login' => 'Los datos de acceso no son correctos.'])->assertCookieMissing($name);
        $this->assertGuest();
    }

    public function test_login_failures_show_the_same_inline_message(): void
    {
        $user = User::factory()->create(['active' => true]);

        foreach ([$user->email, 'desconocido@example.com', 'correo-invalido'] as $email) {
            $this->from('/login')->post('/login', ['email' => $email, 'password' => 'incorrecta'])
                ->assertRedirect('/login')
                ->assertSessionHasErrors(['login' => 'Los datos de acceso no son correctos.']);

            $this->get('/login')->assertOk()
                ->assertSee('Los datos de acceso no son correctos.')
                ->assertDontSee('data-kardex-alert="error"', false)
                ->assertDontSee('sweetalert2.all.min.js')
                ->assertDontSee('kardex-alerts.js');
        }
    }

    public function test_login_limit_returns_to_the_form_with_an_inline_message(): void
    {
        $credentials = ['email' => 'desconocido@example.com', 'password' => 'incorrecta'];

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->from('/login')->post('/login', $credentials)
                ->assertSessionHasErrors(['login' => 'Los datos de acceso no son correctos.']);
        }

        $this->post('/login', $credentials)->assertRedirect('/login');
        $this->get('/login')->assertOk()
            ->assertSee('Demasiados intentos. Espera un minuto y vuelve a intentar.');
        $this->postJson('/login', $credentials)->assertTooManyRequests();
    }
}
