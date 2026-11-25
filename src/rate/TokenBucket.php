<?php

declare(strict_types=1);

namespace wise\api\rate;

use wise\support\rate\TokenBucket as BaseTokenBucket;

/**
 * 令牌桶限流算法 — 兼容代理
 *
 * 实际实现已迁移至 {@see \wise\support\rate\TokenBucket}。
 * 本类保留为向后兼容的代理，所有调用均透传至 wise-support。
 *
 * @method array attempt(string $key, array $options = [])
 * @method array status(string $key, array $options = [])
 * @method void  reset(string $key)
 *
 * @package wise\api\rate
 */
class TokenBucket extends BaseTokenBucket
{
}
