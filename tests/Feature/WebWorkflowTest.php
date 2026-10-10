<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_protected_pages_redirect_to_login_and_login_renders(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/unidades')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Grid Finance');
    }

    public function test_login_and_logout_control_web_access(): void
    {
        $user = User::factory()->create(['password' => 'Example-password!']);
        $this->post('/login', ['email' => $user->email, 'password' => 'Example-password!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->get('/unidades')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/unidades')->assertRedirect('/login');
    }

    public function test_invalid_credentials_do_not_authenticate(): void
    {
        $user = User::factory()->create();
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_units_can_be_created_and_edited_without_changing_their_identity(): void
    {
        $this->actingAs(User::factory()->create());
        $data = ['name' => 'Operações', 'company' => 'Grupo', 'slug' => 'operacoes'];
        $this->from('/unidades')->post('/unidades', $data)->assertRedirect('/unidades')->assertSessionHas('success');
        $unit = Unit::sole();
        $this->from('/unidades')->put('/unidades/'.$unit->id, [...$data, 'name' => 'Operações Brasil'])
            ->assertRedirect('/unidades')->assertSessionHas('success');
        $this->assertSame('Operações Brasil', $unit->refresh()->name);
        $this->assertDatabaseCount('units', 1);
        $this->get('/unidades')->assertOk()->assertSee('Operações Brasil')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_invalid_unit_data_is_rejected_without_partial_writes(): void
    {
        $this->actingAs(User::factory()->create());
        Unit::create(['name' => 'Operações', 'company' => 'Grupo', 'slug' => 'operacoes']);
        $this->from('/unidades')->post('/unidades', ['name' => 'Outra', 'company' => 'Grupo', 'slug' => 'operacoes'])
            ->assertRedirect('/unidades')->assertSessionHasErrors('slug');
        $this->post('/unidades', ['name' => '', 'company' => 'Grupo', 'slug' => 'Espaços Inválidos'])
            ->assertSessionHasErrors(['name', 'slug']);
        $this->assertDatabaseCount('units', 1);
    }

    public function test_empty_database_has_a_usable_units_page(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/')->assertRedirect('/unidades');
        $this->get('/unidades')->assertOk()->assertSee('Comece pela estrutura do grupo');
    }

    public function test_seed_is_repeatable_without_resetting_credentials(): void
    {
        $this->seed();
        $original = User::first()->password;
        $this->seed();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('units', 5);
        $this->assertSame($original, User::first()->password);
        $this->actingAs(User::first())->get('/unidades')->assertOk();
    }
}
