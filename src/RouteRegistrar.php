<?php

declare(strict_types=1);

namespace wise\api;

use think\facade\Route;
use wise\api\debug\DebugPanel;

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
     */
    public static function registerApi(): void
    {
        // 预留：业务 API 路由在此定义
    }
}
