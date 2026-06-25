<?php
declare(strict_types=1);

namespace wise\api\jwt;

use wise\jwt\Jwt as WiseJwt;
use wise\jwt\validator\Validator;

/**
 * JWT 核心类（wise-jwt 薄包装，保持向后兼容）
 * @deprecated 请直接使用 \wise\jwt\Jwt
 */
class Jwt extends WiseJwt
{
    /**
     * @param string                  $algorithm
     * @param SignerInterface         $signer  wise-api SignerInterface（extends wise-jwt SignerInterface，类型兼容）
     * @param string                  $issuer
     * @param int                     $ttl          access_token 有效期（秒）
     * @param int                     $refreshTtl   刷新阈值（秒）
     * @param string|null             $audience     受众声明（可选）
     */
    public function __construct(
        string $algorithm,
        $signer,
        string $issuer = 'wise-api',
        int $ttl = 7200,
        int $refreshTtl = 1800,
        ?string $audience = null
    ) {
        parent::__construct(
            algorithm: $algorithm,
            signer: $signer,
            validator: new Validator(0),
            blacklist: null,
            issuer: $issuer,
            ttl: $ttl,
            refreshTtl: $refreshTtl,
            audience: $audience
        );
    }
}
