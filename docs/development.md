# 开发指南

## 1. 代码约定

- 严格类型：所有 PHP 文件 `declare(strict_types=1)`
- 命名空间：`App\` 对应 `app/` 目录，PSR-4 自动加载，**不使用 Composer**
- 注释与文档统一使用中文；业务解释优先写「为什么」，而不是复述代码
- 所有输出给前端的敏感字段必须脱敏（`mask_secret()`）
- 数据库访问只允许出现在 `app/Repositories` 下，服务与控制器不直接拼 SQL
- 新增配置一律经 `env()` / `config()` 读取，不在业务代码里写死

## 2. 请求处理链路

```php
// routes/*.php
$router->get('/api/user/example', ExampleController::class . '@index', [
    CorsMiddleware::class,
    UserAuthMiddleware::class,
]);
```

```php
// app/Controllers/User/ExampleController.php
final class ExampleController
{
    public function __construct(private ExampleService $service) {}

    public function index(Request $request): Response
    {
        return Response::json(['data' => $this->service->list(Tenant::userId())]);
    }
}
```

容器按构造函数类型提示自动注入依赖；控制器方法的 `Request` 参数会复用当前请求实例（保留路由参数）。

## 3. 常用辅助

| 函数 / 类 | 用途 |
| --- | --- |
| `env()` / `env_raw()` | 读取环境变量（后者不做类型转换） |
| `config()` | 读取 `config/*.php` 的配置 |
| `now_ms()` / `uuid()` / `short_id()` | 时间戳与 ID 生成 |
| `storage_path()` | `storage/` 绝对路径 |
| `mask_secret()` | 敏感串脱敏 |
| `admin_login_path()` | 规范化后的管理端登录入口 |
| `App\Support\Logger` | 写每日日志与错误日志 |
| `App\Support\Tenant` | 请求级租户作用域 |
| `App\Support\HttpClient` | 统一 HTTP 客户端（代理、超时、重试） |
| `App\Support\JsonSchema` | 工具 Schema 归一化 |
| `App\Support\Crypto` | 凭据加解密 |

## 4. 日志

```php
\App\Support\Logger::error('未捕获异常：' . $e->getMessage(), [
    'file'  => $e->getFile() . ':' . $e->getLine(),
    'path'  => $request->path(),
]);
```

- 应用日志：`storage/logs/app-YYYY-MM-DD.log`
- PHP 错误：`storage/logs/php-error.log`
- worker：`storage/logs/action-worker.log` 等

日志中的请求体需先经脱敏（参考 `app/Repositories/LogRepository` 与 `App\Support\Audit` 的处理方式）。

## 5. 错误处理

- 业务可预期错误用 `App\Exceptions\*`，由控制器转换为统一 JSON
- 上游错误经 `Provider::parseError()` 归一化为 `ErrorCategory`
- 网关对外错误必须保持 OpenAI 风格：`{"error":{"message","type","code"}}`
- 生产环境禁止把堆栈、SQL、路径返回客户端

## 6. 数据库变更

1. 修改 `database/schema.sql`（注意：该文件用于全新安装，每张表前有 `DROP TABLE IF EXISTS`）
2. 生产环境**不要**用 `install.php --force` 升级；应单独准备增量 SQL
3. 仓储层新增查询时统一使用 `aigw_` 前缀常量，不硬编码表名

## 7. SSE 输出

流式接口必须：

- 关闭输出缓冲与压缩
- 每帧以 `data: {...}\n\n` 结束
- 终态后发送 `data: [DONE]`
- 客户端断开时停止继续产出（`connection_aborted()` / 回调返回 `false`）

参考 `GatewayController` 中 chat completions 与 responses 的流式实现。

## 8. 关于测试与工具脚本

本仓库**不包含**原开发目录中的：

- `tests/`：接口与回归测试脚本
- `tools/`：探针、诊断、实验脚本
- `mobile/`：Flutter 客户端
- `storage/` 运行时数据、数据库导出、下载分发包

如需在本地自测，建议在自己的私有仓库或本地目录维护，不要提交到本仓库。

## 9. 提交前自检

```bash
# 语法检查（逐个文件）
find app routes config bootstrap bin database -name '*.php' -print0 \
  | xargs -0 -n1 php -l

# 确认没有把敏感文件加入暂存区
git status
git diff --cached --name-only | grep -E '\.env$|\.sql$|\.zip$|\.tar\.gz$' || true
```

上线前建议再跑一遍：`GET /v1/health`、登录管理台、发起一次流式对话、查看 `storage/logs/`。
