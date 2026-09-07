<?php
declare(strict_types=1);

namespace App\Entity;

/**
 * 风控判决载体。
 *
 * 核心在受保护的入口创建它，**按引用**传给钩子；订阅方改对象、返回 null，
 * 核心随后读 action 决定怎么走。
 *
 * 为什么不靠钩子返回值：`kernel/Util/Plugin.php::hook()` 遇到 bool 会**短路整条链**，
 * 第一个订阅方就把后面的都挡住了，没法多方投票。
 *
 * 也不能只靠抛异常：拒绝可以抛，但「挂人工审核」不行 ——
 * 那需要核心知道「账号照建，但别给他签发会话」，光抛异常表达不了。
 *
 * @see \App\Consts\Hook 0x2300 ~ 0x2305
 */
class RiskContext
{
    /** 放行 */
    public const PASS = 0;
    /** 静默降权限额：放行，但由订阅方自己给这个身份挂软约束 */
    public const LIMIT = 1;
    /** 挂人工审核 */
    public const REVIEW = 2;
    /** 直接拒绝 */
    public const DENY = 3;

    /** register|login|order|recharge|cash|password|ticket|delivery */
    public string $scene = '';

    public int $action = self::PASS;

    /** 给用户看的一句话。订阅方**必须自己 lang() 过**再放进来 */
    public string $reason = '';

    /** 可查证的请求编号，核心原样回显给用户，站长凭它在插件后台查完整取证 */
    public string $ref = '';

    /** @var array<string,mixed> 场景数据，订阅方只读 */
    public array $input = [];

    /** @var array<int,array{by:string,why:string,action:int}> 投票记录 */
    public array $votes = [];

    private bool $locked = false;

    public function __construct(string $scene = '', array $input = [])
    {
        $this->scene = $scene;
        $this->input = $input;
    }

    /**
     * 加严。只升不降 —— 谁都不能把别人的判决改松。
     */
    public function escalate(int $action, string $by, string $why = ''): void
    {
        if ($this->locked || $action <= $this->action) {
            return;
        }
        $this->action = $action;
        if ($why !== '') {
            $this->reason = $why;
        }
        $this->votes[] = ['by' => $by, 'why' => $why, 'action' => $action];
    }

    /**
     * 强制放行并锁定。给「这是站长本人」「这是白名单」这类确定性结论用。
     */
    public function hardAllow(string $by, string $why = ''): void
    {
        $this->action = self::PASS;
        $this->locked = true;
        $this->votes[] = ['by' => $by, 'why' => $why, 'action' => self::PASS];
    }

    public function denied(): bool
    {
        return $this->action === self::DENY;
    }

    public function held(): bool
    {
        return $this->action === self::REVIEW;
    }

    public function limited(): bool
    {
        return $this->action === self::LIMIT;
    }

    /**
     * 拒绝时给用户的文案。订阅方没给就用核心的兜底话术。
     */
    public function message(string $fallback): string
    {
        $text = $this->reason !== '' ? $this->reason : $fallback;
        return $this->ref !== '' ? ($text . '（' . $this->ref . '）') : $text;
    }
}
