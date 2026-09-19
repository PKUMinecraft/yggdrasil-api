<?php

namespace Yggdrasil\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Yggdrasil\Exceptions\ForbiddenOperationException;

/**
 * Verifies signed requests on trusted callbacks (api/union/member/*) from the MUA union's
 * central server into this site.
 *
 * The central server attaches three headers on every callback, X-Message-Signature/Timestamp/Nonce:
 *   - signature = base64( SHA256withRSA(body + timestamp + nonce) )
 *   - the verification public key is fetched from the union_host_signature_public_key field of GET {union_api_root}
 *   - timestamp is tolerated within -10s ~ +30s; a nonce cannot be replayed within 60s
 */
class UnionHostVerify
{
    const PUBLIC_KEY_CACHE_KEY = 'union_host_signature_public_key';
    const PUBLIC_KEY_CACHE_TTL = 300; // 5 minutes

    public function handle($request, Closure $next)
    {
        $signature = $request->header('X-Message-Signature');
        $timestamp = $request->header('X-Message-Timestamp');
        $nonce = $request->header('X-Message-Nonce');
        $body = $request->getContent();

        if (! is_string($signature) || ! is_string($timestamp) || ! ctype_digit($timestamp)
            || ! is_string($nonce) || $nonce === '' || strlen($nonce) > 256) {
            Log::channel('ygg')->info('Union host verification failure: Missing signature headers.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        // Anti-replay: the same nonce is only accepted once within 60s
        $nonceKey = $this->cacheKey().':nonce:'.hash('sha256', $nonce);
        if (Cache::has($nonceKey)) {
            Log::channel('ygg')->info('Union host verification failure: Invalid nonce.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        // Timestamp skew check
        if ($timestamp < time() - 10 || $timestamp > time() + 30) {
            Log::channel('ygg')->info('Union host verification failure: Invalid timestamp.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        $payload = $body.$timestamp.$nonce;
        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false || $decodedSignature === '') {
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        $publicKey = $this->fetchPublicKey();
        if (! $publicKey) {
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        if (openssl_verify($payload, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            // The cached key may be stale if the hub rotated its signing key since we last cached
            // it. Refetch once and retry before giving up, so a legitimate rotation doesn't have
            // to wait out the cache TTL.
            $freshKey = Cache::add($this->cacheKey().':refresh', true, 10)
                ? $this->fetchPublicKey(true) : null;
            $verified = $freshKey && $freshKey !== $publicKey
                && openssl_verify($payload, $decodedSignature, $freshKey, OPENSSL_ALGO_SHA256) === 1;

            if (! $verified) {
                Log::channel('ygg')->info('Union host verification failure: Invalid signature.');
                throw new ForbiddenOperationException('Union host verification failure.');
            }
        }

        if (! Cache::add($nonceKey, true, 60)) {
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        return $next($request);
    }

    /**
     * Fetch the central server's host-callback signature public key, cached for
     * self::PUBLIC_KEY_CACHE_TTL seconds so we don't round-trip to the hub on every request.
     * Pass $forceRefresh to bypass the cache, e.g. after a signature verification failure that
     * might be caused by the hub having rotated its key since we last cached it.
     */
    protected function fetchPublicKey(bool $forceRefresh = false): ?string
    {
        if (! $forceRefresh) {
            $cached = Cache::get($this->cacheKey());
            if ($cached) {
                return $cached;
            }
        }

        try {
            $response = Http::timeout(5.0)->get(option('union_api_root'));
            $publicKey = $response->successful() ? $response->json('union_host_signature_public_key') : null;
        } catch (\Exception $e) {
            Log::channel('ygg')->info('Union host verification failure: Cannot fetch public key. '.$e->getMessage());
            return null;
        }

        if (! is_string($publicKey) || ! openssl_pkey_get_public($publicKey)) {
            Log::channel('ygg')->info('Union host verification failure: Public key missing in upstream response.');
            return null;
        }

        Cache::put($this->cacheKey(), $publicKey, self::PUBLIC_KEY_CACHE_TTL);

        return $publicKey;
    }

    protected function cacheKey(): string
    {
        return self::PUBLIC_KEY_CACHE_KEY.':'.hash('sha256', rtrim(option('union_api_root'), '/'));
    }
}
