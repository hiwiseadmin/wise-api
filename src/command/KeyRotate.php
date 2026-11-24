<?php
declare(strict_types=1);

namespace wise\api\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\api\encrypt\KeyManager;

/**
 * 加密密钥轮换命令
 *
 * 生成新的 RSA 密钥对并输出 .env/配置文件格式。
 * 支持密钥轮换场景：保留旧密钥用于解密，新密钥用于加密。
 *
 * 用法：
 *   php think wise:api:key-rotate                  # 生成新密钥对
 *   php think wise:api:key-rotate --env            # 生成并输出 .env 格式
 *   php think wise:api:key-rotate --bits=4096      # 使用 4096 位密钥
 *   php think wise:api:key-rotate --show-current   # 显示当前密钥配置
 *   php think wise:api:key-rotate --write-env      # 自动写入 .env 文件
 *   php think wise:api:key-rotate --next-id=kid-002  # 指定下一轮密钥 ID
 *
 * 密钥轮换流程：
 *   1. 运行 wise:api:key-rotate --next-id=kid-002 --env
 *   2. 将输出添加到 .env 文件或 wise-api.php 配置
 *   3. 更新 encryption.keys.active_key_id 为 kid-002
 *   4. 保留 kid-001 密钥对用于解密历史数据
 *   5. 72 小时后（过渡期）可删除旧密钥
 *
 * @package wise\api\command
 */
class KeyRotate extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:api:key-rotate')
            ->addOption('bits', 'b', Option::VALUE_REQUIRED, 'RSA 密钥长度（2048/4096）', '2048')
            ->addOption('env', 'e', Option::VALUE_NONE, '输出 .env 格式')
            ->addOption('next-id', null, Option::VALUE_REQUIRED, '下一轮密钥 ID', '')
            ->addOption('show-current', null, Option::VALUE_NONE, '仅显示当前密钥配置')
            ->addOption('write-env', null, Option::VALUE_NONE, '自动写入 .env 文件')
            ->setDescription('生成加密密钥对或执行密钥轮换')
            ->setHelp(<<<'HELP'
<info>密钥轮换命令</info>

<comment>生成新密钥对：</comment>
  php think wise:api:key-rotate

<comment>生成并输出 .env 格式：</comment>
  php think wise:api:key-rotate --env

<comment>生成 4096 位密钥：</comment>
  php think wise:api:key-rotate --bits=4096

<comment>查看当前密钥：</comment>
  php think wise:api:key-rotate --show-current

<comment>自动写入 .env + 轮换（指定新 ID）：</comment>
  php think wise:api:key-rotate --write-env --next-id=kid-002
HELP
            );
    }

    protected function execute(Input $input, Output $output): int
    {
        $showCurrent  = $input->getOption('show-current');
        $bits         = (int) $input->getOption('bits');
        $envFormat    = $input->getOption('env');
        $writeEnv     = $input->getOption('write-env');
        $nextId       = $input->getOption('next-id') ?: $this->generateNextKeyId();

        if (!in_array($bits, [2048, 4096], true)) {
            $output->warning("建议使用 2048 或 4096 位，当前: {$bits}");
        }

        if ($showCurrent) {
            $this->showCurrentKeys($output);
            return 0;
        }

        return $this->generateAndOutput($output, $bits, $envFormat, $writeEnv, $nextId);
    }

    /**
     * 显示当前密钥配置
     */
    private function showCurrentKeys(Output $output): void
    {
        $config = $this->getEncryptionConfig();

        $output->info("=== 当前密钥配置 ===");
        $output->writeln("");

        $activeKeyId = $config['keys']['active_key_id'] ?? 'kid-001';
        $keyPairs    = $config['keys']['key_pairs'] ?? [];

        if (empty($keyPairs)) {
            $output->warning("未配置任何密钥对");
            $output->writeln("请运行 php think wise:api:key-rotate 生成密钥对");
            return;
        }

        $output->writeln("<info>活跃密钥 ID:</info> <comment>{$activeKeyId}</comment>");
        $output->writeln("");

        foreach ($keyPairs as $kid => $pairConfig) {
            $marker = ($kid === $activeKeyId) ? ' ★ 活跃' : '';
            $hasPrivate = !empty($pairConfig['private_key'] ?? '');
            $hasPublic  = !empty($pairConfig['public_key'] ?? '');

            $status = $hasPrivate && $hasPublic ? '<info>完整</info>' : '<error>缺失</error>';
            $output->writeln("  <comment>{$kid}</comment> {$status}{$marker}");

            if ($hasPrivate && $hasPublic) {
                $pem = $pairConfig['private_key'];
                if (!str_starts_with($pem, '-----BEGIN')) {
                    $path = $pem;
                    if (str_starts_with($path, 'file://')) {
                        $path = substr($path, 7);
                    }
                    if (file_exists($path)) {
                        $pem = file_get_contents($path);
                    }
                }
                if ($pem && str_starts_with($pem, '-----BEGIN')) {
                    $output->writeln("    指纹: " . md5($pem));
                }
            }
        }

        $output->writeln("");
        $output->comment("提示: 使用 --write-env --next-id={$this->generateNextKeyId()} 进行密钥轮换");
    }

    /**
     * 生成密钥对并输出
     */
    private function generateAndOutput(Output $output, int $bits, bool $envFormat, bool $writeEnv, string $nextId): int
    {
        if (!extension_loaded('openssl')) {
            $output->error('需要 OpenSSL 扩展');
            return 1;
        }

        $output->info("生成 RSA-{$bits} 密钥对...");
        $output->writeln("");

        try {
            $keys = KeyManager::generateKeyPair($bits);
        } catch (\RuntimeException $e) {
            $output->error("密钥生成失败: {$e->getMessage()}");
            return 1;
        }

        $privateKeyPem = $keys['private_key'];
        $publicKeyPem  = $keys['public_key'];

        if ($writeEnv) {
            $this->writeToEnv($output, $nextId, $privateKeyPem, $publicKeyPem);
            $output->writeln("");
            $this->showKeySummary($output, $nextId);
        } elseif ($envFormat) {
            $this->outputEnvFormat($output, $nextId, $privateKeyPem, $publicKeyPem);
        } else {
            $this->outputPemFormat($output, $nextId, $privateKeyPem, $publicKeyPem);
        }

        $output->writeln("");
        $output->comment("配置到 wise-api.php 或 .env 文件后，更新 active_key_id 为 '{$nextId}' 即可启用新密钥");
        $output->comment("旧密钥请保留在 key_pairs 中用于解密历史数据（过渡期建议 72 小时）");

        return 0;
    }

    /**
     * PEM 格式输出
     */
    private function outputPemFormat(Output $output, string $keyId, string $privatePem, string $publicPem): void
    {
        $output->writeln("<info>密钥 ID:</info> <comment>{$keyId}</comment>");
        $output->writeln("");
        $output->writeln("<info>===== 私钥（请妥善保管，勿泄露）=====</info>");
        $output->writeln($privatePem);
        $output->writeln("");
        $output->writeln("<info>===== 公钥（可公开分发）=====</info>");
        $output->writeln($publicPem);
    }

    /**
     * .env 格式输出
     */
    private function outputEnvFormat(Output $output, string $keyId, string $privatePem, string $publicPem): void
    {
        $privateOneLine = str_replace("\n", "\\n", $privatePem);
        $publicOneLine  = str_replace("\n", "\\n", $publicPem);

        if ($keyId === 'kid-001') {
            $output->writeln("# === API 传输加密密钥（由 wise:api:key-rotate 生成）===");
            $output->writeln("API_ENC_KEY_ID={$keyId}");
            $output->writeln("API_ENC_PRIVATE_KEY={$privateOneLine}");
            $output->writeln("API_ENC_PUBLIC_KEY={$publicOneLine}");
        } else {
            $output->writeln("# === 密钥轮换（由 wise:api:key-rotate 生成）===");
            $output->writeln("# 在 wise-api.php 中设置: 'keys.active_key_id' => '{$keyId}'");
            $output->writeln("#");
            $output->writeln("# 新增以下 key_pairs 条目:");
            $output->writeln("API_ENC_KEY_ID={$keyId}");
            $output->writeln("API_ENC_PRIVATE_KEY_{$keyId}={$privateOneLine}");
            $output->writeln("API_ENC_PUBLIC_KEY_{$keyId}={$publicOneLine}");
        }
    }

    /**
     * 写入 .env 文件
     */
    private function writeToEnv(Output $output, string $keyId, string $privatePem, string $publicPem): void
    {
        $app = $this->app ?? app();
        $envPath = $app->getRootPath() . '.env';

        if (!file_exists($envPath)) {
            $output->error(".env 文件不存在: {$envPath}");
            $output->writeln("请手动复制以下配置到 .env:");
            $this->outputEnvFormat($output, $keyId, $privatePem, $publicPem);
            return;
        }

        $content = file_get_contents($envPath);
        if ($content === false) {
            $output->error("无法读取 .env 文件");
            return;
        }

        $privateOneLine = str_replace("\n", "\\n", $privatePem);
        $publicOneLine  = str_replace("\n", "\\n", $publicPem);

        $envKeyPrivate = ($keyId === 'kid-001') ? 'API_ENC_PRIVATE_KEY' : "API_ENC_PRIVATE_KEY_{$keyId}";
        $envKeyPublic  = ($keyId === 'kid-001') ? 'API_ENC_PUBLIC_KEY' : "API_ENC_PUBLIC_KEY_{$keyId}";

        if (preg_match('/^' . preg_quote($envKeyPrivate, '/') . '\s*=\s*\S/m', $content)) {
            $output->warning("{$envKeyPrivate} 已存在于 .env，跳过写入");
        } else {
            $content = rtrim($content);
            $content .= "\n\n# API 传输加密密钥（由 wise:api:key-rotate --write-env 自动生成）\n";
            $content .= "{$envKeyPrivate}={$privateOneLine}\n";
            $content .= "{$envKeyPublic}={$publicOneLine}\n";

            if (file_put_contents($envPath, $content) !== false) {
                $output->writeln("<info>✓ 密钥已写入 .env</info>");
                $output->writeln("  {$envKeyPrivate}");
                $output->writeln("  {$envKeyPublic}");
            } else {
                $output->error("写入 .env 失败，请手动配置");
            }
        }

        $content = file_get_contents($envPath);
        if ($content === false) {
            $output->comment("请手动设置 API_ENC_KEY_ID = {$keyId}");
            return;
        }

        if (preg_match('/^API_ENC_KEY_ID\s*=\s*\S/m', $content)) {
            $updated = preg_replace(
                '/^API_ENC_KEY_ID\s*=\s*\S/m',
                "API_ENC_KEY_ID = {$keyId}",
                $content
            );
            if ($updated !== null && file_put_contents($envPath, $updated) !== false) {
                $output->writeln("<info>✓ API_ENC_KEY_ID 已更新为 {$keyId}</info>");
            }
        } else {
            $output->comment("请手动设置 API_ENC_KEY_ID = {$keyId}");
        }
    }

    /**
     * 显示密钥摘要
     */
    private function showKeySummary(Output $output, string $keyId): void
    {
        $config = $this->getEncryptionConfig();
        $algorithm = $config['algorithm']['rsa_alg'] ?? 'RSA-OAEP-256';

        $output->writeln("<info>=== 密钥摘要 ===</info>");
        $output->writeln("  ID:        <comment>{$keyId}</comment>");
        $output->writeln("  算法:      <comment>{$algorithm}</comment>");
        $output->writeln("  下一步:    <comment>在 wise-api.php 中将 active_key_id 改为 '{$keyId}'</comment>");
    }

    /**
     * 获取加密配置
     *
     * @return array
     */
    private function getEncryptionConfig(): array
    {
        try {
            $app = $this->app ?? app();
            return $app->config->get('wise-api.encryption', []);
        } catch (\Throwable $e) {
            return [
                'keys' => ['active_key_id' => 'kid-001', 'key_pairs' => []],
                'algorithm' => ['rsa_alg' => 'RSA-OAEP-256'],
            ];
        }
    }

    /**
     * 生成下一个密钥 ID
     */
    private function generateNextKeyId(): string
    {
        $config = $this->getEncryptionConfig();
        $activeKeyId = $config['keys']['active_key_id'] ?? 'kid-001';

        if (preg_match('/^kid-(\d+)$/', $activeKeyId, $m)) {
            return 'kid-' . str_pad((string) ((int) $m[1] + 1), 3, '0', STR_PAD_LEFT);
        }

        return 'kid-' . date('Ymd');
    }
}
