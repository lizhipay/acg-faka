<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;

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
     * @return array
     */
    public function get(): array
    {
        @$dict = $this->dict->get((string)$_POST['dict'], (string)$_POST['keywords']);
        return $this->json(200, null, (array)$dict);
    }
}