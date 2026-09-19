<?php

// Uses an isolated in-memory DB and mocked Mojang responses; never migrates the application DB.
require ($argv[1] ?? '/var/www/blessing-skin').'/vendor/autoload.php';
$app = require ($argv[1] ?? '/var/www/blessing-skin').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/../src/Controllers/PremiumBridgeController.php';
require_once __DIR__.'/../src/Controllers/MojangBindController.php';
require_once __DIR__.'/../src/Middleware/PremiumBridgeAuth.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Yggdrasil\Controllers\PremiumBridgeController;
use Yggdrasil\Controllers\MojangBindController;

config(['database.default' => 'premium_test', 'database.connections.premium_test' => [
    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
], 'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('premium_test');
Schema::create('users', function ($t) { $t->integer('uid')->primary(); $t->integer('permission')->default(0); });
Schema::create('players', function ($t) { $t->integer('pid')->primary(); $t->integer('uid'); $t->string('name'); });
Schema::create('uuid', function ($t) { $t->string('uuid')->unique(); $t->string('name')->unique(); });
Schema::create('mojang_verifications', function ($t) { $t->integer('user_id')->unique(); $t->string('mojang_uuid')->unique(); });
Schema::create('pending_mojang_bind', function ($t) { $t->integer('user_id')->unique(); $t->string('mojang_uuid'); $t->string('mojang_name'); $t->dateTime('created_at'); });
Schema::create('premium_binding_codes', function ($t) { $t->string('mojang_uuid')->primary(); $t->string('code_hash'); $t->integer('attempts')->default(0); $t->dateTime('expires_at'); });
Schema::create('premium_binding_sessions', function ($t) { $t->string('session_hash')->primary(); $t->dateTime('expires_at'); });
Schema::create('premium_binding_metadata', function ($t) { $t->integer('user_id')->primary(); $t->string('mojang_uuid'); $t->dateTime('verified_at'); });
DB::table('users')->insert([['uid' => 1, 'permission' => 0], ['uid' => 2, 'permission' => 0]]);
DB::table('players')->insert([['pid' => 1, 'uid' => 1, 'name' => 'Hay'], ['pid' => 2, 'uid' => 2, 'name' => 'Other']]);
$local = '00000000000040008000000000000001';
$premium = '00000000000040008000000000000002';
DB::table('uuid')->insert(['name' => 'Hay', 'uuid' => $local]);
$checks = 0;
function check($condition, $label) { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo "PASS $label\n"; }
function denied($fn, $status, $code = null) {
    try { $fn(); } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
        check($e->getStatusCode() === $status, "rejection HTTP $status"); return;
    } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
        check($e->getResponse()->getStatusCode() === $status, "rejection HTTP $status");
        if ($code !== null) check($e->getResponse()->getData(true)['error'] === $code, "identity error $code");
        return;
    }
    throw new RuntimeException('Expected rejection');
}
function req(array $values) { $r = Request::create('/', 'POST', $values); $r->setLaravelSession(app('session')->driver()); return $r; }
$app->instance('request', req([]));
Http::fake(function ($request) use ($premium) {
    if (str_contains($request->url(), '/hasJoined')) {
        return $request['serverId'] === '123abc'
            ? Http::response(['id' => $premium, 'name' => 'Hyah'], 200)
            : Http::response('', 204);
    }
    if (str_contains($request->url(), '/profile/'.$premium)) return Http::response(['id' => $premium, 'name' => 'Hyah', 'properties' => []]);
    throw new RuntimeException('Unexpected HTTP request');
});
$bridge = new PremiumBridgeController();
$bind = new MojangBindController();
Auth::setUser(App\Models\User::find(1));
denied(fn () => $bridge->resolve(req(['direction' => 'outbound', 'uuid' => $local])), 403, 'BINDING_REQUIRED');
denied(fn () => $bridge->resolve(req(['direction' => 'inbound', 'uuid' => $premium])), 403, 'BINDING_REQUIRED');
DB::table('mojang_verifications')->insert(['user_id' => 1, 'mojang_uuid' => $premium]);
$legacy = $bridge->resolve(req(['direction' => 'outbound', 'uuid' => $local]))->getData(true);
check($legacy['assurance'] === 'legacy' && $legacy['premium']['id'] === $premium, 'legacy binding accepted without rebind');
check($legacy['local']['id'] === $local && $legacy['local']['name'] === 'Hay' && $legacy['premium']['name'] === 'Hyah', 'different names and local UUID retained');
$back = $bridge->resolve(req(['direction' => 'inbound', 'uuid' => $premium, 'local_uuid' => $local]))->getData(true);
check($back['local']['id'] === $local, 'return restores persisted local UUID');
denied(fn () => $bridge->resolve(req(['direction' => 'inbound', 'uuid' => $premium, 'local_uuid' => str_repeat('f', 32)])), 403);
DB::table('players')->insert(['pid' => 3, 'uid' => 1, 'name' => 'Second']);
denied(fn () => $bridge->resolve(req(['direction' => 'inbound', 'uuid' => $premium])), 409, 'CHARACTER_AMBIGUOUS');
DB::table('players')->where('pid', 3)->delete();
$bind->unbind();
denied(fn () => $bridge->resolve(req(['direction' => 'outbound', 'uuid' => $local])), 403);
DB::table('pending_mojang_bind')->insert(['user_id' => 1, 'mojang_uuid' => $premium, 'mojang_name' => 'Hyah', 'created_at' => now()]);
DB::table('pending_mojang_bind')->insert(['user_id' => 2, 'mojang_uuid' => $premium, 'mojang_name' => 'Hyah', 'created_at' => now()]);
check($bridge->candidate(req(['uuid' => $premium, 'username' => 'Hyah']))->getData(true)['candidate'], 'pending login detected');
denied(fn () => $bridge->verifyLogin(req(['username' => 'Hyah', 'server_id' => 'bad'])), 403);
check(DB::table('premium_binding_codes')->count() === 0, 'failed Mojang authentication creates no proof');
$proof = $bridge->verifyLogin(req(['username' => 'Hyah', 'server_id' => '123abc']))->getData(true);
check(DB::table('mojang_verifications')->count() === 0, 'Mojang login alone cannot auto-bind latest applicant');
denied(fn () => $bridge->verifyLogin(req(['username' => 'Hyah', 'server_id' => '123abc'])), 409);
check(preg_match('/^[0-9]{6}$/D', $proof['code']) === 1, 'issued code is exactly six digits');
$wrong = $proof['code'] === '000000' ? '000001' : '000000';
$bind->confirmBind(req(['code' => $wrong]));
check(DB::table('mojang_verifications')->count() === 0, 'invalid code cannot bind');
check(DB::table('premium_binding_codes')->value('attempts') === 1, 'failed attempt persists outside rollback');
Auth::setUser(App\Models\User::find(2));
for ($i = 0; $i < 4; ++$i) $bind->confirmBind(req(['code' => $wrong]));
Auth::setUser(App\Models\User::find(1));
$bind->confirmBind(req(['code' => $proof['code']]));
check(DB::table('mojang_verifications')->count() === 0 && DB::table('premium_binding_codes')->value('attempts') === 5, 'five failures shared across web accounts lock the code');
DB::table('pending_mojang_bind')->where('user_id', 1)->update(['created_at' => now()]);
$bind->confirmBind(req(['code' => $proof['code']]));
check(DB::table('mojang_verifications')->count() === 0, 'renewing web application cannot reset attempts');
DB::table('premium_binding_sessions')->delete();
$proof = $bridge->verifyLogin(req(['username' => 'Hyah', 'server_id' => '123abc']))->getData(true);
check(DB::table('premium_binding_codes')->count() === 1 && DB::table('premium_binding_codes')->value('attempts') === 0, 'fresh authenticated session replaces code and resets attempts');
// Deterministic leading-zero fixture exercises string handling through confirmation.
$proof['code'] = '012345';
DB::table('premium_binding_codes')->update(['code_hash' => hash('sha256', $premium.':'.$proof['code'])]);
DB::table('premium_binding_codes')->update(['expires_at' => now()->subMinute()]);
$bind->confirmBind(req(['code' => $proof['code']]));
check(DB::table('mojang_verifications')->count() === 0, 'expired code cannot bind');
DB::table('premium_binding_codes')->update(['expires_at' => now()->addMinutes(10)]);
$bind->confirmBind(req(['code' => $proof['code']]));
check(DB::table('mojang_verifications')->where('user_id', 1)->exists(), 'code holder binds their own web account despite a later competing application');
check(DB::table('premium_binding_codes')->count() === 0, 'proof consumed atomically');
check($bridge->resolve(req(['direction' => 'outbound', 'uuid' => $local]))->getData(true)['assurance'] === 'login_code', 'new binding records proof method');
Auth::setUser(App\Models\User::find(2));
$bind->confirmBind(req(['code' => $proof['code']]));
check(!DB::table('mojang_verifications')->where('user_id', 2)->exists(), 'replayed proof cannot bind another account');
denied(fn () => (new Yggdrasil\Middleware\PremiumBridgeAuth())->handle(req([]), fn () => response('unexpected')), 401);
echo "PASS: $checks isolated premium binding checks\n";
