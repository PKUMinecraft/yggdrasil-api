<?php

namespace Yggdrasil\Models;

use DB;
use Log;
use Cache;
use Schema;
use App\Models\Player;
use App\Models\Texture;
use Yggdrasil\Utils\UUID;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Yggdrasil\Exceptions\IllegalArgumentException;

class Profile
{
    public $uuid;
    public $name;
    public $player;
    public $model = "default";
    public $skin;
    public $cape;

    public function sign($data, $key)
    {
        openssl_sign($data, $sign, $key);

        return $sign;
    }

    public function serialize($unsigned = null)
    {
        // 如果没显示指定 `unsigned` 参数就从 URL 中推断
        if (is_null($unsigned)) {
            $unsigned = is_null(request('unsigned')) || request('unsigned') === 'true';
        }

        $textures = [
            'timestamp' => round(microtime(true) * 1000),
            'profileId' => UUID::format($this->uuid),
            'profileName' => $this->name,
            'isPublic' => true,
            'textures' => [],
        ];

        // 检查 RSA 私钥
        if ($unsigned === false) {
            $key = openssl_pkey_get_private(option('ygg_private_key'));

            if (! $key) {
                throw new IllegalArgumentException(
                    trans('Yggdrasil::config.rsa.invalid')
                );
            }

            $textures['signatureRequired'] = true;
        }

        // 避免 BungeeCord 服务器上可能出现无法加载材质的 Bug
        app('url')->forceRootUrl(option('site_url'));

        $hasMojangBinding = Schema::hasTable('mojang_verifications') &&
            DB::table('mojang_verifications')->where('user_id', $this->player->uid)->exists();

        if ($this->skin != "") {
            $textures['textures']['SKIN'] = [
                'url' => url("textures/{$this->skin}"),
            ];

            if ($this->model == "slim") {
                $textures['textures']['SKIN']['metadata'] = ['model' => 'slim'];
            }
        } elseif ($hasMojangBinding) {
            // 该角色未设置皮肤，从绑定的 Mojang 账号获取。
            $skin = $this->fetchProfileFromMojang('SKIN');
            if ($skin) {
                $textures['textures']['SKIN'] = $skin;
            }
        }

        if ($this->cape != "") {
            $textures['textures']['CAPE'] = [
                'url' => url("textures/{$this->cape}")
            ];
        } elseif ($hasMojangBinding) {
            // 该角色未设置披风，从绑定的 Mojang 账号获取。
            $cape = $this->fetchProfileFromMojang('CAPE');
            if ($cape) {
                $textures['textures']['CAPE'] = $cape;
            }
        }

        $result = [
            'id' => UUID::format($this->uuid),
            'name' => $this->name,
            'properties' => [
                [
                    'name' => 'textures',
                    'value' => base64_encode(
                        json_encode($textures, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT)
                    ),
                ],
            ],
        ];

        if ($unsigned === false) {
            // 给每个 properties 签名
            foreach ($result['properties'] as &$prop) {
                $signature = $this->sign($prop['value'], $key);

                $prop['signature'] = base64_encode($signature);
            }

            unset($prop);
            openssl_free_key($key);
        }

        return json_encode($result, JSON_UNESCAPED_SLASHES);
    }

    public function __toString()
    {
        return $this->serialize();
    }

    public static function getUuidFromName($name)
    {
        $result = DB::table('uuid')->where('name', $name)->first();

        if (! $result) {
            // 分配新的 UUID
            $result = UUID::generateMinecraftUuid($name)->clearDashes();

            // v3 算法 + 改名保持 UUID 后，新注册的名字可能恰好撞上别人改名前的旧 UUID
            //（按名字哈希算出来的）。撞上时回落到随机 v4，避免两个角色共用一个 UUID。
            if (DB::table('uuid')->where('uuid', $result)->exists()) {
                $result = UUID::generate(4)->clearDashes();
                Log::channel('ygg')->info("Uuid conflict on allocating for player [$name], fell back to random v4 uuid [$result]");
            }

            DB::table('uuid')->insert(['name' => $name, 'uuid' => $result]);

            Log::channel('ygg')->info("New uuid [$result] allocated to player [$name]");
        } else {
            $result = $result->uuid;
        }

        return $result;
    }

    public static function createFromUuid($uuid)
    {
        $result = DB::table('uuid')->where('uuid', $uuid)->first();

        if ($result && ($player = Player::where('name', $result->name)->first())) {
            return static::createFromPlayer($player);
        }
    }

    public static function createFromPlayer(Player $player)
    {
        $profile = new static();
        $model = 'default';
        if ($t = Texture::find($player->tid_skin)) {
            $model = $t->type == 'steve' ? 'default' : 'slim';
        }

        $profile->uuid = static::getUuidFromName($player->name);
        $profile->name = $player->name;
        $profile->model = $model;
        $profile->player = $player;
        $profile->skin = optional($player->skin)->hash;
        $profile->cape = optional($player->cape)->hash;

        return $profile;
    }

    protected function fetchProfileFromMojang($type)
    {
        $type = strtoupper($type);

        $binding = DB::table('mojang_verifications')
            ->where('user_id', $this->player->uid)
            ->first();

        if (! $binding) {
            return null;
        }

        $mojangUuid = $binding->mojang_uuid;

        $profile = Cache::get('mojang_profile_'.$mojangUuid, function () use ($mojangUuid) {
            try {
                $response = Http::get('https://sessionserver.mojang.com/session/minecraft/profile/'.$mojangUuid);
                if ($response->ok()) {
                    $body = $response->json();
                    Cache::put('mojang_profile_'.$mojangUuid, $body, 300);

                    return $body;
                } else {
                    return null;
                }
            } catch (\Exception $e) {
                return null;
            }
        });

        if (! $profile) {
            return null;
        }
        $property = Arr::first($profile['properties'], function ($item) {
            return $item['name'] === 'textures';
        });
        if (! $property) {
            return null;
        }
        return Arr::get(json_decode(base64_decode($property['value']), true)['textures'], $type);
    }
}
