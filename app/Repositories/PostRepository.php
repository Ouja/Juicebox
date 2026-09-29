<?php

namespace App\Repositories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PostRepository
{
    /** @return LengthAwarePaginator<int, Post> */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Post::query()->with('user')->latest()->orderByDesc('id')->paginate($perPage);
    }

    public function findOrFail(int $id): Post
    {
        return Post::query()->with('user')->findOrFail($id);
    }

    /** @param array{title: string, body: string} $attributes */
    public function create(User $user, array $attributes): Post
    {
        return $user->posts()->create($attributes)->load('user');
    }

    /** @param array{title?: string, body?: string} $attributes */
    public function update(Post $post, array $attributes): Post
    {
        $post->update($attributes);

        return $post;
    }

    public function delete(Post $post): void
    {
        $post->delete();
    }
}
