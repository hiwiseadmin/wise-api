<?php
declare(strict_types=1);

namespace wise\api;

use think\facade\Route;
use wise\support\router\RouteRegistrar as BaseRouteRegistrar;

/**
 * WiseApi 后台页面路由注册器（配置驱动）
 *
 * 与同命名空间的 {@see RouteRegistrar}（API / 调试路由，历史自定义实现）并存，
 * 但只负责**后台管理页**：把「接口日志」页面路由投递到 admin 应用。
 *
 * 采用框架统一的包路由基类（wise\support\router\RouteRegistrar），因此天然获得
 * 配置驱动、应用归属判定与能力市场清单能力；分组前缀 'admin'
 * （见 config 的 web.groups.admin_api_log），在 admin 应用内以相对前缀 '' 落地，
 * 实际 URL 为 /admin/api_log/*，与菜单声明
 * （wise\api\menu\ApiLogMenuProvider）一一对应；卸载本包后路由与菜单一并消失。
 *
 * @package wise\api
 */
class AdminRouteRegistrar extends BaseRouteRegistrar
{
    protected string $configKey = 'wise-api';

    protected function define(array $config): void
    {
        // 依赖 WiseAdmin 宿主提供的 admin 控制器基类（复用后台视图壳与响应 helper）；
        // 脱离该宿主时跳过注册，避免路由命中后 500。
        if (!class_exists(\app\admin\controller\BaseController::class)) {
            return;
        }

        $this->group('admin_api_log', function () {
            // 使用 Route::rule()（匹配全部方法）等价于迁移前后台默认调度的方法无关行为，
            // 避免前端既有 GET/POST 调用差异造成 405；鉴权由 admin 应用级 RBAC 统一负责。
            Route::rule('api_log/index', '\wise\api\controller\ApiLog@index');
            Route::rule('api_log/getList', '\wise\api\controller\ApiLog@getList');
            Route::rule('api_log/preview', '\wise\api\controller\ApiLog@preview');
            Route::rule('api_log/dashboard', '\wise\api\controller\ApiLog@dashboard');
            Route::rule('api_log/clearLog', '\wise\api\controller\ApiLog@clearLog');
            Route::rule('api_log/batchDelete', '\wise\api\controller\ApiLog@batchDelete');
            Route::rule('api_log/delete', '\wise\api\controller\ApiLog@delete');
        });
    }
}
