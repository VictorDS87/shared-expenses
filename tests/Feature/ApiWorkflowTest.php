<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateExpense;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ApiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->startOfDay());
        Http::preventStrayRequests();
    }

    private function authenticate(): User
    {
        $user = User::factory()->create(['email' => 'financeiro@example.test']);
        $response = $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'PHPUnit']);
        $response->assertCreated();
        $this->withToken($response->json('token'));

        return $user;
    }

    #[DataProvider('protectedEndpoints')]
    public function test_corporate_api_requires_authentication(string $method, string $uri): void
    {
        $this->json($method, '/api/v1/'.$uri)->assertUnauthorized();
    }

    public static function protectedEndpoints(): array
    {
        return [
            ['GET', 'units'], ['POST', 'units'], ['PUT', 'units/999'],
            ['DELETE', 'auth/token'], ['GET', 'expenses'], ['POST', 'expenses'], ['GET', 'expenses/999'], ['GET', 'reports/units'],
        ];
    }

    public function test_login_issues_only_a_hashed_eight_hour_token_and_bad_credentials_issue_none(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $response = $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password']);
        $response->assertCreated()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('expires_in', 28800);
        [, $secret] = explode('|', $response->json('token'), 2);
        $token = PersonalAccessToken::sole();
        $this->assertSame(hash('sha256', $secret), $token->token);
        $this->assertNotSame($secret, $token->token);
        $this->assertTrue($token->expires_at->equalTo(now()->addHours(8)));
        $this->withToken($response->json('token'))->getJson('/api/v1/units')->assertOk();
    }

    public function test_token_expiration_is_enforced(): void
    {
        $this->authenticate();
        $this->getJson('/api/v1/units')->assertOk();
        $this->travelTo(now()->addHours(8)->addSecond());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/units')->assertUnauthorized();
    }

    public function test_revoking_current_token_leaves_another_device_valid(): void
    {
        $user = $this->authenticate();
        $otherToken = $user->createToken('Outro dispositivo', ['*'], now()->addHours(8))->plainTextToken;
        $this->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/units')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/units')->assertOk();
    }

    public function test_login_throttling_limits_repeated_password_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/token', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/token', ['email' => 'unknown@example.test', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_units_can_be_created_updated_and_listed_with_unique_valid_slugs(): void
    {
        $this->authenticate();
        $payload = ['name' => 'Centro de serviços', 'company' => 'Grupo Gridmidia', 'slug' => 'centro-servicos'];
        $id = $this->postJson('/api/v1/units', $payload)->assertCreated()->json('data.id');
        $this->putJson('/api/v1/units/'.$id, [...$payload, 'name' => 'Serviços compartilhados'])
            ->assertOk()->assertJsonPath('data.name', 'Serviços compartilhados');
        $this->postJson('/api/v1/units', $payload)->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson('/api/v1/units', [...$payload, 'slug' => 'Inválido Com Espaços'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson('/api/v1/units', [...$payload, 'slug' => 'outra-unidade', 'company' => str_repeat('A', 121)])
            ->assertUnprocessable()->assertJsonValidationErrors('company');
        $this->getJson('/api/v1/units')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'centro-servicos');
    }

    private function payload(string $currency = 'BRL'): array
    {
        $first = Unit::firstOrCreate(['slug' => 'operacoes'], ['name' => 'Operações', 'company' => 'Grupo Gridmidia']);
        $second = Unit::firstOrCreate(['slug' => 'tecnologia'], ['name' => 'Tecnologia', 'company' => 'Grupo Gridmidia']);

        return [
            'description' => 'Licença corporativa', 'supplier' => 'Software Brasil',
            'date' => '2026-10-09', 'amount' => '0.01', 'currency' => $currency,
            'allocations' => [['unit_id' => $first->id, 'percentage' => '50'], ['unit_id' => $second->id, 'percentage' => '50']],
        ];
    }

    public function test_expense_creation_returns_exact_decimal_resource_and_persisted_allocations(): void
    {
        $this->authenticate();
        $response = $this->postJson('/api/v1/expenses', $this->payload())->assertCreated();
        $response->assertJsonPath('data.amount', '0.01')->assertJsonPath('data.amount_minor', 1)
            ->assertJsonPath('data.brl_amount', '0.01')->assertJsonPath('data.status', 'converted')
            ->assertJsonPath('data.allocations.0.amount_minor', 1)->assertJsonPath('data.allocations.1.amount_minor', 0)
            ->assertJsonPath('data.allocations.0.percentage', '50.00');
        $this->getJson('/api/v1/expenses/'.$response->json('data.id'))->assertOk()
            ->assertJsonPath('data.allocations.0.unit.slug', 'operacoes');
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('allocations', 2);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_financial_validation_is_exposed_as_422_with_no_partial_writes(): void
    {
        $this->authenticate();
        $payload = $this->payload();
        $this->postJson('/api/v1/expenses', [...$payload, 'amount' => 10.12])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $payload['allocations'][1]['percentage'] = '49.99';
        $this->postJson('/api/v1/expenses', $payload)->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_listing_combines_filters_includes_boundaries_and_paginates_stably(): void
    {
        $this->authenticate();
        $base = $this->payload();
        foreach ([['2026-10-01', 'Alpha'], ['2026-10-09', 'Beta'], ['2026-09-30', 'Antes']] as [$date, $description]) {
            app(CreateExpense::class)->handle([...$base, 'date' => $date, 'description' => $description]);
        }

        $query = '/api/v1/expenses?from=2026-10-01&to=2026-10-09&status=converted&currency=BRL&per_page=1';
        $response = $this->getJson($query)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.description', 'Beta');
        $this->assertStringContainsString('status=converted', $response->json('links.next'));
        $this->getJson($query.'&page=2')->assertOk()->assertJsonPath('data.0.description', 'Alpha');
        $this->getJson('/api/v1/expenses?search=Software')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/expenses?search=Alpha')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/expenses?to=2026-09-30')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/expenses?from=2026-10-09')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/expenses?from=2026-10-09&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/expenses?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }
}
