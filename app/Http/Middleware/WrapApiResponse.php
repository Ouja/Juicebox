<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class WrapApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::wrap($next($request), $request);
    }

    public static function wrap(Response $response, Request $request): Response
    {
        if (! $request->is('api', 'api/*') || ! $response instanceof JsonResponse || in_array($response->getStatusCode(), [204, 304], true)) {
            return $response;
        }

        $payload = json_decode($response->getContent());

        if ($payload instanceof stdClass && property_exists($payload, 'data')) {
            if (count(get_object_vars($payload)) === 1) {
                if (is_array($payload->data)) {
                    $response->setData(['data' => ['items' => $payload->data]]);
                }

                return $response;
            }

            $payload->items = $payload->data;
            unset($payload->data);
        }

        $response->setData(['data' => is_array($payload) ? ['items' => $payload] : $payload]);

        return $response;
    }
}
