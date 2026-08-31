<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Model\ManageLog;
use App\Util\Client;
use App\Util\Date;
use App\Util\Str;
use Kernel\Annotation\Inject;
use Kernel\Cache\Cache;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;

/**
 * 开发者中心 MCP Server（Streamable HTTP，纯 JSON 响应）。
 *
 * 公开端点 POST /admin/mcp/server，用「访问秘钥」(config/mcp.php) 做 bearer 鉴权，
 * 不走后台会话。AdminEntrance::guard() 已放行 /admin/mcp/*（见 app/Util/AdminEntrance.php）。
 *
 * 故意不加 #[Interceptor]：ManageSession 的 referer/cookie 校验会挡住无会话的 AI 客户端；
 * WAF 只扫 $_GET/$_POST/$_COOKIE，不碰 php://input，所以裸 JSON-RPC 请求体不受影响。
 *
 * @package App\Controller\Admin
 */
class Mcp
{
    #[Inject]
    private \App\Service\Mcp $mcp;

    /**
     * 每把秘钥每分钟最多调用次数。
     */
    private const RATE_LIMIT_PER_MIN = 120;

    /**
     * 支持的 MCP 协议版本。
     */
    private const PROTOCOL_VERSIONS = ["2024-11-05", "2025-03-26", "2025-06-18"];

    /**
     * @param Request $request
     * @return null
     */
    public function server(Request $request)
    {
        //仅接受 POST；GET 常被客户端用于探测 SSE，返回 405 表示不提供服务器推流
        if ($request->method() !== "POST") {
            $this->emit(405, ["jsonrpc" => "2.0", "id" => null, "error" => ["code" => -32600, "message" => "Only POST is supported"]], ["Allow: POST"]);
        }

        $cfg = (array)config("mcp");
        $stored = (string)($cfg['access_key'] ?? "");
        $provided = $this->extractKey($request);

        //未配置或秘钥不匹配一律 401（不区分，避免泄露是否已配置）
        if ($stored === "" || !Str::safetyEquals($provided, $stored)) {
            $this->emit(401, ["jsonrpc" => "2.0", "id" => null, "error" => ["code" => -32001, "message" => "Unauthorized"]], ['WWW-Authenticate: Bearer']);
        }

        //已认证但被管理员停用
        if (empty($cfg['enabled'])) {
            $this->emit(403, ["jsonrpc" => "2.0", "id" => null, "error" => ["code" => -32003, "message" => "MCP 接口已停用，请在开发者中心开启"]]);
        }

        if ($this->rateLimited($stored)) {
            $this->emit(429, ["jsonrpc" => "2.0", "id" => null, "error" => ["code" => -32000, "message" => "Rate limit exceeded, retry later"]]);
        }

        $message = json_decode($request->raw(), true);
        if (!is_array($message)) {
            $this->emit(200, ["jsonrpc" => "2.0", "id" => null, "error" => ["code" => -32700, "message" => "Parse error"]]);
        }

        //JSON-RPC 批量请求（数组）2025-06-18 已移除，不支持。手写判定以兼容 PHP 8.0（array_is_list 是 8.1）
        $keys = array_keys($message);
        if ($keys !== [] && $keys === range(0, count($keys) - 1)) {
            $this->emit(200, ["jsonrpc" => "2.0", "id" => null, "error" => ["code" => -32600, "message" => "Batch requests are not supported"]]);
        }

        //通知（无 id）：如 notifications/initialized，回 202 空响应
        if (!array_key_exists("id", $message)) {
            if (!headers_sent()) {
                http_response_code(202);
            }
            exit;
        }

        $id = $message['id'];
        $method = (string)($message['method'] ?? "");
        $params = (array)($message['params'] ?? []);

        try {
            $result = $this->dispatch($method, $params, $id);
        } catch (\Throwable $e) {
            $this->emit(200, ["jsonrpc" => "2.0", "id" => $id, "error" => ["code" => -32603, "message" => "Internal error"]]);
        }

        $this->emit(200, $result);
    }

    /**
     * @param string $method
     * @param array $params
     * @param mixed $id
     * @return array
     */
    private function dispatch(string $method, array $params, mixed $id): array
    {
        switch ($method) {
            case "initialize":
                $req = (string)($params['protocolVersion'] ?? "");
                $version = in_array($req, self::PROTOCOL_VERSIONS, true) ? $req : "2025-06-18";
                return [
                    "jsonrpc" => "2.0",
                    "id" => $id,
                    "result" => [
                        "protocolVersion" => $version,
                        "capabilities" => ["tools" => ["listChanged" => false]],
                        "serverInfo" => [
                            "name" => "acg-faka-developer",
                            "version" => (string)((array)config("app"))['version'] ?: "1.0.0",
                        ],
                        "instructions" => "本站开发者中心与本机插件运维的 MCP 接口。商店侧：list_plugins 查看你名下插件与审核状态，create_plugin/upload_install_kit/submit_update 管理插件包（服务端自动打包），set_price 改定价。本机侧：local_plugins 列出已装插件，plugin_start/plugin_stop 启停，plugin_config_get/plugin_config_set 查改配置（读取默认脱敏），plugin_log_read/plugin_log_clear 读清日志。",
                    ],
                ];

            case "ping":
                return ["jsonrpc" => "2.0", "id" => $id, "result" => new \stdClass()];

            case "tools/list":
                return ["jsonrpc" => "2.0", "id" => $id, "result" => ["tools" => $this->mcp->tools()]];

            case "tools/call":
                return $this->callTool($params, $id);

            default:
                return ["jsonrpc" => "2.0", "id" => $id, "error" => ["code" => -32601, "message" => "Method not found: {$method}"]];
        }
    }

    /**
     * @param array $params
     * @param mixed $id
     * @return array
     */
    private function callTool(array $params, mixed $id): array
    {
        $name = (string)($params['name'] ?? "");
        $arguments = (array)($params['arguments'] ?? []);

        try {
            $data = $this->mcp->call($name, $arguments);
            $this->audit($name, $arguments, true, "");
            return [
                "jsonrpc" => "2.0",
                "id" => $id,
                "result" => [
                    "content" => [["type" => "text", "text" => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                    "structuredContent" => $data,
                    "isError" => false,
                ],
            ];
        } catch (JSONException $e) {
            $this->audit($name, $arguments, false, $e->getMessage());
            return [
                "jsonrpc" => "2.0",
                "id" => $id,
                "result" => [
                    "content" => [["type" => "text", "text" => $e->getMessage()]],
                    "isError" => true,
                ],
            ];
        } catch (\Throwable $e) {
            $this->audit($name, $arguments, false, "internal");
            return [
                "jsonrpc" => "2.0",
                "id" => $id,
                "result" => [
                    "content" => [["type" => "text", "text" => "内部错误，请稍后重试"]],
                    "isError" => true,
                ],
            ];
        }
    }

    /**
     * 从 Authorization: Bearer 或 X-Access-Key 头提取秘钥。
     * @param Request $request
     * @return string
     */
    private function extractKey(Request $request): string
    {
        $auth = (string)$request->header("Authorization");
        if (preg_match('/^\s*Bearer\s+(\S+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        $x = $request->header("XAccessKey");
        if (is_string($x) && trim($x) !== "") {
            return trim($x);
        }
        return "";
    }

    /**
     * 固定窗口限流：每把秘钥每分钟 RATE_LIMIT_PER_MIN 次。
     * @param string $stored
     * @return bool
     */
    private function rateLimited(string $stored): bool
    {
        try {
            $cache = new Cache(BASE_PATH . "/runtime/mcp", Cache::OPTIONS_SERIALIZE);
            $key = "rate_" . substr(hash("sha256", $stored), 0, 16);
            $now = time();
            $rec = $cache->has($key) ? $cache->get($key) : null;
            if (!is_array($rec) || ($now - (int)($rec['w'] ?? 0)) >= 60) {
                $rec = ["w" => $now, "c" => 0];
            }
            $rec['c'] = (int)$rec['c'] + 1;
            $cache->set($key, $rec);
            return $rec['c'] > self::RATE_LIMIT_PER_MIN;
        } catch (\Throwable $e) {
            //限流器自身故障不应阻断正常调用
            return false;
        }
    }

    /**
     * 审计：每次 tools/call 落一条 [MCP] 管理日志（脱敏，绝不记 base64/密钥）。
     * @param string $tool
     * @param array $args
     * @param bool $ok
     * @param string $err
     * @return void
     */
    private function audit(string $tool, array $args, bool $ok, string $err): void
    {
        try {
            $safe = [];
            foreach ($args as $k => $v) {
                if (str_contains(strtolower((string)$k), "base64")) {
                    $safe[$k] = "<" . strlen((string)$v) . " chars>";
                } elseif (is_scalar($v)) {
                    $safe[$k] = $v;
                }
            }
            $safe = maskSensitive($safe);
            $content = "[MCP] {$tool}(" . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ") " . ($ok ? "成功" : ("失败:" . $err));

            $log = new ManageLog();
            $log->email = "mcp@local";
            $log->nickname = "[MCP]";
            $log->content = mb_substr($content, 0, 950);
            $log->create_time = Date::current();
            $log->create_ip = Client::getAddress();
            $log->ua = mb_substr(Client::getUserAgent(), 0, 180) . " /MCP";
            $log->risk = 1;
            $log->save();
        } catch (\Throwable $e) {
            //审计失败不影响主流程
        }
    }

    /**
     * 输出 JSON 响应并终止（绕过内核的 {code,msg,data} 信封）。
     * @param int $status
     * @param array $payload
     * @param array $headers 额外响应头
     * @return never
     */
    private function emit(int $status, array $payload, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header("Content-Type: application/json; charset=utf-8");
            header("Cache-Control: no-store");
            foreach ($headers as $h) {
                header($h);
            }
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
