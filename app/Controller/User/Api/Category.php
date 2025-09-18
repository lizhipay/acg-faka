<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\Business;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use App\Util\Client;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class, Business::class], Interceptor::TYPE_API)]
class Category extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $this->request->post();
        $get = new Get(\App\Model\Category::class);
        $get->setWhere($map);
        $get->setOrderBy(...$this->query->getOrderBy($map, "sort", "asc"));
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("owner", $this->getUser()->id);
        });

        foreach ($data['list'] as &$item) {
            $item['share_url'] = Client::getUrl() . "/cat/{$item['id']}";
        }

        return $this->json(data: $data);
    }


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function save(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);
        $userId = $this->getUser()->id;

        if (!empty($map['id']) && !\App\Model\Category::query()->where("owner", $userId)->where("id", $map['id'])->exists()) {
            throw new JSONException("分类不存在");
        }

        if (isset($map['sort'])) {
            if ((int)$map['sort'] < 1000) {
                throw new JSONException("排序最低设置1000");
            }
            if ((int)$map['sort'] > 60000) {
                throw new JSONException("排序最高设置60000");
            }
        }

        $save = new Save(\App\Model\Category::class);
        $save->addForceMap("owner", $userId);
        $save->setMap($map);
        $save->enableCreateTime();
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     * @throws \Exception
     */
    public function del(): array
    {
        $id = (int)$_POST['id'];

        if ($id == 0) {
            throw new JSONException("请选择删除的分类");
        }

        $category = \App\Model\Category::query()->find($id);

        if (!$category) {
            throw new JSONException("分类不存在");
        }

        if ($category->owner != $this->getUser()->id) {
            throw new JSONException("该分类不属于你");
        }

        $category->delete();

        return $this->json(200, '（＾∀＾）移除成功');
    }
}