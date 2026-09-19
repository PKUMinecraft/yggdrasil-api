<?php

namespace Yggdrasil\Controllers;

use App\Models\Player;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Yggdrasil\Models\Profile;

class PremiumBridgeController extends Controller
{
    private static function requireIdentity($condition, int $status, string $code): void
    {
        if (! $condition) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['error' => $code], $status));
        }
    }

    public static function uuid(string $value): string
    {
        $value = strtolower(str_replace('-', '', $value));
        abort_unless(preg_match('/^[0-9a-f]{32}$/D', $value), 422);
        return $value;
    }

    public function candidate(Request $request)
    {
        $uuid = self::uuid((string) $request->input('uuid'));
        $pending = DB::table('pending_mojang_bind')
            ->where('mojang_uuid', $uuid)
            ->whereRaw('LOWER(mojang_name) = ?', [strtolower((string) $request->input('username'))])
            ->where('created_at', '>=', now()->subMinutes(15))->exists();
        $bound = DB::table('mojang_verifications')->where('mojang_uuid', $uuid)->exists();

        return response()->json(['candidate' => $pending && ! $bound]);
    }

    public function verifyLogin(Request $request)
    {
        $name = (string) $request->input('username');
        $serverId = (string) $request->input('server_id');
        abort_unless(preg_match('/^[A-Za-z0-9_]{1,16}$/D', $name)
            && preg_match('/^-?[0-9a-f]{1,40}$/D', $serverId), 422);
        $response = Http::timeout(5)->get('https://sessionserver.mojang.com/session/minecraft/hasJoined', [
            'username' => $name, 'serverId' => $serverId,
        ]);
        abort_unless($response->status() === 200, 403, 'Mojang session verification failed.');
        $uuid = self::uuid((string) $response->json('id'));
        abort_unless(strcasecmp((string) $response->json('name'), $name) === 0, 403);
        abort_if(DB::table('mojang_verifications')->where('mojang_uuid', $uuid)->exists(), 409);
        abort_unless(DB::table('pending_mojang_bind')->where('mojang_uuid', $uuid)
            ->where('created_at', '>=', now()->subMinutes(15))->exists(), 403);
        // A successful session can issue only one proof, even if hasJoined is replayed.
        DB::table('premium_binding_sessions')->where('expires_at', '<', now())->delete();
        abort_unless(DB::table('premium_binding_sessions')->insertOrIgnore([
            'session_hash' => hash('sha256', $uuid.':'.$serverId), 'expires_at' => now()->addMinutes(15),
        ]) === 1, 409);
        $code = sprintf('%06d', random_int(0, 999999));
        DB::table('premium_binding_codes')->where('expires_at', '<', now())->delete();
        // Only a fresh authenticated Mojang session can replace a code and reset its budget.
        DB::table('premium_binding_codes')->updateOrInsert(['mojang_uuid' => $uuid], [
            'code_hash' => hash('sha256', $uuid.':'.$code), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]);

        return response()->json(['code' => $code, 'expires_in' => 600]);
    }

    public function resolve(Request $request)
    {
        $direction = $request->input('direction');
        abort_unless(in_array($direction, ['outbound', 'inbound'], true), 422);
        $id = self::uuid((string) $request->input('uuid'));
        if ($direction === 'outbound') {
            $row = DB::table('uuid')->whereRaw('LOWER(REPLACE(uuid, ?, ?)) = ?', ['-', '', $id])->first();
            $player = $row ? Player::where('name', $row->name)->first() : null;
            self::requireIdentity($player, 403, 'LOCAL_ACCOUNT_MISSING');
            $binding = DB::table('mojang_verifications')->where('user_id', $player->uid)->first();
        } else {
            $binding = DB::table('mojang_verifications')->where('mojang_uuid', $id)->first();
            self::requireIdentity($binding, 403, 'BINDING_REQUIRED');
            $query = Player::where('uid', $binding->user_id);
            if ($request->filled('local_uuid')) {
                $localId = self::uuid((string) $request->input('local_uuid'));
                $row = DB::table('uuid')->whereRaw('LOWER(REPLACE(uuid, ?, ?)) = ?', ['-', '', $localId])->first();
                self::requireIdentity($row, 403, 'CHARACTER_CHANGED');
                $query->where('name', $row->name);
            }
            $players = $query->limit(2)->get();
            self::requireIdentity($players->count() > 0, $request->filled('local_uuid') ? 403 : 409,
                $request->filled('local_uuid') ? 'CHARACTER_CHANGED' : 'CHARACTER_REQUIRED');
            self::requireIdentity($players->count() === 1, 409, 'CHARACTER_AMBIGUOUS');
            $player = $players->first();
        }
        self::requireIdentity($binding, 403, 'BINDING_REQUIRED');
        $user = User::find($binding->user_id);
        self::requireIdentity($user && $user->permission != User::BANNED, 403, 'ACCOUNT_UNAVAILABLE');
        // Read the persisted UUID. Never derive a new identity from a mutable player name.
        $local = DB::table('uuid')->where('name', $player->name)->first();
        self::requireIdentity($local, 403, 'CHARACTER_CHANGED');
        $premiumId = self::uuid($binding->mojang_uuid);
        $premium = Cache::remember('bridge-premium-profile:'.$premiumId, 60, function () use ($premiumId) {
            $response = Http::timeout(5)->get('https://sessionserver.mojang.com/session/minecraft/profile/'.$premiumId, ['unsigned' => 'false']);
            abort_unless($response->status() === 200, 503, 'Mojang profile unavailable.');
            $body = $response->json();
            abort_unless(self::uuid((string) ($body['id'] ?? '')) === $premiumId
                && preg_match('/^[A-Za-z0-9_]{1,16}$/D', $body['name'] ?? ''), 503);
            return $body;
        });

        return response()->json([
            'local' => ['id' => self::uuid($local->uuid), 'name' => $player->name, 'properties' => []],
            'premium' => $premium,
            'assurance' => DB::table('premium_binding_metadata')->where('user_id', $binding->user_id)
                ->where('mojang_uuid', $premiumId)->exists() ? 'login_code' : 'legacy',
        ]);
    }
}
