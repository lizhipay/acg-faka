<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Consts\Shared;
use App\Model\User;
use App\Util\Context;
use App\Util\Str;
use Kernel\Annotation\Inject;
use Kernel\Annotation\InterceptorInterface;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;

/**
 * Class SharedValidation
 * @package App\Interceptor
 */
class SharedValidation implements InterceptorInterface
{
    #[Inject]
    private Request $request;

    /**
     * @param int $type
     * @throws JSONException
     */
    public function handle(int $type): void
    {
        $appId = $this->request->unsafePost("app_id");
        $user = User::query()->find($appId);
        if (!$user) {
            throw new JSONException("商户ID不存在");
        }
        $signature = Str::generateSignature($this->request->unsafePost(), $user->app_key);
        $sign = $this->request->unsafePost("sign");
        //强类型 + 定时安全比较：hash_equals 只吃字符串，先挡掉数组/null；
        //避免松散 != 的 magic hash("0e…" 全数字被当 0 相等) 与逐字节短路的时序侧信道。
        if (!is_string($sign) || !hash_equals($signature, $sign)) {
            throw new JSONException("密钥错误");
        }
        //保存会话
        Context::set(Shared::SESSION, $user);
    }
}