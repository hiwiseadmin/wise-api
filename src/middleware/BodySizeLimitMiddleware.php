<?php
declare(strict_types=1);

namespace wise\api\middleware;

use Closure;
use think\Request;
use think\Response;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;

/**
 * 请求体大小限制中间件
 *
 * 防止大 payload 攻击，超限返回 413 Payload Too Large。
 *
 * 支持中间件参数化：
 *   ->middleware(\wise\api\middleware\BodySizeLimitMiddleware::class . ':5242880')  // 限制 5MB
 *
 * 默认限制：1MB（1048576 字节）
 *
 * 中间件栈位置：建议放在签名/鉴权之前
 *
 * @package wise\api\middleware
 */
class BodySizeLimitMiddleware
{
    /** @var int 默认限制（1MB） */
    public const DEFAULT_MAX_BYTES = 1048576;

    /**
     * @param ResponseWrapper $wrapper 统一响应包装器
     */
    public function __construct(
        protected ResponseWrapper $wrapper
    ) {
    }

    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @param mixed   ...$params 第一个参数为字节限制（可选）
     */
    public function handle(Request $request, Closure $next, ...$params): Response
    {
        $maxBytes = !empty($params) ? (int) $params[0] : self::DEFAULT_MAX_BYTES;

        // 检查 Content-Length 头（快速拒绝，无需先读 body）
        $contentLength = (int) $request->header('Content-Length', '0');
        if ($contentLength > $maxBytes) {
            return $this->wrapper->error(
                ErrorCode::INVALID_PARAM->value,
                sprintf('请求体过大，最大允许 %s', $this->formatBytes($maxBytes)),
                ['max_bytes' => $maxBytes, 'actual_bytes' => $contentLength],
                413
            );
        }

        // 对文件上传，检查各文件大小
        $files = $request->file();
        if (!empty($files)) {
            foreach ($files as $field => $file) {
                if (is_array($file)) {
                    foreach ($file as $f) {
                        if ($f->getSize() > $maxBytes) {
                            return $this->wrapper->error(
                                ErrorCode::INVALID_PARAM->value,
                                sprintf('文件 "%s" 过大，最大允许 %s', $f->getOriginalName(), $this->formatBytes($maxBytes)),
                                ['max_bytes' => $maxBytes],
                                413
                            );
                        }
                    }
                } elseif ($file->getSize() > $maxBytes) {
                    return $this->wrapper->error(
                        ErrorCode::INVALID_PARAM->value,
                        sprintf('文件 "%s" 过大，最大允许 %s', $file->getOriginalName(), $this->formatBytes($maxBytes)),
                        ['max_bytes' => $maxBytes],
                        413
                    );
                }
            }
        }

        return $next($request);
    }

    /**
     * 格式化字节数为可读字符串
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        return $bytes . ' B';
    }
}
