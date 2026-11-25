<?php
declare(strict_types=1);

namespace wise\api\controller;

use think\facade\View;
use wise\api\model\ApiLog as ApiLogModel;

/**
 * 接口日志 — 后台管理（包内控制器）
 *
 * 由包内 AdminRouteRegistrar 以 `/admin/api_log/*` 落地，
 * 菜单见 wise\api\menu\ApiLogMenuProvider（挂在框架「日志管理」目录下）。
 *
 * 视图归属：控制器继承宿主 app\admin\controller\BaseController，
 * initialize() 中 ViewScope::activate('wise-api')，使显式模板名
 * `admin/api_log/index` 兜底到本包 view/ 目录；
 * 宿主 app/admin/view/** 放同名文件仍可覆盖（L1 优先）。
 *
 * 前端 ESM 模块随包发布：`../plugins/wise-api/js/api_log`
 * （源文件为本包 `public/js/api_log.js`，由 `wise:vendor:publish` 复制到
 * 应用的 `public/statics/plugins/wise-api/`）。
 */
class ApiLog extends \app\admin\controller\BaseController
{
    /** 视图 scope（视图驱动据此把相对模板名兜底到本包 view/ 目录） */
    protected const VIEW_SCOPE = 'wise-api';

    /**
     * 列表页字段映射（log_id → id 兼容前端 EnhancedTable）
     */
    private const LIST_FIELDS = [
        'log_id as id', 'request_id', 'app_key', 'duration_ms',
        'request_ip', 'request_method', 'request_url', 'request_route',
        'response_status', 'user_agent', 'trace_id', 'channel', 'create_time',
    ];

    protected function initialize()
    {
        parent::initialize();

        try {
            if (class_exists(\app\common\view\ViewScope::class)) {
                \app\common\view\ViewScope::activate(self::VIEW_SCOPE);
            } else {
                $this->request->view_scope = self::VIEW_SCOPE;
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 首页
     * @return string
     */
    public function index()
    {
        View::assign('wsconfig', array_merge(
            (array) View::get('wsconfig'),
            ['js' => '../plugins/wise-api/js/api_log']
        ));

        return $this->fetch('admin/api_log/index');
    }

    /**
     * API 日志列表（对接 wise-api 的 api_log 表）
     */
    public function getList()
    {
        $this->buildQueryParams();

        $query = ApiLogModel::field(self::LIST_FIELDS);

        $dateRange = $this->request->param('date_range');
        if ($dateRange) {
            [$startDate, $endDate] = explode(' - ', $dateRange);
            $query->whereBetweenTime('create_time', $startDate, $endDate);
        }

        $method = $this->request->param('request_method');
        if ($method) {
            $query->where('request_method', strtoupper($method));
        }

        $status = $this->request->param('response_status');
        if ($status !== null && $status !== '') {
            $query->where('response_status', (int) $status);
        }

        $route = $this->request->param('request_route');
        if ($route) {
            $query->where('request_route', 'like', "%{$route}%");
        }

        $ip = $this->request->param('request_ip');
        if ($ip) {
            $query->where('request_ip', 'like', "%{$ip}%");
        }

        $total = $query->count();
        $list = $query->order('create_time', 'desc')
            ->page($this->page, $this->pagesize)
            ->select()
            ->toArray();

        return $this->success('success', [
            'total' => $total,
            'list'  => $list,
            'page'  => $this->page,
            'limit' => $this->pagesize,
        ]);
    }

    /**
     * API 日志预览页面
     * @return string
     */
    public function preview()
    {
        $logId = $this->request->param('id', '');

        $apiLog = ApiLogModel::where('log_id', $logId)->findOrEmpty();
        if ($apiLog->isEmpty()) {
            return $this->error('API日志不存在');
        }

        return $this->assign([
            'apiLog' => $apiLog,
        ])->fetch('admin/api_log/preview');
    }

    /**
     * 数据分析仪表板
     */
    public function dashboard()
    {
        $analysisService = new \app\admin\service\LogAnalysisService();

        $data = [
            'login_stats'     => $analysisService->getLoginStats(7),
            'operation_stats' => $analysisService->getOperationStats(7),
            'api_performance' => $analysisService->getApiPerformance(7),
            'security_risks'  => $analysisService->securityRiskDetection(),
            'system_health'   => $analysisService->getSystemHealth(),
        ];

        return json([
            'code' => 0,
            'data' => $data,
            'msg'  => 'success',
        ]);
    }

    /**
     * 清理日志（删除 N 天前的 API 日志）
     *
     * 安全约束（本动作物理删除审计数据、不可撤销）：
     *   1. 仅接受 POST —— 路由为方法无关的 Route::rule()，若允许 GET，
     *      一个 <img src="/admin/api_log/clearLog?days=0"> 即可被 CSRF 触发；
     *   2. days 必须是大于 0 的整数 —— days=0/负数会把时间下界推到"现在/未来"，
     *      等于清空整张 api_log 表。
     */
    public function clearLog()
    {
        if (!$this->request->isPost()) {
            return $this->error('该操作仅支持 POST 请求');
        }

        $days = (int) $this->request->param('days', 30);
        if ($days < 1) {
            return $this->error('days 必须为大于 0 的整数（清理 N 天前的日志）');
        }

        $clearDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = ApiLogModel::where('create_time', '<', $clearDate)->delete();

        return $this->success("已清理 {$deleted} 条日志");
    }

    /**
     * 批量删除日志
     */
    public function batchDelete()
    {
        $ids = $this->request->param('ids/a', []);
        if (empty($ids)) {
            return $this->error('请选择要删除的日志');
        }

        $deleted = ApiLogModel::whereIn('log_id', $ids)->delete();
        return $this->success("成功删除 {$deleted} 条日志");
    }

    /**
     * 删除日志（批量与单行共用）
     *
     * 前端 EnhancedTable 的 del 链路按场景传参，二者都落到本动作：
     *   - 工具栏批量删除：ids = [log_id, ...]（数组；兼容 "a,b" 逗号串）
     *   - 行内单条删除：id = log_id（api_log 主键是 UUID 字符串）
     *
     * 注意：api_log 为追加型审计数据、未启用软删除，删除即物理删除，
     * 前端「撤销」会请求 /admin/api_log/restore（无此端点）——与 IP 白名单
     * 等同类硬删除页面的既有行为一致。
     */
    public function delete()
    {
        $ids = $this->request->param('ids', []);
        $id  = $this->request->param('id', '');

        if (is_string($ids) && $ids !== '') {
            $ids = explode(',', $ids);
        }

        $idList = [];
        if (is_array($ids)) {
            foreach ($ids as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $idList[] = $value;
                }
            }
        }

        if (is_string($id) && trim($id) !== '') {
            $idList[] = trim($id);
        }

        $idList = array_values(array_unique($idList));

        if (empty($idList)) {
            return $this->error('请选择要删除的日志');
        }

        $deleted = ApiLogModel::whereIn('log_id', $idList)->delete();

        if ($deleted <= 0) {
            return $this->error('日志不存在或已删除');
        }

        return $this->success($deleted > 1 ? "成功删除 {$deleted} 条日志" : '删除成功');
    }
}
