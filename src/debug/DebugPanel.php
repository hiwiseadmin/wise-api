<?php
declare(strict_types=1);

namespace wise\api\debug;

use think\App;
use think\Log;
use think\Response;

/**
 * API 调试面板
 *
 * 仅在 app()->isDebug() 为 true 时启用。提供两种输出方式：
 *  - response_header：在响应 Header 中附带 X-Debug-Info（JSON 字符串）
 *  - route          ：暴露 /__debug_api 路由返回最近一次请求的调试快照
 *
 * 设计上以"被动收集"为主：各中间件主动调用 record() 注入自身上下文。
 * 此外自动捕获请求耗时、内存占用、（可选）SQL 日志。
 *
 * @package wise\api\debug
 */
class DebugPanel
{
    /** @var array<string,mixed> 调试上下文 */
    protected array $context = [];

    /** @var float 请求开始时间 */
    protected float $startTime;

    /** @var int 开始内存（字节） */
    protected int $startMemory;

    protected bool $enabled;

    protected string $output;

    protected bool $withHeader;

    public function __construct(protected App $app)
    {
        $config = $this->app->config('wise-api.debug', []);
        $this->enabled   = $this->app->isDebug() && (bool) ($config['enable'] ?? true);
        $this->output    = (string) ($config['output'] ?? 'route');
        $this->withHeader= (bool) ($config['with_header'] ?? true);

        $this->startTime   = microtime(true);
        $this->startMemory = memory_get_usage();
    }

    /**
     * 调试是否启用（仅 Debug 模式下返回 true）
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 记录一段调试上下文（同名 key 会被覆盖）
     */
    public function record(string $key, array $value): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->context[$key] = $value;
    }

    /**
     * 获取完整调试快照
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'enabled'   => $this->enabled,
            'request'   => [
                'method'   => $this->app->request->method(),
                'path'     => '/' . ltrim($this->app->request->pathinfo(), '/'),
                'ip'       => $this->app->request->ip(),
                'query'    => $this->app->request->get(),
                'post'     => $this->app->request->post(),
            ],
            'elapsed_ms' => (int) round((microtime(true) - $this->startTime) * 1000),
            'memory' => [
                'start'  => $this->startMemory,
                'current'=> memory_get_usage(),
                'peak'   => memory_get_peak_usage(),
            ],
            'context' => $this->filterSensitive($this->context),
            'sql'     => $this->collectSql(),
            'time'    => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 过滤敏感字段（密码、密钥、令牌等）
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function filterSensitive(array $data): array
    {
        $sensitiveKeys = ['password', 'secret', 'sign', 'token', 'app_secret', 'private_key', 'credit_card'];
        foreach ($data as $k => &$v) {
            if (is_array($v)) {
                $v = $this->filterSensitive($v);
            } elseif (is_string($k) && in_array(strtolower($k), $sensitiveKeys, true)) {
                $v = '******';
            }
        }
        return $data;
    }

    /**
     * 收集最近一次请求的 SQL 日志
     *
     * 限制最大读取行数，避免超大日志文件影响性能。
     *
     * @return array<int,string>
     */
    protected function collectSql(): array
    {
        $config = $this->app->config('wise-api.debug', []);
        if (!($config['with_sql'] ?? true)) {
            return [];
        }
        $log = $this->app->log;
        if (!$log instanceof Log) {
            return [];
        }
        // 简单实现：从 think_log 文件中截取最近 SQL
        $logPath = $this->app->getRuntimePath() . 'log' . DIRECTORY_SEPARATOR . date('Ymd') . '.log';
        if (!is_file($logPath)) {
            return [];
        }

        // 限制最大读取 500 行，避免大日志文件影响性能
        $lines = [];
        $fp = fopen($logPath, 'r');
        if ($fp === false) {
            return [];
        }
        $maxLines = 500;
        $count = 0;
        while (($line = fgets($fp)) !== false && $count < $maxLines) {
            $lines[] = rtrim($line, "\n\r");
            $count++;
        }
        fclose($fp);

        $sqls = [];
        for ($i = count($lines) - 1; $i >= 0 && count($sqls) < 50; $i--) {
            if (stripos($lines[$i], 'SQL') !== false || stripos($lines[$i], 'QUERY') !== false) {
                $sqls[] = $lines[$i];
            }
        }
        return array_reverse($sqls);
    }

    /**
     * 将调试信息附加到 Response
     *
     * 只调用一次 snapshot()，缓存结果避免重复计算。
     */
    public function attachToResponse(Response $response): void
    {
        if (!$this->enabled) {
            return;
        }

        $snapshot = $this->snapshot();

        if ($this->withHeader) {
            $response->header(['X-Debug-Info' => base64_encode(json_encode($snapshot, JSON_UNESCAPED_UNICODE))]);
        }

        // route 模式：写入共享存储，缓存键加命名空间避免多应用冲突
        if ($this->output === 'route') {
            try {
                $cache = $this->app->cache;
                $cache->set('wise_api:' . ($this->app->config('wise-api.jwt.app', 'default')) . ':debug_last', $snapshot, 60);
            } catch (\Throwable $e) {
                // 忽略缓存写入失败
            }
        }
    }

    /**
     * route 模式：暴露给 /__debug_api 路由调用
     *
     * @return array<string,mixed>
     */
    public function getLastSnapshot(): array
    {
        if (!$this->enabled) {
            return ['enabled' => false, 'msg' => 'Debug panel disabled'];
        }
        return $this->app->cache->get('wise_api:' . ($this->app->config('wise-api.jwt.app', 'default')) . ':debug_last', []);
    }
}
