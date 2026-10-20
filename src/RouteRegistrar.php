<?php

declare(strict_types=1);

namespace wise\api;

use think\facade\Route;
use wise\api\controller\EncryptController;
use wise\api\debug\DebugPanel;
use wise\support\ExtPoint;
use wise\support\ExtPoints;

/**
 * WiseApi 路由注册器
 *
 * 将路由定义集中管理，通过静态方法按需注册。
 */
class RouteRegistrar
{
    /**
     * 注册管理/调试路由
     */
    public static function registerAdmin(): void
    {
        // 配置驱动总开关（web.enable=false 时不注册任何路由）
        if (empty(config('wise-api.web.enable', true))) {
            return;
        }

        // 仅 Debug 模式生效
        if (!app()->isDebug()) {
            return;
        }

        // 调试面板
        Route::get('__debug_api', function (DebugPanel $panel) {
            return json([
                'code' => 0,
                'msg'  => 'ok',
                'data' => $panel->getLastSnapshot(),
            ]);
        });

        // 健康检查
        Route::get('__health', function () {
            return json([
                'code' => 0,
                'msg'  => 'ok',
                'data' => [
                    'service' => 'wise-api',
                    'time'    => date('c'),
                    'debug'   => app()->isDebug(),
                ],
            ]);
        });
    }

    /**
     * 注册 API 路由
     *
     * app/api 已是标准的 think-multi-app 应用，/api/... 的 'api' 段会在路由匹配前
     * 被从 pathinfo 剥离，因此这里不能再写带 api/ 前缀的完整路径，而是把相对
     * 路径包成闭包投递到 ExtPoints::ROUTE_API，由 app/api/route/api.php 落地。
     */
    public static function registerApi(): void
    {
        // 配置驱动总开关（web.enable=false 时不注册任何路由）
        if (empty(config('wise-api.web.enable', true))) {
            return;
        }

        ExtPoint::register(ExtPoints::ROUTE_API, 'wise-api:encrypt', static function (): void {
            // 加密密钥分发（实际 URL 为 /api/encrypt/public-key）
            Route::get('encrypt/public-key', EncryptController::class . '@publicKey');
            Route::post('encrypt/handshake', EncryptController::class . '@handshake');
        });
    }
}
