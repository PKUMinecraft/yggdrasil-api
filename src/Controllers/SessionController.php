<?php

namespace Yggdrasil\Controllers;

use DB;
use Log;
use Cache;
use Schema;
use App\Models\User;
use App\Models\Player;
use Yggdrasil\Models\Token;
use Illuminate\Http\Request;
use Yggdrasil\Models\Profile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Yggdrasil\Exceptions\ForbiddenOperationException;

class SessionController extends Controller
{
    public function joinServer(Request $request)
    {
        $accessToken = $request->input('accessToken');
        $selectedProfile = $request->input('selectedProfile');
        $serverId = $request->input('serverId');

        Log::channel('ygg')->info("Player [$selectedProfile] is trying to join server [$serverId] with access token [$accessToken]");

        $result = DB::table('uuid')->where('uuid', $selectedProfile)->first();

        if (! $result) {
            // 据说 Mojang 在这种情况下是会返回 403 的
            throw new ForbiddenOperationException(
                trans('Yggdrasil::exceptions.uuid', ['profile' => $selectedProfile])
            );
        }

        $player = Player::where('name', $result->name)->first();

        if (! $player) {
            // 删除已失效的 UUID 映射（e.g. 其对应的角色已被删除）
            DB::table('uuid')->where('uuid', $selectedProfile)->delete();

            throw new ForbiddenOperationException(
                trans('Yggdrasil::exceptions.uuid', ['profile' => $selectedProfile])
            );
        }

        $identification = strtolower($player->user->email);

        Log::channel('ygg')->info("Player [$selectedProfile]'s name is [$player->name], belongs to user [$identification]");

        $token = Token::lookup($accessToken);
        if ($token && $token->isValid()) {

            Log::channel('ygg')->info("All access tokens issued for user [$identification] are as listed", [$token]);

            if ($token->accessToken != $accessToken) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.invalid'));
            }

            if ($token->profileId != $selectedProfile) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.player.not-matched'));
            }

            if ($player->user->permission == User::BANNED) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.banned'));
            }

            // 加入服务器，缓存 120 秒（与 hasJoinedServer 一侧对应）
            Cache::put("SERVER_$serverId", ['profile' => $selectedProfile, 'ip' => $request->ip()], 120);
        } else {
            // 指定角色所属的用户没有签发任何令牌
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.missing'));
        }

        Log::channel('ygg')->info("Player [$selectedProfile] successfully joined the server [$serverId]");

        ygg_log([
            'action' => 'join',
            'user_id' => $player->uid,
            'player_id' => $player->pid,
            'parameters' => json_encode($request->except('accessToken')),
        ]);

        return response('')->setStatusCode(204);
    }

    public function hasJoinedServer(Request $request)
    {
        $name = $request->input('username');
        $serverId = $request->input('serverId');
        $ip = $request->input('ip');

        Log::channel('ygg')->info("Checking if player [$name] has joined the server [$serverId] with IP [$ip]");

        // 检查是否进行过外置登录的 join 请求
        if ($session = Cache::get("SERVER_$serverId")) {
            $cachedProfile = is_array($session) ? ($session['profile'] ?? null) : $session;
            $cachedIp     = is_array($session) ? ($session['ip'] ?? null) : null;

            $profile = $cachedProfile ? Profile::createFromUuid($cachedProfile) : null;

            if ($profile && $name === $profile->name) {
                // IP 校验：双方都有 IP 时才比对，任一方没有则跳过
                if ($ip && $cachedIp && $ip !== $cachedIp) {
                    Log::channel('ygg')->warning("Player [$name] IP mismatch: expected [$cachedIp], got [$ip]");
                    return response('')->setStatusCode(204);
                }

                Cache::forget("SERVER_$serverId");
                Log::channel('ygg')->info("Player [$name] was in the server [$serverId]");

                $response = $profile->serialize(false);
                Log::channel('ygg')->info("Returning player [$name]'s profile", [$response]);

                ygg_log(array_merge([
                    'action' => 'has_joined',
                    'user_id' => $profile->player->uid,
                    'player_id' => $profile->player->pid,
                    'parameters' => json_encode($request->except('username')),
                ], ($ip ? compact('ip') : [])));

                return response()->json()->setContent($response);
            }
        }

        // 外置缓存未命中，尝试向 Mojang 转发验证（正版账号回落）
        if (Schema::hasTable('mojang_verifications')) {
            $profile = $this->hasJoinedMojang($name, $serverId);
            if ($profile) {
                Log::channel('ygg')->info("Player [$name] verified via Mojang, returning bound profile [{$profile->name}]");

                $response = $profile->serialize(false);

                ygg_log(array_merge([
                    'action' => 'has_joined',
                    'user_id' => $profile->player->uid,
                    'player_id' => $profile->player->pid,
                    'parameters' => json_encode($request->except('username')),
                ], ($ip ? compact('ip') : [])));

                return response()->json()->setContent($response);
            }
        }

        // 本站与 Mojang 均未命中时，向 MUA 中央站查询联盟会话。
        if (option('union_member_key') !== '') {
            Log::channel('ygg')->info("Forwarding hasJoined for player [$name] to MUA union upstream.");
            $forwarded = $this->hasJoinedUnion($name, $serverId, $ip);
            if ($forwarded !== null) {
                // 同名跨站玩家使用可用的后缀名，并同步改写、重签材质中的 profileName。
                $forwardedUuid = strtolower(str_replace('-', '', $forwarded['id'] ?? ''));
                $forwardedName = $forwarded['name'] ?? '';
                $localCollision = DB::table('uuid')
                    ->whereRaw('LOWER(name) = ?', [strtolower($forwardedName)])
                    ->whereRaw('LOWER(REPLACE(uuid, ?, ?)) <> ?', ['-', '', $forwardedUuid])
                    ->exists();

                if ($localCollision) {
                    $newName = $this->resolveCollisionName($forwardedName, $forwardedUuid);
                    Log::channel('ygg')->warning(
                        "Cross-site name collision: union profile [$forwardedName / $forwardedUuid] ".
                        "conflicts with a local player. Renaming forwarded profile to [$newName] and resigning."
                    );
                    $forwarded = $this->renameAndResign($forwarded, $newName);
                }

                Log::channel('ygg')->info("Player [$name] verified via MUA union, returning forwarded profile.");
                return response()->json($forwarded);
            }
        }

        Log::channel('ygg')->info("Player [$name] was not in the server [$serverId]");
        return response('')->setStatusCode(204);
    }

    /**
     * 把 hasJoined 透传给 MUA 中央站。返回原样的 JSON 数组（含 properties[].signature），
     * 或在中央站 204 / 5xx / 异常时返回 null 让上层走默认 204。
     */
    protected function hasJoinedUnion(string $name, string $serverId, ?string $ip): ?array
    {
        $apiRoot = option('union_api_root');
        if (! $apiRoot) {
            return null;
        }

        // union_api_root 形如 https://skin.mualliance.ltd/api/union，
        // 联盟中央站本身也是个 yggdrasil 实现，hasJoined 在 /api/yggdrasil 下。
        $base = preg_replace('#/api/union/?$#', '', rtrim($apiRoot, '/'));
        $url  = $base.'/api/yggdrasil/sessionserver/session/minecraft/hasJoined';

        $query = ['username' => $name, 'serverId' => $serverId];
        if ($ip) {
            $query['ip'] = $ip;
        }

        try {
            $response = Http::timeout(5.0)->get($url, $query);
        } catch (\Exception $e) {
            Log::channel('ygg')->warning("Union hasJoined forwarding failed: ".$e->getMessage());
            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $profile = $response->json();
        if (! is_array($profile) || empty($profile['id']) || empty($profile['name'])) {
            return null;
        }

        Log::channel('ygg')->info("Union hasJoined forwarded profile.", [$profile]);

        return $profile;
    }

    protected function hasJoinedMojang(string $name, string $serverId): ?Profile
    {
        try {
            $response = Http::get('https://sessionserver.mojang.com/session/minecraft/hasJoined', [
                'username' => $name,
                'serverId' => $serverId,
            ]);

            if ($response->status() !== 200) {
                return null;
            }

            $mojangUuid = str_replace('-', '', $response->json('id') ?? '');
            if (! $mojangUuid) {
                return null;
            }

            Log::channel('ygg')->info("Mojang verified player [$name] with uuid [$mojangUuid]");

            $binding = DB::table('mojang_verifications')
                ->where('mojang_uuid', $mojangUuid)
                ->first();

            if (! $binding) {
                // New bindings require an encrypted login proof and explicit web confirmation.
                Log::channel('ygg')->info("Mojang uuid [$mojangUuid] has no binding, rejecting.");
                return null;
            }

            $user = User::find($binding->user_id);
            if (! $user || $user->permission == User::BANNED) {
                return null;
            }

            $player = Player::where('uid', $binding->user_id)->first();
            if (! $player) {
                Log::channel('ygg')->warning("Bound user [{$binding->user_id}] has no player character — create a character on the skin server.");
                return null;
            }

            return Profile::createFromPlayer($player);
        } catch (\Exception $e) {
            Log::channel('ygg')->warning("Mojang hasJoined forwarding failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 同步修改角色名与材质中的 profileName，并使用本站配置的私钥重新签名。
     */
    protected function renameAndResign(array $profile, string $newName): array
    {
        $profile['name'] = $newName;

        $key = openssl_pkey_get_private(option('ygg_private_key'));
        if (! $key) {
            Log::channel('ygg')->warning('Cannot resign forwarded profile: private key invalid; returning unsigned rename.');
            // 私钥无效时返回未签名材质，由客户端决定是否接受。
            foreach ($profile['properties'] ?? [] as &$prop) {
                if (($prop['name'] ?? '') === 'textures') {
                    $decoded = json_decode(base64_decode($prop['value']), true);
                    if (is_array($decoded)) {
                        $decoded['profileName'] = $newName;
                        $prop['value'] = base64_encode(json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT));
                    }
                }
                unset($prop['signature']);
            }
            unset($prop);
            return $profile;
        }

        foreach ($profile['properties'] ?? [] as &$prop) {
            if (($prop['name'] ?? '') === 'textures') {
                $decoded = json_decode(base64_decode($prop['value']), true);
                if (is_array($decoded)) {
                    $decoded['profileName'] = $newName;
                    $prop['value'] = base64_encode(json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT));
                }
            }

            openssl_sign($prop['value'], $signature, $key);
            $prop['signature'] = base64_encode($signature);
        }
        unset($prop);

        openssl_free_key($key);

        return $profile;
    }

    /**
     * 撞名时给跨站 profile 起一个本地不冲突的新名字。
     *
     * 后缀来源：向 MUA 中央站查 `/profile/unmapped/byuuid/{uuid}`，
     * 取 `backend_scopes.self` 作为该 profile 所属皮肤站的简称（如 MUA / SJMC / PKUMC）。
     *
     * 如果中央站查询失败或拿不到代码，回落到 `_UNION` 后缀。
     * 加完后缀仍撞，则继续追加 `2`、`3` ……直到不撞。
     */
    protected function resolveCollisionName(string $original, string $uuid): string
    {
        $code = $this->fetchUnionBackendCode($uuid) ?: 'UNION';
        $candidate = $original.'_'.$code;
        $i = 2;
        while (DB::table('uuid')->whereRaw('LOWER(name) = ?', [strtolower($candidate)])->exists()) {
            $candidate = $original.'_'.$code.$i;
            $i++;
        }
        return $candidate;
    }

    /**
     * 向 MUA 中央站查 profile 的归属站点代码（如 "MUA" / "SJMC" / "PKUMC"）。
     * 失败返回 null，由调用方回落到默认后缀。
     */
    protected function fetchUnionBackendCode(string $uuid): ?string
    {
        $apiRoot = option('union_api_root');
        $memberKey = option('union_member_key');
        if (! $apiRoot || ! $memberKey) {
            return null;
        }

        $url = rtrim($apiRoot, '/').'/profile/unmapped/byuuid/'.$uuid;

        try {
            $response = Http::timeout(3.0)
                ->withHeaders(['X-Union-Member-Key' => $memberKey])
                ->get($url);
        } catch (\Exception $e) {
            Log::channel('ygg')->warning("Union byuuid lookup failed for [$uuid]: ".$e->getMessage());
            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || empty($data)) {
            return null;
        }

        // 端点返回数组，期望首项匹配；拿 backend_scopes.self
        $code = $data[0]['backend_scopes']['self'] ?? null;
        if (! is_string($code) || $code === '') {
            return null;
        }

        return $code;
    }

}
