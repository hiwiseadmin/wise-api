<?php
declare(strict_types=1);

namespace wise\api\exception;

/**
 * JWT 异常（wise-jwt 薄包装）
 * @deprecated 请直接使用 \wise\jwt\exception\JwtException
 */
class JwtException extends \wise\jwt\exception\JwtException
{
}
