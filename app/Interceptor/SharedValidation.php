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
        if ($this->request->unsafePost("sign") != $signature) {
            throw new JSONException("密钥错误");
        }
        //保存会话
        Context::set(Shared::SESSION, $user);
    }
}