<?php

namespace Yggdrasil\Utils;

use Illuminate\Support\Facades\Http;

/**
 * Small HTTP helper for calls this site makes to the MUA union central server as a member,
 * attaching the X-Union-Member-Key header every such call needs. Centralizes what used to be
 * duplicated between bootstrap.php's event-driven sync push, UnionController's manually-triggered
 * actions, and SessionController's cross-site name-collision lookup.
 */
class UnionClient
{
    public static function request(string $method, string $url, ?array $payload = null, float $timeout = 5.0)
    {
        $request = Http::timeout($timeout)
            ->withHeaders(['X-Union-Member-Key' => option('union_member_key')]);

        return $payload === null ? $request->{$method}($url) : $request->{$method}($url, $payload);
    }
}
