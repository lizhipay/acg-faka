<?php
declare(strict_types=1);

namespace App\Controller\Base\API;


use Kernel\Annotation\Inject;
use Kernel\Context\Interface\Request;

abstract class Shared extends \App\Controller\Base\Shared
{
    #[Inject]
    protected Request $request;

    /**
     * 生成JSON格式
     * @param int $code
     * @param string|null $message
     * @param array|null $data
     * @return array
     */
    public function json(int $code = 200, ?string $message = null, ?array $data = []): array
    {
        $json['code'] = $code;
        $message ? $json['msg'] = $message : null;
        $json['data'] = $data;
        return $json;
    }
}