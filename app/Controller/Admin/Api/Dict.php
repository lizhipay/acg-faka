<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Waf\Filter;

/**
 * Class Dict
 * @package App\Controller\Admin\Api
 */
#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Dict extends Manage
{

    #[Inject]
    private \App\Service\Dict $dict;

    /**
     * @param Request $request
     * @return array
     */
    public function get(Request $request): array
    {
        $a = htmlspecialchars_decode((string)$request->post("dict", flags: Filter::NORMAL), ENT_QUOTES);
        @$dict = $this->dict->get($a, (string)$_POST['keywords']);
        return $this->json(200, null, (array)$dict);
    }
}