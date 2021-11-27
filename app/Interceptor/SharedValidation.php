<?php
declare(strict_types=1);

namespace App\Interceptor;


use App\Consts\Shared;
use App\Model\User;
use App\Util\Context;
use App\Util\Str;
use JetBrains\PhpStorm\NoReturn;
use Kernel\Annotation\InterceptorInterface;
use Kernel\Exception\JSONException;

/**
 * Class SharedValidation
 * @package App\Interceptor
 */
class SharedValidation implements InterceptorInterface
{

    /**
     * @param int $type
     * @throws \Kernel\Exception\JSONException
     */
    #[NoReturn] public function handle(int $type): void
    {
        $appId = (int)$_POST['app_id'];
        $appKey = (string)$_POST['app_key'];
        $user = User::query()->find($appId);
        if (!$user) {
            throw new JSONException("商户ID不存在");
        }
        $signature = Str::generateSignature($_POST, $user->app_key);
        if ($_POST['sign'] != $signature) {
            throw new JSONException("密钥错误");
        }
        //保存会话
        Context::set(Shared::SESSION, $user);
    }
}