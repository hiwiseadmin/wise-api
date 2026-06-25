<?php
declare(strict_types=1);

namespace wise\api\jwt;

use wise\jwt\signer\SignerInterface as WiseJwtSignerInterface;

/**
 * 签名策略接口（wise-jwt 薄包装，保持向后兼容）
 * @deprecated 请直接使用 \wise\jwt\signer\SignerInterface
 */
interface SignerInterface extends WiseJwtSignerInterface
{
}
