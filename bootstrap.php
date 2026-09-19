<?php

use App\Events\PlayerWasAdded;
use App\Events\PlayerWillBeDeleted;
use App\Services\Hook;
use Blessing\Filter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Yggdrasil\Utils\UnionClient;
use Illuminate\Support\Facades\Log;
use Yggdrasil\Models\Profile;

require __DIR__.'/src/Utils/helpers.php';

return function (Filter $filter, Dispatcher $events) {
    if (env('YGG_VERBOSE_LOG')) {
        config(['logging.channels.ygg' => [
            'driver' => 'single',
            'path' => ygg_log_path(),
        ]]);
    } else {
        config(['logging.channels.ygg' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ]]);
    }

    // 从旧版升级上来的默认继续使用旧的 UUID 生成算法
    if (DB::table('uuid')->count() > 0 && !Option::get('ygg_uuid_algorithm')) {
        Option::set('ygg_uuid_algorithm', 'v4');
    }

    // 初次使用自动生成私钥
    if (option('ygg_private_key') == '') {
        option(['ygg_private_key' => ygg_generate_rsa_keys()['private']]);
    }

    // 记录访问详情
    if (request()->is('api/yggdrasil/*')) {
        ygg_log_http_request_and_response();
    }

    // 保证用户修改角色名后 UUID 一致
    $callback = function ($model) {
        $new = $model->getAttribute('name');
        $original = $model->getOriginal('name');

        if (!$original || $original === $new) return;

        // 新名字已通过唯一性校验，先清除残留映射再迁移原 UUID。
        DB::table('uuid')->where('name', $new)->delete();
        DB::table('uuid')->where('name', $original)->update(['name' => $new]);
    };

    // v3 模式改名也保留 UUID；旧名字被重新注册时由 Profile 处理 UUID 冲突。
    App\Models\Player::updating($callback);

    // 配置成员密钥后，将角色变更同步到 MUA 中央服务器。
    $unionPush = function (string $method, string $url, array $payload = null) {
        if (option('union_member_key') === '') {
            return;
        }
        try {
            $response = UnionClient::request($method, $url, $payload);
            if (! $response->successful()) {
                Log::channel('ygg')->info('Union sync failed.', [
                    'method' => $method, 'url' => $url, 'status' => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('ygg')->info('Union sync exception: '.$e->getMessage(), ['url' => $url]);
        }
    };

    $events->listen(PlayerWasAdded::class, function ($event) use ($unionPush) {
        $player = $event->player;
        $uuid = Profile::getUuidFromName($player->name);
        $unionPush('post', option('union_api_root').'/profile', [
            'id' => $uuid,
            'name' => $player->name,
        ]);
        Log::channel('ygg')->info("Player [$player->name] added; union sync issued.");
    });

    $events->listen(PlayerWillBeDeleted::class, function ($event) use ($unionPush) {
        $player = $event->player;
        // 这里走 uuid 表直查，避免 getUuidFromName 在角色被删后命中空映射时再分配一个新 UUID
        $row = DB::table('uuid')->where('name', $player->name)->first();
        if ($row) {
            $unionPush('delete', option('union_api_root').'/profile/'.$row->uuid);
            Log::channel('ygg')->info("Player [$player->name] deleted; union sync issued.");
        }
    });

    // UUID 不变时同步改名；旧数据仍有不同 UUID 时删除旧条目并重新注册。
    $events->listen('player.renamed', function ($player, $old) use ($unionPush) {
        if (! $old || $old->name === $player->name) {
            return;
        }

        $newUuid = Profile::getUuidFromName($player->name);
        $oldRow = DB::table('uuid')->where('name', $old->name)->first();

        if ($oldRow && $oldRow->uuid !== $newUuid) {
            $unionPush('delete', option('union_api_root').'/profile/'.$oldRow->uuid);
            $unionPush('post', option('union_api_root').'/profile', [
                'id' => $newUuid,
                'name' => $player->name,
            ]);
        } else {
            $unionPush('put', option('union_api_root').'/profile/'.$newUuid, [
                'name' => $player->name,
            ]);
        }

        Log::channel('ygg')->info("Player renamed [{$old->name} -> {$player->name}]; union sync issued.");
    });

    // 向用户中心首页添加「快速配置启动器」板块
    if (option('ygg_show_config_section')) {
        $filter->add('grid:user.index', function ($grid) {
            foreach ($grid['widgets'] as &$row) {
                foreach ($row as &$column) {
                    if (is_array($column) && in_array('user.widgets.dashboard.announcement', $column, true)) {
                        $column[] = 'Yggdrasil::dnd';

                        return $grid;
                    }
                }
            }

            return $grid;
        });
        Hook::addScriptFileToPage(plugin('yggdrasil-api')->assets('dnd.js'), ['user']);
    }

    // 向管理后台菜单添加「Yggdrasil 日志」项目
    Hook::addMenuItem('admin', 4, [
        'title' => 'Yggdrasil::log.title',
        'link'  => 'admin/yggdrasil-log',
        'icon'  => 'fa-history'
    ]);

    // 向用户中心菜单添加「绑定正版账号」项目
    Hook::addMenuItem('user', 4, [
        'title' => 'Yggdrasil::bind.menu_title',
        'link'  => 'yggdrasil/mojang/bind',
        'icon'  => 'fa-gamepad'
    ]);

    // 添加 API 路由
    Hook::addRoute(function () {
        Route::namespace('Yggdrasil\Controllers')
            ->prefix('api/yggdrasil')
            ->group(function () {
                Route::any('', 'ConfigController@hello');

                require __DIR__.'/routes.php';
            });

        // MUA 中央服务器的入站回调必须验签。
        Route::namespace('Yggdrasil\Controllers')->group(function () {
            Route::middleware(['Yggdrasil\Middleware\UnionHostVerify'])
                ->prefix('api/union/member')
                ->group(function () {
                    Route::post('updatelist',       'UnionController@updateList');
                    Route::post('updateprivatekey', 'UnionController@updatePrivateKey');
                    Route::post('updatebackendkey', 'UnionController@serverUpdatesBackendKey');
                    Route::post('sync',             'UnionController@triggerSync');
                    Route::post('remapuuid',        'UnionController@remapUUID');
                    Route::post('diagnose',         'UnionController@diagnose');
                });

            // 联盟 hello（无需签名，供中央服务器轮询版本号）
            Route::get('api/union/member', 'UnionController@hello');
        });

        Route::middleware(['web', 'auth', 'role:admin'])
            ->namespace('Yggdrasil\Controllers')
            ->prefix('admin')
            ->group(function () {
                Route::get('yggdrasil-log', 'ConfigController@logPage');

                Route::post(
                    'plugins/config/yggdrasil-api/generate',
                    'ConfigController@generate'
                );

                // 管理员手动触发联盟同步
                Route::prefix('union')->group(function () {
                    Route::post('member/updatelist',       'UnionController@updateList');
                    Route::post('member/updateprivatekey', 'UnionController@updatePrivateKey');
                    Route::post('member/sync',             'UnionController@triggerSync');
                    Route::post('member/diagnose',         'UnionController@triggerDiagnose');
                });
            });

        // Bridge API 使用独立 Bearer 密钥，不接受普通用户会话。
        Route::middleware(['Yggdrasil\\Middleware\\PremiumBridgeAuth', 'throttle:180,1'])
            ->namespace('Yggdrasil\\Controllers')
            ->prefix('api/trusted-bridge')
            ->group(function () {
                Route::post('candidate', 'PremiumBridgeController@candidate');
                Route::post('verify-login', 'PremiumBridgeController@verifyLogin');
                Route::post('resolve', 'PremiumBridgeController@resolve');
            });

        // 绑定页面及确认操作要求皮肤站用户登录。
        Route::middleware(['web', 'auth'])
            ->namespace('Yggdrasil\Controllers')
            ->prefix('yggdrasil/mojang')
            ->group(function () {
                Route::get('bind', 'MojangBindController@index');
                Route::post('bind', 'MojangBindController@requestBind');
                Route::post('confirm-bind', 'MojangBindController@confirmBind')->middleware('throttle:10,1');
                Route::post('cancel-bind', 'MojangBindController@cancelBind');
                Route::post('unbind', 'MojangBindController@unbind');
            });
    });

    // 全局添加 ALI HTTP 响应头
    if (option('ygg_enable_ali')) {
        $kernel = app()->make(Illuminate\Contracts\Http\Kernel::class);
        $kernel->pushMiddleware(Yggdrasil\Middleware\AddApiIndicationHeader::class);
    }
};
