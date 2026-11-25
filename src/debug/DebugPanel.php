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
        $config = $this->app->config->get('wise-api.debug', []);
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
        $requestId = function_exists('request_id') ? request_id() : 'unknown';
        $userId    = function_exists('auth_id') ? (auth_id('api') ?? auth_id('admin')) : null;

        return [
            'enabled'    => $this->enabled,
            'request_id' => $requestId,
            'user_id'    => $userId,
            'request'    => [
                'method'   => $this->app->request->method(),
                'path'     => '/' . ltrim($this->app->request->pathinfo(), '/'),
                'ip'       => $this->app->request->ip(),
                'query'    => $this->filterSensitive($this->app->request->get()),
                'post'     => $this->filterSensitive($this->app->request->post()),
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
        $config = $this->app->config->get('wise-api.debug', []);
        if (!($config['with_sql'] ?? true)) {
            return [];
        }
        $log = $this->app->log;
        if (!$log instanceof Log) {
            return [];
        }
        $logPath = $this->app->getRuntimePath() . 'log' . DIRECTORY_SEPARATOR . date('Ymd') . '.log';
        if (!is_file($logPath)) {
            return [];
        }

        $maxLines = 500;
        $lines = [];
        $fp = fopen($logPath, 'r');
        if ($fp === false) {
            return [];
        }

        $buffer = '';
        $chunkSize = 4096;
        fseek($fp, 0, SEEK_END);
        $position = ftell($fp);

        while ($position > 0 && count($lines) < $maxLines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            fseek($fp, $position);
            $chunk = fread($fp, $readSize);
            $buffer = $chunk . $buffer;
            $newLines = explode("\n", $buffer);
            if ($position > 0) {
                $buffer = array_shift($newLines);
            }
            $lines = array_merge($newLines, $lines);
        }
        fclose($fp);

        $lines = array_slice($lines, -$maxLines);

        $sqls = [];
        foreach (array_reverse($lines) as $line) {
            if (count($sqls) >= 50) {
                break;
            }
            if (stripos($line, 'SQL') !== false || stripos($line, 'QUERY') !== false) {
                $sqls[] = rtrim($line, "\n\r");
            }
        }
        return $sqls;
    }

    /** @var array<string,mixed>|null 快照缓存（同一次请求内只计算一次） */
    protected ?array $cachedSnapshot = null;

    /**
     * 清除快照缓存（用于新请求开始时重置）
     */
    public function resetSnapshot(): void
    {
        $this->cachedSnapshot = null;
    }

    /**
     * 将调试信息附加到 Response
     *
     * 使用实例级缓存，同一次请求中多次调用 attachToResponse()
     * 只计算一次 snapshot()，避免重复读取 SQL 日志文件。
     */
    public function attachToResponse(Response $response): void
    {
        if (!$this->enabled) {
            return;
        }

        $snapshot = $this->cachedSnapshot ??= $this->snapshot();

        if ($this->withHeader) {
            $response->header(['X-Debug-Info' => base64_encode(json_encode($snapshot, JSON_UNESCAPED_UNICODE))]);
        }

        if ($this->output === 'route') {
            try {
                $cache = $this->app->cache;
                $cache->set(
                    'wise_api:' . ($this->app->config->get('wise-api.jwt.app', 'default'))
                    . ':debug_last:' . $this->viewerFingerprint(),
                    $snapshot,
                    60
                );
            } catch (\Throwable $e) {
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
        return $this->app->cache->get(
            'wise_api:' . ($this->app->config->get('wise-api.jwt.app', 'default'))
            . ':debug_last:' . $this->viewerFingerprint(),
            []
        );
    }

    /**
     * 查看者指纹（按客户端 IP 隔离调试快照）
     *
     * 调试面板自身没有身份体系，用 IP 做最小隔离：避免 /__debug_api 的
     * 任意访问者读到「别人刚才那次请求」的 query/post/SQL。
     *
     * @return string
     */
    protected function viewerFingerprint(): string
    {
        try {
            $ip = (string) $this->app->request->ip();
        } catch (\Throwable) {
            $ip = 'unknown';
        }

        return substr(md5($ip), 0, 12);
    }
}
