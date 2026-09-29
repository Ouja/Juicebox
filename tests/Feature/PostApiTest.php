<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_author_can_create_a_post(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/posts', ['title' => 'Hello Perth', 'body' => 'A first post.'])
            ->assertCreated()->assertJsonPath('data.title', 'Hello Perth')
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('posts', ['title' => 'Hello Perth', 'body' => 'A first post.', 'user_id' => $user->id]);
    }

    public function test_list_is_paginated_and_ordered_newest_first(): void
    {
        $user = User::factory()->create();
        Post::factory()->for($user)->create(['created_at' => '2026-01-01']);
        $latest = Post::factory()->for($user)->create(['created_at' => '2026-01-02']);
        Sanctum::actingAs($user);

        $this->getJson('/api/posts?per_page=1')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $latest->id)->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.meta.per_page', 1)->assertJsonStructure(['data' => ['links' => ['next']]]);
    }

    public function test_authenticated_user_can_view_another_authors_post(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/posts/'.$post->id)->assertOk()
            ->assertJsonPath('data.body', $post->body)->assertJsonMissingPath('data.user.email')
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_post_list_eager_loads_authors_without_per_post_queries(): void
    {
        $user = User::factory()->create();
        Post::factory()->count(5)->create();
        Sanctum::actingAs($user);
        $this->expectsDatabaseQueryCount(3);

        $this->getJson('/api/posts')->assertOk()->assertJsonCount(5, 'data.items')
            ->assertJsonStructure(['data' => ['items' => [['user' => ['id', 'name']]]]]);
    }

    public function test_author_can_patch_only_the_title(): void
    {
        $post = Post::factory()->create(['body' => 'Keep this content.']);
        Sanctum::actingAs($post->user);

        $this->patchJson('/api/posts/'.$post->id, ['title' => 'Updated title'])
            ->assertOk()->assertJsonPath('data.title', 'Updated title')->assertJsonPath('data.body', 'Keep this content.');

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Updated title', 'body' => 'Keep this content.']);
    }

    public function test_author_can_soft_delete_a_post(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs($post->user);

        $this->deleteJson('/api/posts/'.$post->id)->assertNoContent();

        $this->assertSoftDeleted($post);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => $post->title, 'body' => $post->body]);
    }

    public function test_deleted_posts_are_excluded_from_list_and_pagination_total(): void
    {
        $user = User::factory()->create();
        Post::factory()->for($user)->trashed()->create();
        $active = Post::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/posts')->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $active->id)->assertJsonPath('data.meta.total', 1);
    }

    /** @return array<string, array{string}> */
    public static function deletedPostMethods(): array
    {
        return ['read' => ['GET'], 'update' => ['PATCH'], 'delete again' => ['DELETE']];
    }

    #[DataProvider('deletedPostMethods')]
    public function test_deleted_post_returns_404_without_changing_the_record(string $method): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->trashed()->create(['title' => 'Original']);
        Sanctum::actingAs($user);

        $this->json($method, '/api/posts/'.$post->id, ['title' => 'Changed'])->assertNotFound();

        $this->assertSoftDeleted($post);
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Original']);
    }

    /** @return array<string, array{string}> */
    public static function writeMethods(): array
    {
        return ['update' => ['PATCH'], 'delete' => ['DELETE']];
    }

    #[DataProvider('writeMethods')]
    public function test_other_authors_write_returns_403_without_changes(string $method): void
    {
        $post = Post::factory()->create(['title' => 'Original']);
        Sanctum::actingAs(User::factory()->create());

        $this->json($method, '/api/posts/'.$post->id, ['title' => 'Stolen'])->assertForbidden()
            ->assertExactJson(['data' => ['message' => 'You may only modify your own posts.']]);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Original']);
        $this->assertNotSoftDeleted($post);
    }

    /** @return array<string, array{string, string}> */
    public static function protectedRoutes(): array
    {
        return [
            'list posts' => ['GET', '/api/posts'],
            'read post' => ['GET', '/api/posts/1'],
            'create post' => ['POST', '/api/posts'],
            'update post' => ['PATCH', '/api/posts/1'],
            'delete post' => ['DELETE', '/api/posts/1'],
            'list users' => ['GET', '/api/users'],
            'read user' => ['GET', '/api/users/1'],
            'weather' => ['GET', '/api/weather'],
            'logout' => ['POST', '/api/logout'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_protected_routes_return_json_401_without_a_token(string $method, string $path): void
    {
        $this->call($method, $path)->assertUnauthorized()->assertJsonPath('data.message', 'Unauthenticated.');
    }

    public function test_missing_post_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/posts/9999')->assertNotFound();
    }

    #[DataProvider('protectedRoutes')]
    public function test_invalid_token_returns_json_401_before_validation_or_resource_lookup(string $method, string $path): void
    {
        $this->withToken('invalid-token')->call($method, $path)->assertUnauthorized()
            ->assertExactJson(['data' => ['message' => 'Unauthenticated.']]);
    }

    public function test_owner_middleware_returns_403_before_update_validation(): void
    {
        $post = Post::factory()->create(['title' => 'Original']);
        Sanctum::actingAs(User::factory()->create());

        $this->call('PATCH', '/api/posts/'.$post->id)->assertForbidden()
            ->assertExactJson(['data' => ['message' => 'You may only modify your own posts.']]);

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Original', 'deleted_at' => null]);
    }

    /** @return array<string, array{array<string, mixed>, array<string>}> */
    public static function invalidPosts(): array
    {
        return [
            'missing fields' => [[], ['title', 'body']],
            'empty title' => [['title' => ' ', 'body' => 'Body'], ['title']],
            'oversized title' => [['title' => str_repeat('a', 256), 'body' => 'Body'], ['title']],
            'oversized body' => [['title' => 'Title', 'body' => str_repeat('a', 10001)], ['body']],
            'array body' => [['title' => 'Title', 'body' => ['value']], ['body']],
            'forged owner' => [['title' => 'Title', 'body' => 'Body', 'user_id' => 99], ['user_id']],
        ];
    }

    #[DataProvider('invalidPosts')]
    public function test_invalid_post_returns_422_without_creating_record(array $payload, array $fields): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/posts', $payload)->assertUnprocessable()->assertJsonValidationErrors($fields, 'data.errors');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_empty_patch_returns_422_without_changes(): void
    {
        $post = Post::factory()->create(['title' => 'Original']);
        Sanctum::actingAs($post->user);

        $this->patchJson('/api/posts/'.$post->id, [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'body'], 'data.errors');

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Original']);
    }

    public function test_post_ownership_cannot_be_changed(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs($post->user);

        $this->patchJson('/api/posts/'.$post->id, ['title' => 'Updated', 'user_id' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id', 'data.errors');

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'user_id' => $post->user_id, 'title' => $post->title]);
    }

    /** @return array<string, array{string}> */
    public static function invalidPagination(): array
    {
        return ['too many' => ['per_page=101'], 'zero' => ['per_page=0'], 'negative page' => ['page=-1'], 'array page' => ['page[]=1']];
    }

    #[DataProvider('invalidPagination')]
    public function test_invalid_pagination_returns_422(string $query): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/posts?'.$query)->assertUnprocessable();
    }
}
