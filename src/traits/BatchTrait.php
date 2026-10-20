<?php
declare(strict_types=1);

namespace wise\api\traits;

use think\Model;
use think\Request;
use wise\api\response\ErrorCode;

/**
 * 批量操作 Trait
 *
 * 为 ApiController 提供批量删除/更新/导入等批量 API 能力。
 *
 * @mixin \wise\api\ApiController
 *
 * @method array       validateRequest(array $rules, array $messages = [], bool $batch = false)
 * @method \think\response\Json success(string $msg = '', mixed $data = null, ErrorCode|int $code = 0)
 * @method \think\response\Json error(string $msg = 'error', mixed $data = [], ErrorCode|int $code = ErrorCode::INVALID_PARAM)
 * @property Request $request
 *
 * 用法：
 *   class UserController extends ApiController
 *   {
 *       use BatchTrait;
 *
 *       public function batchDelete()
 *       {
 *           return $this->batch('delete', UserModel::class, ['id'], (int) $this->currentUser()['id']);
 *       }
 *   }
 *
 * @package wise\api\traits
 */
trait BatchTrait
{
    /**
     * 批量删除
     *
     * 请求体示例：{ "ids": [1, 2, 3] }
     *
     * @param string $modelClass 模型类名（如 UserModel::class）
     * @param string $primaryKey 主键字段名（默认 'id'）
     * @param int    $operatorId 操作人 ID（用于日志审计）
     * @return \think\response\Json
     */
    protected function batchDelete(string $modelClass, string $primaryKey = 'id', int $operatorId = 0): \think\response\Json
    {
        $params = $this->validateRequest([
            'ids' => 'require|array',
        ]);

        $ids = array_map('intval', $params['ids']);
        if (empty($ids)) {
            return $this->error('ids 不能为空', [], 4001);
        }

        /** @var Model $model */
        $model = new $modelClass();
        $count = $model::destroy($ids);

        return $this->success(sprintf('成功删除 %d 条记录', $count), [
            'deleted' => $count,
            'ids'     => $ids,
        ]);
    }

    /**
     * 批量更新
     *
     * 请求体示例：
     *   { "items": [{ "id": 1, "status": 1 }, { "id": 2, "status": 0 }] }
     *
     * @param string   $modelClass    模型类名
     * @param string   $primaryKey    主键字段名
     * @param string[] $allowedFields 允许批量更新的字段白名单
     * @param int      $operatorId    操作人 ID
     * @return \think\response\Json
     */
    protected function batchUpdate(
        string $modelClass,
        string $primaryKey = 'id',
        array $allowedFields = [],
        int $operatorId = 0
    ): \think\response\Json {
        $params = $this->validateRequest([
            'items' => 'require|array',
        ]);

        $items  = $params['items'];
        $total  = count($items);
        $updated = 0;
        $failed  = [];

        /** @var Model $model */
        $model = new $modelClass();

        foreach ($items as $index => $item) {
            if (empty($item[$primaryKey])) {
                $failed[] = ['index' => $index, 'reason' => '缺少主键'];
                continue;
            }

            $pkValue = $item[$primaryKey];
            $data    = $item;

            // 移除主键（通常不做更新）
            unset($data[$primaryKey]);

            // 白名单过滤
            if (!empty($allowedFields)) {
                $data = array_intersect_key($data, array_flip($allowedFields));
            }

            if (empty($data)) {
                $failed[] = ['index' => $index, $pkValue => $pkValue, 'reason' => '无可更新字段'];
                continue;
            }

            try {
                $affected = $model->where($primaryKey, $pkValue)->update($data);
                if ($affected !== false) {
                    $updated++;
                } else {
                    $failed[] = ['index' => $index, $primaryKey => $pkValue, 'reason' => '更新失败'];
                }
            } catch (\Throwable $e) {
                // 不回显原始异常（PDO 消息可能含表名/列名/SQL 片段），详情只进日志
                \think\facade\Log::error('[wise-api] batchUpdate 失败: ' . $e->getMessage());
                $failed[] = ['index' => $index, $primaryKey => $pkValue, 'reason' => '更新失败'];
            }
        }

        return $this->success(sprintf('批量更新完成：%d/%d 成功', $updated, $total), [
            'total'   => $total,
            'updated' => $updated,
            'failed'  => $failed,
        ]);
    }

    /**
     * 批量更新状态（快捷方法）
     *
     * 请求体示例：{ "ids": [1, 2, 3], "status": 1 }
     *
     * @param string $modelClass  模型类名
     * @param string $statusField 状态字段名
     * @param string $primaryKey  主键字段名
     * @return \think\response\Json
     */
    protected function batchUpdateStatus(
        string $modelClass,
        string $statusField = 'status',
        string $primaryKey = 'id'
    ): \think\response\Json {
        $params = $this->validateRequest([
            'ids'    => 'require|array',
            'status' => 'require|integer',
        ]);

        $ids  = array_map('intval', $params['ids']);
        $status = (int) $params['status'];

        if (empty($ids)) {
            return $this->error('ids 不能为空', [], 4001);
        }

        /** @var Model $model */
        $model = new $modelClass();
        $count = $model->whereIn($primaryKey, $ids)->update([$statusField => $status]);

        return $this->success(sprintf('成功更新 %d 条记录状态', $count), [
            'updated' => $count,
            'ids'     => $ids,
            'status'  => $status,
        ]);
    }

    /**
     * 批量导入（CSV/Excel → 逐行验证 + 写入）
     *
     * 请求体：multipart/form-data
     *   file: CSV 文件
     *
     * @param string              $modelClass 模型类名
     * @param array<string,string> $mapping    列名 → 数据库字段映射 ['姓名' => 'name', '邮箱' => 'email']
     * @param array<string,string> $rules      字段验证规则 ['name' => 'require|chs', 'email' => 'require|email']
     * @return \think\response\Json
     */
    protected function batchImport(
        string $modelClass,
        array $mapping,
        array $rules = []
    ): \think\response\Json {
        $file = $this->request->file('file');
        if (!$file) {
            return $this->error('请上传文件', [], 4001);
        }

        // 读取 CSV/Excel
        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            return $this->error('仅支持 CSV / Excel 文件', [], 4001);
        }

        $rows = [];
        if ($ext === 'csv') {
            $handle = fopen($file->getPathname(), 'r');
            if (!$handle) {
                return $this->error('无法读取文件', [], 4003);
            }
            // UTF-8 BOM 处理
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                return $this->error('CSV 文件为空', [], 4001);
            }
            $header = array_map('trim', $header);
            while (($line = fgetcsv($handle)) !== false) {
                // PHP 8 起 array_combine() 在键值数量不等时抛 ValueError（不再返回 false），
                // 必须先判等，避免一行残缺 CSV 让整个导入接口 500。
                if (count($line) !== count($header)) {
                    continue;
                }
                $rows[] = array_combine($header, $line);
            }
            fclose($handle);
        } else {
            return $this->error('Excel 格式暂未支持，请使用 CSV', [], 4003);
        }

        if (empty($rows)) {
            return $this->error('无有效数据行', [], 4001);
        }

        // 映射 + 验证 + 写入
        $imported = 0;
        $failed   = [];

        /** @var Model $model */
        $model = new $modelClass();

        foreach ($rows as $index => $row) {
            // 列映射
            $data = [];
            foreach ($mapping as $colName => $field) {
                if (isset($row[$colName])) {
                    $data[$field] = trim((string) $row[$colName]);
                }
            }

            // 验证
            if (!empty($rules)) {
                $validator = app()->validate();
                $validator->rule($rules);
                if (!$validator->check($data)) {
                    $failed[] = ['row' => $index + 1, 'reason' => $validator->getError(), 'data' => $data];
                    continue;
                }
            }

            try {
                $model->create($data);
                $imported++;
            } catch (\Throwable $e) {
                // 同 batchUpdate：不回显数据库原始错误，避免泄露表名/列名/SQL 片段
                \think\facade\Log::error('[wise-api] batchImport 失败: ' . $e->getMessage());
                $failed[] = ['row' => $index + 1, 'reason' => '写入失败', 'data' => $data];
            }
        }

        return $this->success(sprintf('导入完成：%d/%d 成功', $imported, count($rows)), [
            'total'    => count($rows),
            'imported' => $imported,
            'failed'   => $failed,
        ]);
    }
}
