<?php
declare(strict_types=1);

namespace App\Controller\Base\API;


use App\Model\BusinessLevel;
use Kernel\Exception\JSONException;

abstract class User extends \App\Controller\Base\User
{
    /**
     * 生成JSON格式
     * @param int $code
     * @param string|null $message
     * @param array|null $data
     * @return array
     */
    public function json(int $code, ?string $message = null, ?array $data = []): array
    {
        $json['code'] = $code;
        $message ? $json['msg'] = $message : null;
        $json['data'] = $data;
        return $json;
    }


    /**
     * @throws \Kernel\Exception\JSONException
     */
    protected function businessValidation(): BusinessLevel
    {
        $level = $this->getUser()->businessLevel;
        if (!$level) {
            throw new JSONException("无权限");
        }
        return $level;
    }
}