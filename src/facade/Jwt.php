<?php
declare(strict_types=1);

namespace wise\api\facade;

use think\Facade;

/**
 * JWT 门面（wise-jwt 薄包装）
 * @deprecated 请直接使用 \wise\jwt\facade\Jwt
 *
 * @method static string       issue(string|int $subject, array $claims = [], ?int $ttl = null)
 * @method static array        parse(string $token)
 * @method static array|null   tryParse(string $token)
 * @method static int          ttlOf(array $payload)
 * @method static bool         shouldRefresh(array $payload)
 * @method static string       refresh(string $token)
 * @method static bool         revoke(string $token)
 * @method static bool         isRevoked(string $jti)
 * @method static \wise\jwt\Builder builder()
 */
class Jwt extends Facade
{
    protected static function getFacadeClass()
    {
        return \wise\jwt\Jwt::class;
    }
}
