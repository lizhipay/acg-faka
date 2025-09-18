<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Util\Tree;
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
        $dict = $this->dict->get(html_entity_decode((string)$request->get("dict", flags: Filter::NORMAL)), (string)$request->get("keywords"));


        foreach ($dict as &$item) {
            $item['name'] = strip_tags($item['name']);
        }


        if ($request->get("tree")) {
            $dict = Tree::generate($dict, "id", "pid", "children");
        }

        return $this->json(data: $dict);
    }
}