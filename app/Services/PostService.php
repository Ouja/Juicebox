<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use App\Repositories\PostRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PostService
{
    public function __construct(private PostRepository $posts) {}

    /** @return LengthAwarePaginator<int, Post> */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->posts->paginate($perPage);
    }

    public function find(int $id): Post
    {
        return $this->posts->findOrFail($id);
    }

    /** @param array{title: string, body: string} $attributes */
    public function create(User $user, array $attributes): Post
    {
        return $this->posts->create($user, $attributes);
    }

    /** @param array{title?: string, body?: string} $attributes */
    public function update(int $id, array $attributes): Post
    {
        $post = $this->posts->findOrFail($id);

        return $this->posts->update($post, $attributes);
    }

    public function delete(int $id): void
    {
        $post = $this->posts->findOrFail($id);
        $this->posts->delete($post);
    }
}
