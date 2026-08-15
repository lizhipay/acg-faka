<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
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
     * @throws JSONException
     */
    public function get(Request $request): array
    {
        $dictName = html_entity_decode((string)$request->get("dict", flags: Filter::NORMAL));

        //前端调用永远携带 dict 参数；到这里为空基本都是服务器环境把 URL 参数吞了
        //（伪静态规则缺 $args、WAF/CDN 清洗 query 等），给出可自查的错误而不是静默空列表（issue #794）
        if (trim($dictName) === '') {
            throw new JSONException("字典参数缺失：服务器未收到 URL 参数，请检查伪静态规则(try_files 是否带 \$args)、WAF 或 CDN 是否丢弃了链接参数");
        }

        $dict = $this->dict->get($dictName, (string)$request->get("keywords"));


        foreach ($dict as &$item) {
            $item['name'] = strip_tags($item['name']);
        }


        if ($request->get("tree")) {
            $dict = Tree::generate($dict, "id", "pid", "children");
        }

        return $this->json(data: $dict);
    }
}