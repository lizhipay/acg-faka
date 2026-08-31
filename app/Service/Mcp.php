<?php
declare(strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

/**
 * 开发者中心 MCP 工具服务。
 *
 * 商店侧暴露「查询 / 创建+上传 / 定价」三类开发者操作，全部经由本站已登录的
 * 应用商店账号（config/store.php 的 app_id/app_key 签名）中转到商店，
 * 调用方（AI 工具）永远接触不到商店凭据。
 *
 * 本机侧暴露「已装插件列表 / 启停 / 配置查改 / 日志读清」运维操作，
 * 与后台「功能插件」页同一套内核流程，安全边界见 Bind\Mcp 头注释。
 *
 * @package App\Service
 */
#[Bind(class: \App\Service\Bind\Mcp::class)]
interface Mcp
{
    /**
     * 返回 MCP tools/list 使用的工具清单（含 name/description/inputSchema）。
     * @return array
     */
    public function tools(): array;

    /**
     * 执行一个工具，返回结果数据（失败时抛 JSONException，由控制器转成 isError）。
     * @param string $name 工具名
     * @param array $arguments 工具入参（已由 JSON-RPC 解码）
     * @return array
     */
    public function call(string $name, array $arguments): array;
}
