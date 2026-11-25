/**
 * api_log.js -- ESM 模块
 *
 * 对接 wise-api 的 api_log 表结构。
 * 控制器已将 log_id 别名为 id 以兼容 EnhancedTable 组件。
 */
const $ = window.jQuery;
const Table = window.EnhancedTable;
const FormValidator = window.FormValidator;

/**
 * HTML 转义
 *
 * 日志字段（trace_id / request_url / user_agent 等）来源于请求方，可被伪造；
 * 自定义 formatter 的返回值会被当作 HTML 渲染，拼接前必须转义，
 * 否则后台接口日志列表存在存储型 XSS（可劫持超管会话）。
 */
function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

const Controller = {
    index() {
        const table = new Table({
            idTable: 'table',
            url: '/admin/api_log/getList',
            showColumns: true,
            pagination: true,
            dataDataField: 'data.list',
            extend: {
                index_url: '/admin/api_log/index',
                preview_url: '/admin/api_log/preview',
                // 删除接口（工具栏批量删除与行内删除共用）：
                // 工具栏传 { ids: [log_id, ...] }，行内传 { id: log_id }
                del_url: '/admin/api_log/delete',
            },
            toolbarBtn: ['preview', 'refresh', 'export', 'del'],
            // 请求数据前的参数处理
            queryParams: function(params) {
                // 可以在这里添加额外的筛选参数
                return params;
            },
            columns: [
                {
                    field: 'ck',
                    checkbox: true,
                    align: 'center',
                    valign: 'middle',
                },
                {
                    field: 'id',
                    title: '日志 ID',
                    width: '140',
                    sortable: true,
                    formatter: function(value) {
                        // UUID 过长时截断显示（esc 转义，避免拼接 HTML 时 XSS）
                        var safe = esc(value);
                        if (safe && safe.length > 10) {
                            return '<span title="' + safe + '" style="cursor:default;">' + safe.substring(0, 8) + '...</span>';
                        }
                        return safe;
                    },
                },
                {
                    field: 'request_route',
                    title: '接口路由',
                    formatter: 'normal',
                    align: 'left',
                },
                {
                    field: 'request_url',
                    title: '请求 URL',
                    align: 'left',
                    // 长 URL 在列表页截断（esc 转义：URL 由请求方构造，可携带 HTML）
                    formatter: function(value) {
                        var safe = esc(value);
                        if (safe && safe.length > 50) {
                            return '<span title="' + safe + '">' + safe.substring(0, 50) + '...</span>';
                        }
                        return safe || '-';
                    },
                },
                {
                    field: 'request_method',
                    title: '请求方法',
                    width: '90',
                    align: 'center',
                    formatter: function(value) {
                        var colors = {
                            'GET': 'success',
                            'POST': 'primary',
                            'PUT': 'warning',
                            'PATCH': 'info',
                            'DELETE': 'danger',
                            'HEAD': 'secondary',
                            'OPTIONS': 'secondary',
                        };
                        var color = colors[value] || 'secondary';
                        return '<span class="badge bg-' + color + '">' + (value || '-') + '</span>';
                    },
                },
                {
                    field: 'request_ip',
                    title: '请求 IP',
                    width: '130',
                    formatter: 'normal',
                    align: 'center',
                },
                {
                    field: 'response_status',
                    title: '状态码',
                    width: '80',
                    align: 'center',
                    formatter: function(value) {
                        var status = parseInt(value) || 0;
                        var color = 'secondary';
                        if (status >= 200 && status < 300) color = 'success';
                        else if (status >= 300 && status < 400) color = 'info';
                        else if (status >= 400 && status < 500) color = 'warning';
                        else if (status >= 500) color = 'danger';
                        return '<span class="badge bg-' + color + '">' + (status || '-') + '</span>';
                    },
                },
                {
                    field: 'duration_ms',
                    title: '耗时',
                    width: '80',
                    sortable: true,
                    align: 'center',
                    formatter: function(value) {
                        var ms = parseInt(value) || 0;
                        if (ms > 3000) {
                            return '<span class="text-danger fw-bold">' + ms + ' ms</span>';
                        } else if (ms > 1000) {
                            return '<span class="text-warning">' + ms + ' ms</span>';
                        }
                        return '<span class="text-success">' + (ms || '0') + ' ms</span>';
                    },
                },
                {
                    field: 'trace_id',
                    title: 'Trace ID',
                    width: '100',
                    // trace_id 取自客户端可伪造的 X-Request-Id / X-Trace-Id 头，必须转义
                    formatter: function(value) {
                        var safe = esc(value);
                        if (safe && safe.length > 10) {
                            return '<span title="' + safe + '" style="cursor:default;font-size:0.8rem;">' + safe.substring(0, 8) + '...</span>';
                        }
                        return safe || '-';
                    },
                },
                {
                    field: 'channel',
                    title: '通道',
                    width: '80',
                    align: 'center',
                    formatter: function(value) {
                        return value || '<span class="text-muted">-</span>';
                    },
                },
                {
                    field: 'create_time',
                    title: '请求时间',
                    width: '160',
                    sortable: true,
                    align: 'center',
                },
                {
                    field: 'operate',
                    buttons: ['preview', 'del'],
                    title: '操作',
                    width: '120',
                    formatter: 'operate',
                },
            ],
        });
    },
};

export default Controller;
