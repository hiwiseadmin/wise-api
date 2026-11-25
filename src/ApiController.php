<?php
declare(strict_types=1);

namespace wise\api;

use think\db\Query;
use think\Model;
use wise\api\response\ErrorCode;
use wise\api\response\ResponseWrapper;

/**
 * API 控制器基类
 *
 * - 继承 think\Controller
 * - 预置 success / error 统一响应方法
 * - 提供 allowFields / allowFieldsDeep 字段过滤
 * - 支持 ?fields=id,name,email 动态选择返回字段
 * - 支持 ?fields=id,name,profile.bio 点号语法嵌套过滤
 * - 提供 validateRequest 简化参数校验（支持 batch 模式）
 * - 提供 successWithEtag ETag/304 条件请求
 * - 提供 paginate 统一分页包装
 *
 * 业务控制器通常这样使用：
 * <pre>
 * class UserController extends ApiController
 * {
 *     protected $middleware = [
 *         \wise\api\middleware\RateLimitMiddleware::class . ':60,1',
 *         \wise\api\middleware\SignVerifyMiddleware::class,
 *         \wise\api\middleware\JwtAuthMiddleware::class,
 *     ];
 *
 *     public function index()
 *     {
 *         $params = $this->validateRequest([
 *             'page'      => 'integer|egt:1',
 *             'page_size' => 'integer|between:1,100',
 *         ]);
 *         return $this->paginate(UserModel::order('id desc'), (int)($params['page_size'] ?? 15));
 *     }
 * }
 * </pre>
 *
 * @package wise\api
 */
class ApiController
{
    /** @var ResponseWrapper 统一响应包装器（延迟注入） */
    protected ?ResponseWrapper $responseWrapper = null;

    /**
     * 初始化：注入 ResponseWrapper
     *
     * 注意：不在此处注入 $request，改用 getRequest() 懒加载。
     * 避免子类覆盖 __construct 但忘记调用 parent 导致属性未初始化。
     */
    public function __construct()
    {
        $this->responseWrapper = app(ResponseWrapper::class);
    }

    /**
     * 获取 Request 实例（懒加载，子类安全）
     *
     * 使用此方法代替直接访问 $this->request，确保：
     * 1. 子类覆盖 __construct 无副作用
     * 2. 与 ThinkPHP 容器中的 request 始终保持一致
     *
     * @return \think\Request
     */
    protected function getRequest(): \think\Request
    {
        return app('request');
    }


    /**
     * 业务成功
     *
     * C4: 支持 ErrorCode 枚举或 int。
     *
     * @param string        $msg  提示消息
     * @param mixed         $data 业务数据
     * @param ErrorCode|int $code 业务码（默认 0 = ErrorCode::SUCCESS）
     */
    protected function success(string $msg = '', mixed $data = null, ErrorCode|int $code = 0): \think\response\Json
    {
        return $this->responseWrapper->success($msg, $data, $code);
    }

    /**
     * 业务失败
     *
     * C4: 支持 ErrorCode 枚举或 int，推荐传入 ErrorCode 枚举。
     *
     * @param string        $msg  错误描述
     * @param mixed         $data 附加数据
     * @param ErrorCode|int $code 错误码（默认 ErrorCode::INVALID_PARAM）
     */
    protected function error(string $msg = 'error', mixed $data = [], ErrorCode|int $code = ErrorCode::INVALID_PARAM): \think\response\Json
    {
        return $this->responseWrapper->error($msg, $data, $code);
    }


    /**
     * 带 ETag 的业务成功响应
     *
     * 自动对 data 计算 MD5 ETag，比对 If-None-Match 请求头：
     *   - 匹配 → 返回 304 Not Modified（无 body）
     *   - 不匹配 → 返回 200 + ETag 响应头
     *
     * 适用于大规模列表接口（GET /api/v1/users）减少不必要的数据传输。
     *
     * 用法：
     *   $users = UserModel::select();
     *   return $this->successWithEtag($users);
     *
     * @param mixed  $data    业务数据
     * @param string $msg     消息
     * @param int    $code    业务码
     * @return \think\response\Json|\think\Response
     */
    protected function successWithEtag(mixed $data = null, string $msg = '', int $code = 0): \think\Response
    {
        $etag = '"' . md5(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';

        $ifNoneMatch = $this->getRequest()->header('If-None-Match', '');
        if ($ifNoneMatch === $etag) {
            return response('', 304, [
                    'ETag'   => $etag,
                    'Cache-Control' => 'private, must-revalidate',
                ]);
        }

        $response = $this->success($msg, $data, $code);
        \call_user_func([$response, 'header'], [
            'ETag'          => $etag,
            'Cache-Control' => 'private, must-revalidate',
        ]);
        return $response;
    }

    /**
     * 为已有响应附加 ETag
     *
     * @param mixed                $data     用于计算 ETag 的数据
     * @param \think\response\Json $response 已有响应
     * @return \think\response\Json
     */
    protected function withEtag(mixed $data, \think\response\Json $response): \think\response\Json
    {
        $etag = '"' . md5(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
        \call_user_func([$response, 'header'], [
            'ETag'          => $etag,
            'Cache-Control' => 'private, must-revalidate',
        ]);
        return $response;
    }


    /**
     * 过滤数据，仅保留指定字段（仅顶层）
     *
     * 支持数组或模型集合：
     *   $this->allowFields(['id','name','email'], $user);
     *   $this->allowFields(['id','name','email'], [$user1, $user2]);
     *
     * @param string[] $fields 允许的字段名
     * @param mixed    $data   数组、模型或模型集合
     */
    protected function allowFields(array $fields, mixed $data): mixed
    {
        if ($data instanceof Model) {
            $arr = $data->toArray();
            return $this->pickFields($arr, $fields);
        }

        if (is_array($data)) {
            $isList = array_is_list($data);
            if ($isList && !empty($data) && $data[0] instanceof Model) {
                return array_map(fn($m) => $this->pickFields($m->toArray(), $fields), $data);
            }
            if ($isList) {
                return array_map(fn($item) => $this->pickFields((array) $item, $fields), $data);
            }
            return $this->pickFields($data, $fields);
        }

        return $data;
    }

    /**
     * 过滤数据，支持点号语法嵌套字段
     *
     * 示例：
     *   ?fields=id,name,profile.bio,profile.avatar
     *
     *   对顶层字段 id/name 直接保留，对点号字段嵌套过滤：
     *   { "id": 1, "name": "张三", "profile": { "bio": "...", "avatar": "..." } }
     *
     * 注意：
     *   - 只支持一层嵌套（profile.bio），不支持 profile.address.city
     *   - 会保留键的原始顺序
     *
     * @param string[] $fields 允许的字段名（支持点号语法）
     * @param mixed    $data   数组、模型或模型集合
     * @return mixed
     */
    protected function allowFieldsDeep(array $fields, mixed $data): mixed
    {
        $topFields    = [];
        $nestedFields = [];

        foreach ($fields as $f) {
            if (str_contains($f, '.')) {
                [$parent, $child] = explode('.', $f, 2);
                $nestedFields[$parent][] = $child;
            } else {
                $topFields[] = $f;
            }
        }

        if ($data instanceof Model) {
            return $this->filterDeepItem($data->toArray(), $topFields, $nestedFields);
        }

        if (is_array($data) && array_is_list($data)) {
            return array_map(
                fn($item) => $this->filterDeepItem(
                    $item instanceof Model ? $item->toArray() : (array) $item,
                    $topFields,
                    $nestedFields
                ),
                $data
            );
        }

        if (is_array($data)) {
            return $this->filterDeepItem($data, $topFields, $nestedFields);
        }

        return $data;
    }

    /**
     * 对单条数据进行嵌套过滤
     *
     * @param array<string,mixed> $data
     * @param string[]            $topFields    顶层字段
     * @param array<string,string[]> $nestedFields 嵌套字段映射
     * @return array<string,mixed>
     */
    private function filterDeepItem(array $data, array $topFields, array $nestedFields): array
    {
        $result = $this->pickFields($data, $topFields);

        foreach ($nestedFields as $parent => $children) {
            if (isset($data[$parent]) && is_array($data[$parent])) {
                $result[$parent] = [];
                foreach ($children as $child) {
                    if (array_key_exists($child, $data[$parent])) {
                        $result[$parent][$child] = $data[$parent][$child];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 解析 ?fields=id,name,email 参数（支持点号嵌套）
     *
     * @param string[] $default 未指定 fields 时的默认字段（空数组 = 不过滤）
     * @return string[]
     */
    protected function resolveFields(array $default = []): array
    {
        $raw = (string) ($this->getRequest()->get('fields', '') ?? '');
        if ($raw === '') {
            return $default;
        }
        $fields = array_filter(array_map('trim', explode(',', $raw)));
        return $fields ?: $default;
    }

    /**
     * 从关联数组中挑选字段
     *
     * @param array<string,mixed> $arr
     * @param string[]            $fields
     * @return array<string,mixed>
     */
    private function pickFields(array $arr, array $fields): array
    {
        $result = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $arr)) {
                $result[$f] = $arr[$f];
            }
        }
        return $result;
    }


    /**
     * 统一分页包装
     *
     * 返回结构：
     *   {
     *     "list": [...],
     *     "total": 123,
     *     "page": 1,
     *     "page_size": 15
     *   }
     *
     * @param Query|Model|\think\db\Query $query     查询构造器或模型
     * @param int                          $pageSize  每页条数
     * @param string                       $pageParam 页码参数名（默认 page）
     * @param string[]                     $fields    过滤字段（可选）
     */
    protected function paginate($query, int $pageSize = 15, string $pageParam = 'page', array $fields = []): \think\response\Json
    {
        $page = max(1, (int) ($this->getRequest()->get($pageParam, 1)));

        if (empty($fields)) {
            $fields = $this->resolveFields();
        }

        if ($query instanceof Model) {
            $query = $query->db();
        }

        $paginator = $query->paginate(['list_rows' => $pageSize, 'page' => $page], false);

        $list = $paginator->items();
        if (!empty($fields)) {
            $hasNested = !empty(array_filter($fields, fn($f) => str_contains($f, '.')));
            $list = $hasNested
                ? $this->allowFieldsDeep($fields, $list)
                : $this->allowFields($fields, $list);
        }

        return $this->success('', [
            'list'      => $list,
            'total'     => $paginator->total(),
            'page'      => $paginator->currentPage(),
            'page_size' => $paginator->listRows(),
        ]);
    }


    /**
     * 简化参数校验（基于 ThinkPHP 内置 validate）
     *
     * 支持两种模式：
     *   - 默认模式（batch=false）：遇第一个错误即抛异常
     *   - 批量模式（batch=true）：收集所有字段的验证错误，一次性返回
     *
     * 批量模式响应示例：
     *   {
     *     "code": 4000,
     *     "msg": "参数验证失败",
     *     "data": {
     *       "errors": {
     *         "name": ["不能为空", "长度必须在2-20之间"],
     *         "email": ["格式无效"]
     *       }
     *     }
     *   }
     *
     * @param array<string,string> $rules    字段 => 规则（同 Validate::rule）
     * @param array<string,string> $messages 错误消息（可选）
     * @param bool                  $batch    是否批量验证模式（默认 false）
     * @return array<string,mixed>           过滤后的参数
     */
    protected function validateRequest(array $rules, array $messages = [], bool $batch = false): array
    {
        $decryptedInput = $this->getDecryptedInput();
        if (!empty($decryptedInput)) {
            $params = $decryptedInput;
        } else {
            $params = array_merge($this->getRequest()->get(), $this->getRequest()->post());
            $contentType = $this->getRequest()->contentType();
            if ($contentType && stripos($contentType, 'application/json') !== false) {
                $json = json_decode($this->getRequest()->getContent(), true);
                if (is_array($json)) {
                    $params = array_merge($params, $json);
                }
            }
        }

        $validator = app('validate');
        $validator->rule($rules);
        if (!empty($messages)) {
            $validator->message($messages);
        }

        if ($batch) {
            if (!$validator->batch()->check($params)) {
                $rawErrors = $validator->getError();
                throw new \think\exception\ValidateException(
                    '参数验证失败',
                    ['errors' => $rawErrors]
                );
            }
            return $params;
        }

        if (!$validator->check($params)) {
            throw new \think\exception\ValidateException($validator->getError());
        }
        return $params;
    }


    /**
     * 获取当前 API 已认证用户的用户对象
     *
     * 优先通过 AuthManager 获取（统一认证入口），
     * 回退到 request->wise_user 属性（兼容旧中间件）。
     *
     * @return object|array|null 用户对象（AuthManager 返回）或用户数组（旧属性）
     */
    protected function currentUser(): object|array|null
    {
        if (function_exists('auth_user')) {
            $user = auth_user('api');
            if ($user !== null) {
                return $user;
            }
        }
        return $this->getRequest()->wise_user ?? null;
    }


    /**
     * 获取解密后的请求参数（PayloadEncryptMiddleware 注入）
     *
     * 当请求经过 PayloadEncryptMiddleware 解密后，原始明文 JSON
     * 存储在 request 属性中。此方法优先返回解密数据，未加密时回退到
     * request->post() 的传统行为。
     *
     * 用法：
     *   $params = $this->getDecryptedInput();
     *   $username = $params['username'] ?? '';
     *
     * @return array<string,mixed>
     */
    protected function getDecryptedInput(): array
    {
        $decryptedJson = $this->getRequest()->{ \wise\api\middleware\PayloadEncryptMiddleware::ATTR_DECRYPTED_JSON} ?? null;
        if ($decryptedJson !== null && is_string($decryptedJson)) {
            $data = json_decode($decryptedJson, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return $this->getRequest()->post() ?: [];
    }
}
