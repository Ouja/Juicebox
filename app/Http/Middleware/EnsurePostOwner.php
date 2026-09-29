<?php

namespace App\Http\Middleware;

use App\Services\PostService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePostOwner
{
    public function __construct(private PostService $posts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        $post = $this->posts->find((int) $request->route('id'));

        if ($user->id !== $post->user_id) {
            return response()->json(['message' => 'You may only modify your own posts.'], 403);
        }

        return $next($request);
    }
}
