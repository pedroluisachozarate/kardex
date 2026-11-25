<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_error_is_inline_and_does_not_change_the_password(): void
    {
        $user=User::factory()->create(['password'=>'AnteriorClave123','active'=>true,'must_change_password'=>false]);
        $this->actingAs($user)->from('/password')->post('/password',['current_password'=>'AnteriorClave123','password'=>'NuevaClave123','password_confirmation'=>'OtraClave123'])
            ->assertRedirect('/password')->assertSessionHasErrors(['password_confirmation'=>'Las contraseñas no coinciden.']);
        $this->assertTrue(Hash::check('AnteriorClave123',$user->fresh()->password));
        $this->get('/password')->assertOk()->assertSee('Las contraseñas no coinciden.')->assertSee('id="confirm-password-feedback"',false)->assertDontSee('data-kardex-alert="error"',false);
    }

    public function test_current_password_is_still_checked_by_the_server_and_valid_change_succeeds(): void
    {
        $user=User::factory()->create(['password'=>'AnteriorClave123','active'=>true,'must_change_password'=>false]);
        $this->actingAs($user)->from('/password')->post('/password',['current_password'=>'ClaveErronea123','password'=>'NuevaClave123','password_confirmation'=>'NuevaClave123'])
            ->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('AnteriorClave123',$user->fresh()->password));
        $this->get('/password')->assertOk()->assertSee('La contraseña actual es incorrecta.')->assertDontSee('data-kardex-alert="error"',false);
        $this->post('/password',['current_password'=>'AnteriorClave123','password'=>'NuevaClave123','password_confirmation'=>'NuevaClave123'])->assertRedirect('/password')->assertSessionHas('password_updated', 'Contraseña actualizada correctamente.')->assertSessionMissing('success');
        $this->assertTrue(Hash::check('NuevaClave123',$user->fresh()->password));
        $this->get('/password')->assertOk()->assertSee('Contraseña actualizada correctamente.')->assertDontSee('data-kardex-alert="success"',false);
    }
}
