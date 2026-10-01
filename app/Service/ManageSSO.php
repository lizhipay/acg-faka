<?php
declare(strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

/**
 * Interface ManageSSO
 * @package App\Service
 */
#[Bind(class: \App\Service\Bind\ManageSSO::class)]
interface ManageSSO
{
    /**
     * 登录
     * @param string $username
     * @param string $password
     * @param bool $remember
     * @param string $code 谷歌验证器动态码（已绑定时必填）
     * @return array
     */
    public function login(string $username, string $password, bool $remember = false, string $code = ''): array;

    /**
     * passkey(WebAuthn) 登入：断言已在控制器校验通过，仅做状态检查与会话签发收尾。
     * @param \App\Model\Manage $manage 已通过断言校验的管理员
     * @param bool $remember
     * @return array
     */
    public function issueForManage(\App\Model\Manage $manage, bool $remember = false): array;
}