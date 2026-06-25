<?php
declare(strict_types=1);

namespace wise\api\trait;

use think\Model;

/**
 * 批量操作 Trait
 *
 * 为 ApiController 提供批量删除/更新/导入等批量 API 能力。
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
 * @package wise\api\trait
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
            return $this->error(4001, 'ids 不能为空');
        }

        /** @var Model $model */
        $model = new $modelClass();
        $count = $model->whereIn($primaryKey, $ids)->delete();

        return $this->success([
            'deleted' => $count,
            'ids'     => $ids,
        ], sprintf('成功删除 %d 条记录', $count));
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
                $failed[] = ['index' => $index, $primaryKey => $pkValue, 'reason' => $e->getMessage()];
            }
        }

        return $this->success([
            'total'   => $total,
            'updated' => $updated,
            'failed'  => $failed,
        ], sprintf('批量更新完成：%d/%d 成功', $updated, $total));
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
            return $this->error(4001, 'ids 不能为空');
        }

        /** @var Model $model */
        $model = new $modelClass();
        $count = $model->whereIn($primaryKey, $ids)->update([$statusField => $status]);

        return $this->success([
            'updated' => $count,
            'ids'     => $ids,
            'status'  => $status,
        ], sprintf('成功更新 %d 条记录状态', $count));
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
            return $this->error(4001, '请上传文件');
        }

        // 读取 CSV/Excel
        $ext = strtolower($file->getOriginalExtension());
        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            return $this->error(4001, '仅支持 CSV / Excel 文件');
        }

        $rows = [];
        if ($ext === 'csv') {
            $handle = fopen($file->getPathname(), 'r');
            if (!$handle) {
                return $this->error(4003, '无法读取文件');
            }
            // UTF-8 BOM 处理
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                return $this->error(4001, 'CSV 文件为空');
            }
            $header = array_map('trim', $header);
            while (($line = fgetcsv($handle)) !== false) {
                $row = array_combine($header, $line);
                if ($row !== false) {
                    $rows[] = $row;
                }
            }
            fclose($handle);
        } else {
            return $this->error(4003, 'Excel 格式暂未支持，请使用 CSV');
        }

        if (empty($rows)) {
            return $this->error(4001, '无有效数据行');
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
                $failed[] = ['row' => $index + 1, 'reason' => $e->getMessage(), 'data' => $data];
            }
        }

        return $this->success([
            'total'    => count($rows),
            'imported' => $imported,
            'failed'   => $failed,
        ], sprintf('导入完成：%d/%d 成功', $imported, count($rows)));
    }
}
