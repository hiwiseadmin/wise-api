<?php
declare(strict_types=1);
namespace wise\api\command;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\api\trait\CommandTrait;

class Publish extends Command
{
    use CommandTrait;

    protected function configure(): void
    {
        $this->setName('wise:api:publish')
            ->setDescription('发布 API 日志配置文件及数据库迁移文件')
            ->addOption('force', 'f', Option::VALUE_NONE, '强制覆盖已存在的配置文件');
    }

    protected function getPackageConfigName(): string
    {
        return 'wise-api';
    }

    protected function execute(Input $input, Output $output): int
    {
        $force = $input->getOption('force');
        $this->printBanner($output, 'wise-api');

        // 发布配置和迁移文件
        $this->publishConfig($output, $force);
        $migrationsPublished = $this->publishMigrations($output);

        $this->printSuccessFooter($output, 'API 日志', $migrationsPublished);
        return 0;
    }
}
