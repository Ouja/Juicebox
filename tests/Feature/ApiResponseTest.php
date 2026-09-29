<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, string, int}> */
    public static function errorRequests(): array
    {
        return [
            'authentication' => ['GET', '/api/posts', 401],
            'validation' => ['POST', '/api/register', 422],
            'unknown route' => ['GET', '/api/unknown', 404],
            'method not allowed' => ['GET', '/api/login', 405],
        ];
    }

    #[DataProvider('errorRequests')]
    public function test_error_responses_only_have_data_at_the_top_level(string $method, string $path, int $status): void
    {
        $response = $this->call($method, $path);

        $response->assertStatus($status)->assertJsonStructure(['data' => ['message']]);
        $this->assertSame(['data'], array_keys($response->json()));
        $response->assertJsonMissingPath('data.data');
    }

    public function test_unexpected_errors_are_wrapped_without_changing_status(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/test-error', function (): never {
            throw new \RuntimeException('Private error detail');
        });

        $this->getJson('/api/test-error')->assertInternalServerError()
            ->assertExactJson(['data' => ['message' => 'Server Error']]);
    }

    public function test_paginated_responses_keep_items_links_and_meta_inside_data(): void
    {
        $user = User::factory()->create();
        Post::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/posts');

        $response->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.meta.total', 1)->assertJsonStructure(['data' => ['items', 'links', 'meta']]);
        $this->assertSame(['data'], array_keys($response->json()));
    }

    public function test_login_keeps_user_and_token_inside_data(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);
        $this->assertSame(['data'], array_keys($response->json()));
    }

    public function test_single_resources_are_not_double_wrapped(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/users/'.$user->id);

        $response->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissingPath('data.data');
        $this->assertSame(['data'], array_keys($response->json()));
    }
}
