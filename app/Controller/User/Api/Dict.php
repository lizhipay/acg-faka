<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Interceptor\Business;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;
use Kernel\Util\Tree;

/**
 * Class Dict
 * @package App\Controller\Admin\Api
 */
#[Interceptor([Waf::class, UserSession::class, Business::class], Interceptor::TYPE_API)]
class Dict extends User
{


    /**
     * @param bool $tree
     * @return array
     */
    public function category(bool $tree = false): array
    {
        $data = \App\Model\Category::query()->where("owner", $this->getUser()->id)->get(["id", "name", "pid"])->toArray();
        foreach ($data as &$item) {
            $item['name'] = strip_tags($item['name']);
        }
        if ($tree) {
            $data = Tree::generate($data, "id", "pid", "children");
        }
        return $this->json(data: $data);
    }


    /**
     * @return array
     */
    public function commodityLocal(): array
    {
        $data = \App\Model\Commodity::query()
            ->where("owner", $this->getUser()->id)
            ->where("delivery_way", 0)
            ->get(["id", "name"])->toArray();

        foreach ($data as &$item) {
            $item['name'] = strip_tags($item['name']);
        }

        return $this->json(data: $data);
    }

    /**
     * @return array
     */
    public function commodityAll(): array
    {
        $data = \App\Model\Commodity::query()
            ->where("owner", $this->getUser()->id)
            ->get(["id", "name"])->toArray();

        foreach ($data as &$item) {
            $item['name'] = strip_tags($item['name']);
        }

        return $this->json(data: $data);
    }
}