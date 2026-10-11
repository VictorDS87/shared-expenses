<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MalformedWebInputTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidExpenseFields(): array
    {
        return [
            'text as array' => ['description', ['invalid']],
            'supplier as array' => ['supplier', ['invalid']],
            'amount as array' => ['amount', ['invalid']],
            'date as array' => ['date', ['invalid']],
            'currency as array' => ['currency', ['invalid']],
            'allocation collection as text' => ['allocations', 'invalid'],
            'allocation row as text' => ['allocations', ['invalid']],
            'nested percentage' => ['allocations', [['unit_id' => 1, 'percentage' => ['100']]]],
            'nested unit' => ['allocations', [['unit_id' => [1], 'percentage' => '100']]],
            'too many rows' => ['allocations', array_fill(0, 101, ['unit_id' => 1, 'percentage' => '100'])],
        ];
    }

    #[DataProvider('invalidExpenseFields')]
    public function test_invalid_expense_structure_preserves_errors_without_breaking_the_form(string $field, mixed $value): void
    {
        $this->withoutVite()->actingAs(User::factory()->create());
        $unit = Unit::create(['name' => 'Test', 'company' => 'Test', 'slug' => 'test']);
        $payload = ['description' => 'Draft expense', 'supplier' => 'Draft supplier', 'date' => '2026-01-01',
            'currency' => 'BRL', 'amount' => '1.00', 'allocations' => [['unit_id' => $unit->id, 'percentage' => '100']]];
        $payload[$field] = $value;
        $this->from('/despesas/nova')->post('/despesas', $payload)->assertRedirect('/despesas/nova')->assertSessionHasErrors();
        $this->get('/despesas/nova')->assertOk();
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_array_in_login_email_returns_validation_feedback(): void
    {
        $this->withoutVite();
        $this->from('/login')->post('/login', ['email' => ['invalid'], 'password' => 'wrong'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->get('/login')->assertOk();
        $this->assertGuest();
    }

    public function test_array_in_api_login_email_is_rejected_without_a_server_error(): void
    {
        $this->postJson('/api/v1/auth/token', ['email' => ['invalid'], 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidUnitFields(): array
    {
        return [['name'], ['company'], ['slug']];
    }

    #[DataProvider('invalidUnitFields')]
    public function test_array_in_unit_fields_returns_validation_feedback(string $field): void
    {
        $this->withoutVite()->actingAs(User::factory()->create());
        $payload = ['name' => 'Draft unit', 'company' => 'Test', 'slug' => 'draft-unit'];
        $payload[$field] = ['invalid'];
        $this->from('/unidades')->post('/unidades', $payload)->assertRedirect('/unidades')->assertSessionHasErrors($field);
        $this->get('/unidades')->assertOk();
        $this->assertDatabaseCount('units', 0);
    }

    public function test_valid_draft_values_survive_an_invalid_split(): void
    {
        $this->withoutVite()->actingAs(User::factory()->create());
        $unit = Unit::create(['name' => 'Test', 'company' => 'Test', 'slug' => 'test']);
        $payload = ['description' => 'Preserved draft', 'supplier' => 'Preserved supplier', 'date' => '2026-01-01',
            'currency' => 'BRL', 'amount' => '10.99', 'allocations' => [['unit_id' => $unit->id, 'percentage' => '90']]];
        $this->from('/despesas/nova')->post('/despesas', $payload)->assertRedirect('/despesas/nova')->assertSessionHasErrors('allocations');
        $this->get('/despesas/nova')->assertOk()->assertSee('value="Preserved draft"', false)
            ->assertSee('value="Preserved supplier"', false)->assertSee('value="10.99"', false)->assertSee('value="90"', false);
    }

    public static function invalidDateQueries(): array
    {
        return array_map(fn ($path) => [$path], [
            '/relatorios?from[]=bad&to=2025-04-01',
            '/relatorios/exportar?from[]=bad&to=2025-04-01',
            '/relatorios?from=2025-04-01&to[]=bad',
            '/despesas?from[]=bad&to=2025-04-01',
            '/api/v1/expenses?from[]=bad&to=2025-04-01',
            '/api/v1/reports/units?from[]=bad&to=2025-04-01',
            '/api/v1/reports/units?from=2025-04-01&to[]=bad',
        ]);
    }

    #[DataProvider('invalidDateQueries')]
    public function test_invalid_date_structure_is_rejected_before_comparing_the_period(string $path): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson($path)->assertUnprocessable()->assertJsonValidationErrors(str_contains($path, 'from[]') ? 'from' : 'to');
    }
}
