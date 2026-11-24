<?php
declare(strict_types=1);

namespace wise\api\config;

use wise\api\encrypt\KeyManager;
use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckInterface;

/**
 * wise-api 加密密钥安全检测
 *
 * 检查要点：
 *   1. 加密功能启用时密钥对是否已配置
 *   2. RSA 私钥是否可正确加载
 *   3. 私钥是否已安全存储（非硬编码）
 *
 * 密钥配置方式：
 *   1. .env 环境变量（推荐）—— API_ENC_PRIVATE_KEY / API_ENC_PUBLIC_KEY
 *   2. 文件路径引用 —— 指向安全的文件存储位置
 *   3. 直接 PEM 文本（不推荐生产环境使用）
 *
 * @package wise\api\config
 */
class EncryptionKeyCheck implements ConfigCheckInterface
{
    public function __construct(
        private readonly \think\Config $config,
    ) {
    }

    public function id(): string
    {
        return 'wise-api:encryption-key';
    }

    public function package(): string
    {
        return 'wise-api';
    }

    public function level(): CheckLevel
    {
        return CheckLevel::ERROR;
    }

    public function check(): CheckResult
    {
        // 加密功能未启用时无需检查密钥
        if (!$this->config->get('wise-api.encryption.enable', false)) {
            return CheckResult::ok();
        }

        $activeKeyId = $this->config->get('wise-api.encryption.keys.active_key_id', 'kid-001');
        $keyPairs    = $this->config->get('wise-api.encryption.keys.key_pairs', []);

        if (empty($keyPairs)) {
            return CheckResult::fail(
                'API 传输加密已启用但未配置任何密钥对',
                '请在 .env 中配置 API_ENC_PRIVATE_KEY / API_ENC_PUBLIC_KEY，或在 wise-api.php encryption.keys.key_pairs 中配置'
            );
        }

        // 检查活跃密钥对是否存在
        if (!isset($keyPairs[$activeKeyId])) {
            return CheckResult::fail(
                "活跃密钥 '{$activeKeyId}' 未在 key_pairs 中配置",
                '请确认 encryption.keys.active_key_id 与 key_pairs 中的 key_id 一致'
            );
        }

        // 检查私钥是否可加载
        $privateKeyConfig = $keyPairs[$activeKeyId]['private_key'] ?? '';
        if (empty($privateKeyConfig)) {
            return CheckResult::fail(
                "活跃密钥 '{$activeKeyId}' 缺少私钥配置",
                '请配置 API_ENC_PRIVATE_KEY 环境变量'
            );
        }

        // 尝试验证密钥（使用 KeyManager 在独立模式下验证）
        try {
            $km = new KeyManager($this->config->get('wise-api.encryption', []));
            $resolvedPem = $this->resolvePemConfig($privateKeyConfig);

            if ($resolvedPem !== '') {
                if (!$km->validatePem($resolvedPem, true)) {
                    return CheckResult::fail(
                        "活跃密钥 '{$activeKeyId}' 的私钥格式无效",
                        '请确认 API_ENC_PRIVATE_KEY 为有效的 RSA 私钥 PEM 格式'
                    );
                }
            }
        } catch (\Throwable $e) {
            return CheckResult::warning(
                "密钥验证过程异常: {$e->getMessage()}",
                '请检查 openssl 扩展和密钥配置'
            );
        }

        // 检查是否使用了硬编码密钥（从配置文件中直接读取非环境变量来源的密钥）
        $publicKeyConfig = $keyPairs[$activeKeyId]['public_key'] ?? '';
        if (!str_starts_with($privateKeyConfig, '-----BEGIN') && !str_starts_with($publicKeyConfig, '-----BEGIN')) {
            $envPrivate = env('API_ENC_PRIVATE_KEY');
            $envPublic  = env('API_ENC_PUBLIC_KEY');
            if (empty($envPrivate) && empty($envPublic)) {
                return CheckResult::warning(
                    '加密密钥未通过环境变量配置，建议使用 .env 管理密钥',
                    '请在 .env 中设置 API_ENC_PRIVATE_KEY 和 API_ENC_PUBLIC_KEY'
                );
            }
        }

        return CheckResult::ok();
    }

    /**
     * 解析 PEM 配置值：区分直接文本和文件路径
     *
     * - 以 "-----BEGIN" 开头 → 直接返回 PEM
     * - 以 "file://" 开头 → 读取文件
     * - 其它 → 视为文件路径
     */
    private function resolvePemConfig(string $value): string
    {
        if (empty($value)) {
            return '';
        }

        if (str_starts_with($value, '-----BEGIN')) {
            return $value;
        }

        $path = $value;
        if (str_starts_with($path, 'file://')) {
            $path = substr($path, 7);
        }

        if (@file_exists($path)) {
            $content = @file_get_contents($path);
            return $content !== false ? trim($content) : '';
        }

        return '';
    }
}
