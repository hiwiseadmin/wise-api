<?php

declare(strict_types=1);

namespace wise\api\driver;

use Psr\Log\LoggerInterface;
use wise\api\contract\LogDriverInterface;
use wise\api\dto\ApiLog;

/**
 * 文件日志驱动
 *
 * 以 NDJSON 格式写入文件，按日期分文件，支持按大小轮转和自动清理过期文件
 */
class FileDriver implements LogDriverInterface
{
    /** @var string 日志文件目录 */
    private string $path;

    /** @var int 保留最大文件数 */
    private int $maxFiles;

    /** @var int 单文件最大字节数 */
    private int $maxFileSize;

    /** @var LoggerInterface PSR-3 日志 */
    private LoggerInterface $logger;

    /**
     * @param string $path 日志文件目录
     * @param int $maxFiles 保留最大文件数
     * @param int $maxFileSize 单文件最大字节数
     * @param LoggerInterface $logger PSR-3 日志
     */
    public function __construct(string $path, int $maxFiles, int $maxFileSize, LoggerInterface $logger)
    {
        $this->path = rtrim($path, '/\\');
        $this->maxFiles = $maxFiles;
        $this->maxFileSize = $maxFileSize;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function write(ApiLog $log): void
    {
        try {
            $this->ensureDirectoryExists();
            $file = $this->getCurrentFile();

            // 按大小轮转
            if (file_exists($file) && filesize($file) >= $this->maxFileSize) {
                $this->rotateFile($file);
            }

            // NDJSON 格式：每行一条日志
            $line = $log->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            $this->logger->error('[ApiLogger] FileDriver 写入失败: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * {@inheritdoc}
     *
     * 文件驱动无需刷新（每次 write 已直接落盘），但会在 flush 时清理过期文件
     */
    public function flush(): void
    {
        try {
            $this->cleanExpiredFiles();
        } catch (\Throwable $e) {
            $this->logger->error('[ApiLogger] FileDriver 清理过期文件失败: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * 确保目录存在
     */
    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    /**
     * 获取当前日期对应的日志文件路径
     */
    private function getCurrentFile(): string
    {
        return $this->path . '/api_log_' . date('Y-m-d') . '.ndjson';
    }

    /**
     * 按大小轮转文件
     *
     * 将当前文件重命名为带序号的备份文件
     */
    private function rotateFile(string $file): void
    {
        $dir = dirname($file);
        $basename = basename($file, '.ndjson');
        $seq = 1;

        // 查找可用的序号
        while (file_exists($dir . '/' . $basename . '_' . $seq . '.ndjson')) {
            $seq++;
        }

        $rotatedFile = $dir . '/' . $basename . '_' . $seq . '.ndjson';
        rename($file, $rotatedFile);
    }

    /**
     * 清理过期文件
     *
     * 保留最近 max_files 个文件，删除更早的
     */
    private function cleanExpiredFiles(): void
    {
        if ($this->maxFiles <= 0) {
            return;
        }

        $pattern = $this->path . '/api_log_*.ndjson';
        $files = glob($pattern);

        if ($files === false || count($files) <= $this->maxFiles) {
            return;
        }

        // 按修改时间排序（升序，最旧的在前）
        usort($files, function (string $a, string $b): int {
            return filemtime($a) <=> filemtime($b);
        });

        // 删除最旧的文件，直到数量不超过 maxFiles
        $deleteCount = count($files) - $this->maxFiles;
        for ($i = 0; $i < $deleteCount; $i++) {
            unlink($files[$i]);
        }
    }
}
