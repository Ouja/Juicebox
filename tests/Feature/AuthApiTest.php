<?php

namespace Tests\Feature;

use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_creates_user_token_and_welcome_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Taylor',
            'email' => 'Taylor@Example.com',
            'password' => 'Securepass123',
            'password_confirmation' => 'Securepass123',
            'email_verified_at' => '2026-01-01',
        ]);

        $response->assertCreated()->assertJsonPath('data.user.name', 'Taylor')
            ->assertJsonPath('data.token_type', 'Bearer')->assertJsonMissingPath('data.user.password');
        $user = User::query()->sole();
        $this->assertSame('taylor@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Securepass123', $user->password));
        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertSame($user->id, $token->tokenable_id);
        Queue::assertPushed(SendWelcomeEmail::class, fn (SendWelcomeEmail $job): bool => $job->userId === $user->id && $job->afterCommit === true);
        Queue::assertCount(1);
    }

    public function test_duplicate_email_returns_422_without_creating_user_or_job(): void
    {
        User::factory()->create(['email' => 'taylor@example.com']);
        Queue::fake();

        $this->postJson('/api/register', [
            'name' => 'Another Taylor',
            'email' => 'TAYLOR@example.com',
            'password' => 'Securepass123',
            'password_confirmation' => 'Securepass123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email', 'data.errors')
            ->assertJsonPath('data.errors.email.0', 'The email has already been taken.');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Queue::assertNothingPushed();
    }

    public function test_registration_requires_valid_fields_without_side_effects(): void
    {
        Queue::fake();

        $this->postJson('/api/register', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password'], 'data.errors');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidRegistration(): array
    {
        return [
            'invalid email' => [['email' => 'invalid'], 'email'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'short password' => [['password' => 'Ab1', 'password_confirmation' => 'Ab1'], 'password'],
            'password without digits' => [['password' => 'longpassword', 'password_confirmation' => 'longpassword'], 'password'],
            'mismatched confirmation' => [['password_confirmation' => 'Otherpass123'], 'password'],
            'oversized password' => [['password' => str_repeat('a', 73)], 'password'],
            'multibyte password' => [['password' => str_repeat('é', 40).'1', 'password_confirmation' => str_repeat('é', 40).'1'], 'password'],
            'null byte password' => [['password' => "Secret123\0", 'password_confirmation' => "Secret123\0"], 'password'],
        ];
    }

    #[DataProvider('invalidRegistration')]
    public function test_invalid_registration_returns_422(array $changes, string $field): void
    {
        Queue::fake();

        $this->postJson('/api/register', array_replace([
            'name' => 'Taylor', 'email' => 'taylor@example.com',
            'password' => 'Securepass123', 'password_confirmation' => 'Securepass123',
        ], $changes))->assertUnprocessable()->assertJsonValidationErrors($field, 'data.errors');

        $this->assertDatabaseCount('users', 0);
        Queue::assertNothingPushed();
    }

    public function test_login_returns_a_usable_bearer_token(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/login', ['email' => strtoupper($user->email), 'password' => 'password']);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id)->assertJsonMissingPath('data.user.password');
        $this->withToken($response->json('data.token'))->getJson('/api/users/'.$user->id)
            ->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_password_that_looks_like_a_hash_is_still_hashed_as_plaintext(): void
    {
        Queue::fake();
        $password = Hash::make('Secret123');

        $this->postJson('/api/register', [
            'name' => 'Taylor', 'email' => 'taylor@example.com',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertCreated();

        $user = User::query()->sole();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check('Secret123', $user->password));
        Queue::assertPushed(SendWelcomeEmail::class);
    }

    /** @return array<string, array{bool}> */
    public static function credentialCases(): array
    {
        return ['wrong password' => [true], 'unknown user' => [false]];
    }

    #[DataProvider('credentialCases')]
    public function test_invalid_credentials_return_401_without_issuing_token(bool $existing): void
    {
        if ($existing) {
            User::factory()->create(['email' => 'taylor@example.com']);
        }

        $this->postJson('/api/login', ['email' => 'taylor@example.com', 'password' => 'wrong'])
            ->assertUnauthorized()->assertJsonPath('data.message', 'The provided credentials are incorrect.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_requires_valid_fields(): void
    {
        $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password'], 'data.errors');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_revokes_only_the_presented_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');
        $other = $user->createToken('other');

        $this->withToken($current->plainTextToken)->postJson('/api/logout')->assertNoContent();

        $this->assertModelMissing($current->accessToken);
        $this->assertModelExists($other->accessToken);
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/posts')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/posts')->assertOk();
    }

    public function test_expired_token_returns_401(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['*'], now()->subMinute());

        $this->withToken($token->plainTextToken)->getJson('/api/posts')->assertUnauthorized();
    }

    public function test_login_rate_limit_returns_429(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertUnauthorized();
        }

        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
