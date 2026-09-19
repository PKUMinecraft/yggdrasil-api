<?php

// Standalone tests: no application boot, database, credentials, or network access.
namespace Illuminate\Support\Facades {
    class Cache {
        public static $items = [];
        public static function get($key) { return self::$items[$key] ?? null; }
        public static function has($key) { return isset(self::$items[$key]); }
        public static function put($key, $value, $ttl) { self::$items[$key] = $value; }
        public static function add($key, $value, $ttl) {
            if (self::has($key)) return false;
            self::put($key, $value, $ttl);
            return true;
        }
    }
    class Http {
        public static $keys = [];
        public static $calls = 0;
        private $url;
        public static function timeout($seconds) { return new self; }
        public function get($url) { ++self::$calls; $this->url = $url; return $this; }
        public function successful() { return isset(self::$keys[$this->url]); }
        public function json($key) { return self::$keys[$this->url] ?? null; }
    }
    class Log {
        public static function channel($name) { return new self; }
        public function info(...$args) {}
    }
}
namespace Yggdrasil\Exceptions {
    class ForbiddenOperationException extends \RuntimeException {}
}
namespace Carbon {
    class Carbon { public static function now() { return '2026-01-01 00:00:00'; } }
}
namespace Vectorface\Whip {
    class Whip { public function getValidIpAddress() { return '127.0.0.1'; } }
}
namespace {
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Http;
    use Yggdrasil\Exceptions\ForbiddenOperationException;

    $root = 'https://first.example/union';
    $verbose = true;
    function option($key) { global $root; return $root; }
    function env($key) { global $verbose; return $verbose; }
    class DB {
        public static $rows = [];
        public static function table($name) { return new self; }
        public function insert($row) { self::$rows[] = $row; return true; }
    }
    require __DIR__.'/../src/Middleware/UnionHostVerify.php';
    require __DIR__.'/../src/Utils/helpers.php';
    $checks = 0;
    function check($condition, $label) {
        global $checks;
        if (!$condition) throw new \RuntimeException($label);
        ++$checks;
        echo "PASS $label\n";
    }
    function denied($action, $label) {
        try { $action(); } catch (ForbiddenOperationException $e) { check(true, $label); return; }
        throw new \RuntimeException($label);
    }
    function request($key, $nonce, $timestamp = null) {
        $timestamp = (string) ($timestamp ?? time());
        $body = '{"properties":[]}';
        openssl_sign($body.$timestamp.$nonce, $signature, $key, OPENSSL_ALGO_SHA256);
        return new class($body, $timestamp, $nonce, base64_encode($signature)) {
            public $headers;
            private $body;
            public function __construct($body, $time, $nonce, $signature) {
                $this->body = $body;
                $this->headers = ['X-Message-Timestamp' => $time, 'X-Message-Nonce' => $nonce, 'X-Message-Signature' => $signature];
            }
            public function header($key) { return $this->headers[$key] ?? null; }
            public function getContent() { return $this->body; }
        };
    }
    $a = openssl_pkey_new(['private_key_bits' => 2048]);
    $b = openssl_pkey_new(['private_key_bits' => 2048]);
    $publicA = openssl_pkey_get_details($a)['key'];
    $publicB = openssl_pkey_get_details($b)['key'];
    Http::$keys[$root] = $publicA;
    $middleware = new \Yggdrasil\Middleware\UnionHostVerify;
    $run = fn ($r) => $middleware->handle($r, fn () => 'accepted');
    $r = request($a, 'first');
    check($run($r) === 'accepted', 'valid signed callback accepted');
    denied(fn () => $run($r), 'replayed callback rejected');
    check($run(request($a, 'second')) === 'accepted' && Http::$calls === 1, 'cached key avoids another HTTP call');
    denied(fn () => $run(request($a, 'expired', time() - 60)), 'expired request rejected');
    $r = request($a, 'unsigned');
    unset($r->headers['X-Message-Signature']);
    denied(fn () => $run($r), 'unsigned restore callback rejected');
    Http::$keys[$root] = $publicB;
    check($run(request($b, 'rotated')) === 'accepted', 'key rotation refetched and accepted');
    $root = 'https://second.example/union';
    Http::$keys[$root] = $publicA;
    denied(fn () => $run(request($b, 'old-hub')), 'previous hub key rejected after changing root');
    check($run(request($a, 'new-hub')) === 'accepted', 'new hub key accepted');
    $before = Http::$calls;
    denied(fn () => $run(request($b, 'invalid-1')), 'invalid signature rejected');
    denied(fn () => $run(request($b, 'invalid-2')), 'repeated invalid signature rejected');
    check(Http::$calls === $before, 'invalid signatures cannot repeatedly force key fetches');
    $root = 'https://unavailable.example/union';
    denied(fn () => $run(request($a, 'unavailable')), 'unavailable hub fails closed');
    ygg_log(['parameters' => json_encode(['username' => 'Player', 'password' => 'secret', 'accessToken' => 'secret', 'clientToken' => 'secret', 'nested' => ['password' => 'secret']])]);
    check(json_decode(DB::$rows[0]['parameters'], true) === ['username' => 'Player'], 'log keeps diagnostic fields without credentials');
    ygg_log(['parameters' => json_encode(['username' => str_repeat('x', 300)])]);
    check(DB::$rows[1]['parameters'] === '{}', 'oversized parameters fit database column');
    $verbose = false;
    ygg_log([]);
    check(count(DB::$rows) === 2, 'logging stays opt-in');
    echo "PASS: $checks union and log checks\n";
}
