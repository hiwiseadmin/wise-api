<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\App;
use think\Request;
use think\Response;

/**
 * 响应压缩中间件
 *
 * 根据 Accept-Encoding 请求头自动对 JSON/文本响应进行 gzip 或 brotli 压缩。
 * 可将 API 响应体减小 70%-90%，显著降低带宽消耗。
 *
 * 优先级：brotli > gzip > 不压缩
 *
 * 跳过场景：
 *   - 响应体 < 1KB（压缩收益低）
 *   - 非 text/html/json/xml 类 MIME
 *   - 客户端未声明接受编码
 *   - 上游已设置 Content-Encoding（防双重压缩）
 *
 * 生产建议：
 *   - 如果 Nginx 已开启 gzip，设置 compression.gzip_enabled = false
 *     （Nginx C 实现比 PHP gzencode 快得多，避免重复工作）
 *   - brotli 建议始终开启：标准 Nginx 不带 brotli 模块，PHP 层是唯一来源
 *
 * 中间件栈位置：建议放在中间件链靠后位置（在业务中间件之后）
 *
 * @package wise\api\middleware
 */
class CompressionMiddleware
{
    /** @var int 最小压缩字节数（< 该值不压缩） */
    protected int $minBytes;

    /** @var int gzip 压缩级别（0-9） */
    protected int $gzipLevel;

    /** @var int brotli 压缩级别（0-11） */
    protected int $brotliLevel;

    /** @var bool 是否启用 gzip */
    protected bool $gzipEnabled;

    /** @var bool 是否启用 brotli */
    protected bool $brotliEnabled;

    /**
     * @param App|null $app  应用容器（用于读取配置）
     * @param int      $minBytes    最小压缩字节数
     * @param int      $gzipLevel   gzip 压缩级别
     * @param int      $brotliLevel brotli 压缩级别
     */
    public function __construct(
        ?App $app = null,
        int $minBytes = 1024,
        int $gzipLevel = 6,
        int $brotliLevel = 4
    ) {
        if ($app) {
            $config = $app->config('wise-api.compression', []);
            $this->gzipEnabled   = $config['gzip_enabled'] ?? true;
            $this->brotliEnabled = $config['brotli_enabled'] ?? true;
            $this->minBytes      = $config['min_bytes'] ?? 1024;
            $this->gzipLevel     = max(0, min(9, (int) ($config['gzip_level'] ?? 6)));
            $this->brotliLevel   = max(0, min(11, (int) ($config['brotli_level'] ?? 4)));
        } else {
            $this->gzipEnabled   = true;
            $this->brotliEnabled = true;
            $this->minBytes      = $minBytes;
            $this->gzipLevel     = max(0, min(9, $gzipLevel));
            $this->brotliLevel   = max(0, min(11, $brotliLevel));
        }
    }

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // 检查是否需要压缩
        if (!$this->shouldCompress($request, $response)) {
            return $response;
        }

        $content     = (string) $response->getContent();
        $acceptEnc   = $request->header('Accept-Encoding', '');
        $compressed  = false;

        // 1. 优先 brotli（压缩率更高，且 Nginx 通常不提供）
        if ($this->brotliEnabled && str_contains($acceptEnc, 'br') && function_exists('brotli_compress')) {
            $compressedContent = brotli_compress($content, $this->brotliLevel);
            if ($compressedContent !== false && strlen($compressedContent) < strlen($content)) {
                $response->content($compressedContent);
                $response->header('Content-Encoding', 'br');
                $compressed = true;
            }
        }

        // 2. 回退 gzip（仅当 gzip_enabled=true 且 brotli 未命中）
        if (!$compressed && $this->gzipEnabled && str_contains($acceptEnc, 'gzip')) {
            $compressedContent = gzencode($content, $this->gzipLevel);
            if ($compressedContent !== false && strlen($compressedContent) < strlen($content)) {
                $response->content($compressedContent);
                $response->header('Content-Encoding', 'gzip');
                $compressed = true;
            }
        }

        // 3. 压缩后更新 Content-Length（如之前设置过）
        if ($compressed) {
            $response->header('Content-Length', (string) strlen((string) $response->getContent()));
            // 告知缓存服务器：响应因编码而异
            $response->header('Vary', 'Accept-Encoding');
        }

        return $response;
    }

    /**
     * 判断是否应对该响应进行压缩
     */
    protected function shouldCompress(Request $request, Response $response): bool
    {
        // 客户端未声明接受编码 → 跳过
        $acceptEnc = $request->header('Accept-Encoding', '');
        if (empty($acceptEnc) || $acceptEnc === 'identity') {
            return false;
        }

        // 响应体太小 → 压缩无意义
        $content = (string) $response->getContent();
        if (strlen($content) < $this->minBytes) {
            return false;
        }

        // 已由上游压缩过 → 跳过
        if ($response->getHeader('Content-Encoding')) {
            return false;
        }

        // 检查 Content-Type 是否适合压缩
        $contentType = (string) ($response->getHeader('Content-Type') ?? '');
        $compressibleTypes = ['text/', 'application/json', 'application/xml', 'application/javascript', 'image/svg'];

        foreach ($compressibleTypes as $type) {
            if (stripos($contentType, $type) !== false) {
                return true;
            }
        }

        return false;
    }
}
