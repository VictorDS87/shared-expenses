<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            ['DELETE', 'auth/token'],
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
}
