<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_read_their_profile_without_sensitive_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/users/'.$user->id)->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'created_at' => $user->created_at->toISOString(),
            ]]);
    }

    public function test_other_user_profile_hides_email(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/users/'.$user->id)->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id, 'name' => $user->name,
                'created_at' => $user->created_at->toISOString(),
            ]]);
    }

    public function test_users_list_is_paginated(): void
    {
        $users = User::factory()->count(3)->create();
        Sanctum::actingAs($users[0]);

        $this->getJson('/api/users?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $users[1]->id)->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.current_page', 2)->assertJsonMissingPath('data.items.0.email');
    }

    public function test_missing_user_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/users/9999')->assertNotFound();
    }

    public function test_invalid_user_id_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/users/not-an-id')->assertNotFound();
    }
}
