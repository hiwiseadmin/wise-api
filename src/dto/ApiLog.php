<?php

declare(strict_types=1);

namespace wise\api\dto;

/**
 * API日志值对象
 *
 * 不可变的日志数据传输对象，包含一次 API 请求的完整信息
 */
class ApiLog
{
    /** @var string 日志主键 UUID v4 */
    private string $logId;

    /** @var string 请求唯一标识 */
    private string $requestId;

    /** @var string 应用标识 */
    private string $appKey;

    /** @var int 接口耗时(毫秒) */
    private int $durationMs;

    /** @var string 客户端IP */
    private string $requestIp;

    /** @var string HTTP方法 */
    private string $requestMethod;

    /** @var string 完整请求URL */
    private string $requestUrl;

    /** @var string TP6路由标识 */
    private string $requestRoute;

    /** @var array<string, string>|null 请求头JSON */
    private ?array $requestHeaders;

    /** @var string|null 请求体 */
    private ?string $requestBody;

    /** @var array<string, mixed>|null URL查询参数JSON */
    private ?array $requestQuery;

    /** @var int HTTP响应状态码 */
    private int $responseStatus;

    /** @var array<string, string>|null 响应头JSON */
    private ?array $responseHeaders;

    /** @var string|null 响应体 */
    private ?string $responseBody;

    /** @var string 用户ID */
    private string $userId;

    /** @var string User-Agent */
    private string $userAgent;

    /** @var string 分布式追踪ID */
    private string $traceId;

    /** @var string 日志通道 */
    private string $channel;

    /** @var array<string, mixed>|null 扩展字段JSON */
    private ?array $extra;

    /** @var string 创建时间 */
    private string $createdAt;

    /**
     * @param array<string, mixed> $data 日志数据
     */
    public function __construct(array $data = [])
    {
        $this->logId = $data['log_id'] ?? $this->generateUuidV4();
        $this->requestId = (string)($data['request_id'] ?? '');
        $this->appKey = (string)($data['app_key'] ?? '');
        $this->durationMs = (int)($data['duration_ms'] ?? 0);
        $this->requestIp = (string)($data['request_ip'] ?? '');
        $this->requestMethod = (string)($data['request_method'] ?? '');
        $this->requestUrl = (string)($data['request_url'] ?? '');
        $this->requestRoute = (string)($data['request_route'] ?? '');
        $this->requestHeaders = $data['request_headers'] ?? null;
        $this->requestBody = $data['request_body'] ?? null;
        $this->requestQuery = $data['request_query'] ?? null;
        $this->responseStatus = (int)($data['response_status'] ?? 0);
        $this->responseHeaders = $data['response_headers'] ?? null;
        $this->responseBody = $data['response_body'] ?? null;
        $this->userId = (string)($data['user_id'] ?? '');
        $this->userAgent = (string)($data['user_agent'] ?? '');
        $this->traceId = (string)($data['trace_id'] ?? '');
        $this->channel = (string)($data['channel'] ?? 'api');
        $this->extra = $data['extra'] ?? null;
        $this->createdAt = $data['create_time'] ?? date('Y-m-d H:i:s');
    }

    /**
     * 生成 UUID v4
     */
    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // 版本 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // 变体 RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 转换为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'log_id' => $this->logId,
            'request_id' => $this->requestId,
            'app_key' => $this->appKey,
            'duration_ms' => $this->durationMs,
            'request_ip' => $this->requestIp,
            'request_method' => $this->requestMethod,
            'request_url' => $this->requestUrl,
            'request_route' => $this->requestRoute,
            'request_headers' => $this->requestHeaders,
            'request_body' => $this->requestBody,
            'request_query' => $this->requestQuery,
            'response_status' => $this->responseStatus,
            'response_headers' => $this->responseHeaders,
            'response_body' => $this->responseBody,
            'user_id' => $this->userId,
            'user_agent' => $this->userAgent,
            'trace_id' => $this->traceId,
            'channel' => $this->channel,
            'extra' => $this->extra,
            'create_time' => $this->createdAt,
        ];
    }

    /**
     * 转换为 JSON 字符串
     *
     * @param int $flags JSON 编码标志
     */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE): string
    {
        return (string)json_encode($this->toArray(), $flags);
    }

    // ---- Getter 方法 ----

    public function getLogId(): string
    {
        return $this->logId;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getAppKey(): string
    {
        return $this->appKey;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getRequestIp(): string
    {
        return $this->requestIp;
    }

    public function getRequestMethod(): string
    {
        return $this->requestMethod;
    }

    public function getRequestUrl(): string
    {
        return $this->requestUrl;
    }

    public function getRequestRoute(): string
    {
        return $this->requestRoute;
    }

    /**
     * @return array<string, string>|null
     */
    public function getRequestHeaders(): ?array
    {
        return $this->requestHeaders;
    }

    public function getRequestBody(): ?string
    {
        return $this->requestBody;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRequestQuery(): ?array
    {
        return $this->requestQuery;
    }

    public function getResponseStatus(): int
    {
        return $this->responseStatus;
    }

    /**
     * @return array<string, string>|null
     */
    public function getResponseHeaders(): ?array
    {
        return $this->responseHeaders;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExtra(): ?array
    {
        return $this->extra;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }
}
