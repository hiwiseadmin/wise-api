<?php
declare(strict_types=1);

namespace wise\api\trait;

/**
 * Mock 模式 Trait
 *
 * 为 ApiController 提供 Debug 模式下的 Mock 数据能力。
 *
 * 触发条件（三者缺一不可）：
 *   1. app()->isDebug() === true
 *   2. 请求参数中 ?mock=1 或 Header X-Mock-Mode: 1
 *   3. 控制器提供了对应方法的 mock 数据
 *
 * 用法：
 *   class UserController extends ApiController
 *   {
 *       use MockTrait;
 *
 *       public function index()
 *       {
 *           // 优先返回 Mock 数据
 *           if ($mock = $this->tryMock('users_list')) {
 *               return $mock;
 *           }
 *           // 正常业务逻辑...
 *       }
 *
 *       // 定义 Mock 数据（建议放在单独文件中）
 *       protected function mockData(): array
 *       {
 *           return [
 *               'users_list' => [
 *                   'list' => [
 *                       ['id' => 1, 'name' => 'Mock 张三', 'email' => 'mock@example.com'],
 *                       ['id' => 2, 'name' => 'Mock 李四', 'email' => 'mock2@example.com'],
 *                   ],
 *                   'total' => 2,
 *                   'page'  => 1,
 *                   'page_size' => 15,
 *               ],
 *               'user_detail' => ['id' => 1, 'name' => 'Mock 张三', 'email' => 'mock@example.com'],
 *           ];
 *       }
 *   }
 *
 * 安全：仅 Debug 模式可用，生产环境自动忽略 ?mock=1 参数。
 *
 * @package wise\api\trait
 */
trait MockTrait
{
    /**
     * 尝试返回 Mock 数据
     *
     * @param string $key       Mock 数据键名（对应 mockData() 中的 key）
     * @param int    $delayMs   模拟延迟/毫秒（0 表示不延迟）
     * @return \think\response\Json|null Mock 命中返回 Json，否则返回 null
     */
    protected function tryMock(string $key, int $delayMs = 0): ?\think\response\Json
    {
        if (!$this->shouldMock()) {
            return null;
        }

        $data = $this->getMockData($key);
        if ($data === null) {
            return null;
        }

        // 模拟网络延迟
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        return $this->success($data, 'mock: ' . $key);
    }

    /**
     * 判断是否应返回 Mock 数据
     */
    protected function shouldMock(): bool
    {
        // 仅 Debug 模式
        if (!app()->isDebug()) {
            return false;
        }

        // 从请求参数或 Header 读取
        $mockParam = $this->request->param('mock', '');
        $mockHeader = $this->request->header('X-Mock-Mode', '');

        return $mockParam === '1' || $mockParam === 'true' || $mockHeader === '1';
    }

    /**
     * 获取指定 key 的 Mock 数据
     *
     * 先从子类 mockData() 方法查找，支持点号嵌套。
     *
     * @return mixed|null 找到返回数据，否则 null
     */
    protected function getMockData(string $key): mixed
    {
        // 子类应覆盖此方法来提供数据（如果未定义则返回 null）
        if (!method_exists($this, 'mockData')) {
            return null;
        }

        $data = $this->mockData();
        if (!is_array($data)) {
            return null;
        }

        // 支持点号嵌套：'users.list' → $data['users']['list']
        if (str_contains($key, '.')) {
            $keys = explode('.', $key);
            foreach ($keys as $k) {
                if (!is_array($data) || !array_key_exists($k, $data)) {
                    return null;
                }
                $data = $data[$k];
            }
            return $data;
        }

        return $data[$key] ?? null;
    }
}
