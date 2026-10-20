<?php
declare(strict_types=1);

namespace wise\api\traits;

use think\Response;
use wise\api\debug\DebugPanel;

/**
 * 调试支持 Trait
 *
 * A5: 为中间件提供统一的调试记录 + 响应附加方法，
 * 消除 RateLimitMiddleware / SignVerifyMiddleware 中的重复代码。
 *
 * 用法：
 *   class MyMiddleware
 *   {
 *       use HasDebugSupport;
 *
 *       public function handle(...) {
 *           $this->debugRecord($this->debugPanel, 'rate_limit', [...]);
 *           $this->debugAttach($this->debugPanel, $response);
 *       }
 *   }
 *
 * @package wise\api\traits
 */
trait HasDebugSupport
{
    /**
     * 向调试面板记录一段上下文（面板为 null 时静默跳过）
     *
     * @param ?DebugPanel       $panel 调试面板实例（nullable）
     * @param string            $key   记录键名
     * @param array<string,mixed> $value 记录内容
     */
    protected function debugRecord(?DebugPanel $panel, string $key, array $value): void
    {
        $panel?->record($key, $value);
    }

    /**
     * 将调试信息附加到响应（面板为 null 时静默跳过）
     *
     * @param ?DebugPanel $panel    调试面板实例（nullable）
     * @param Response    $response HTTP 响应对象
     */
    protected function debugAttach(?DebugPanel $panel, Response $response): void
    {
        $panel?->attachToResponse($response);
    }
}
