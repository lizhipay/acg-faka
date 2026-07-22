<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Query;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class, \App\Interceptor\Business::class], Interceptor::TYPE_API)]
class CommodityOrder extends User
{
    private const MAX_PAGE_SIZE = 100;
    private const MAX_FILTER_LENGTH = 128;

    private const FILTER_WHITELIST = [
        'equal-trade_no',
        'equal-commodity_id',
        'search-secret',
        'equal-contact',
        'equal-delivery_status',
        'equal-create_device',
        'equal-create_ip',
        'equal-owner',
        'betweenStart-create_time',
        'betweenEnd-create_time',
        'equal-status',
    ];

    private const SENSITIVE_FILTERS = [
        'equal-trade_no',
        'search-secret',
        'equal-contact',
        'equal-create_ip',
    ];

    private const LIST_COLUMNS = [
        'id',
        'owner',
        'user_id',
        'substation_user_id',
        'trade_no',
        'amount',
        'commodity_id',
        'card_id',
        'card_num',
        'pay_id',
        'create_time',
        'create_ip',
        'create_device',
        'pay_time',
        'status',
        'secret',
        'password',
        'contact',
        'delivery_status',
        'coupon_id',
        'widget',
        'race',
        'sku',
    ];

    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $post = $this->request->post();
        $post = is_array($post) ? $post : [];

        $page = max(1, (int)($post['page'] ?? 1));
        $limit = (int)($post['limit'] ?? 15);
        $limit = $limit > 0 ? min($limit, self::MAX_PAGE_SIZE) : 15;

        $map = [];
        $sensitiveFilters = [];
        foreach ($post as $key => $value) {
            if (!is_string($key) || !is_scalar($value) || $value === '') {
                continue;
            }

            if (strlen((string)$value) > self::MAX_FILTER_LENGTH) {
                throw new JSONException('查询条件过长');
            }

            $canonicalKey = explode('·', urldecode($key), 2)[0];
            if (!in_array($canonicalKey, self::FILTER_WHITELIST, true)) {
                continue;
            }

            if (in_array($canonicalKey, self::SENSITIVE_FILTERS, true)) {
                $sensitiveFilters[$canonicalKey] = (string)$value;
                continue;
            }

            $map[$canonicalKey] = $value;
        }

        $userId = (int)$this->getUser()->id;
        $get = new Get(\App\Model\Order::class);
        $get->setPaginate($page, $limit);
        $get->setColumn(...self::LIST_COLUMNS);
        $get->setWhere($map);
        $data = $this->query->get($get, function (Builder $builder) use ($userId, $sensitiveFilters) {
            if ($sensitiveFilters === []) {
                $builder->visibleToMerchant($userId);
            } else {
                // 完整订单号、联系方式、IP、交付内容只能用于检索供货方自己的订单，避免分站侧信道探测。
                $builder->suppliedByMerchant($userId);
                foreach ($sensitiveFilters as $key => $value) {
                    if ($key === 'equal-trade_no') {
                        $builder->where('trade_no', $value);
                    } elseif ($key === 'search-secret') {
                        $builder->where('secret', 'like', '%' . $value . '%');
                    } elseif ($key === 'equal-contact') {
                        $builder->where('contact', $value);
                    } elseif ($key === 'equal-create_ip') {
                        $builder->where('create_ip', $value);
                    }
                }
            }

            return $builder->with([
                'coupon' => function (Relation $relation) {
                    $relation->select(["id", "code"]);
                },
                'owner' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar"]);
                },
                'commodity' => function (Relation $relation) {
                    $relation->select(["id", "name", "cover", "delivery_way", "contact_type"]);
                },
                'pay' => function (Relation $relation) {
                    $relation->select(["id", "name", "icon"]);
                },
                'card' => function (Relation $relation) {
                    $relation->select(["id", "secret"]);
                },
            ]);
        });

        foreach ($data['list'] as &$order) {
            $isSupplier = (int)($order['user_id'] ?? 0) === $userId;

            $order['merchant_permissions'] = [
                'view_secret' => $isSupplier,
                'view_purchase_info' => $isSupplier,
                'delivery' => $isSupplier,
            ];

            if (!$isSupplier) {
                // 订单号由时间和短随机数组成，保留头尾仍可被推算；分站只返回不可用于公共查单的本地记录号。
                $order['trade_no'] = '分站记录 #' . (int)($order['id'] ?? 0);
                $order['owner'] = ['username' => '受保护'];
                $order['create_time'] = substr((string)($order['create_time'] ?? ''), 0, 10);
                $order['pay_time'] = empty($order['pay_time'])
                    ? null
                    : substr((string)$order['pay_time'], 0, 10);

                unset(
                    $order['secret'],
                    $order['password'],
                    $order['contact'],
                    $order['create_ip'],
                    $order['create_device'],
                    $order['widget'],
                    $order['card'],
                    $order['coupon']
                );
            }

            unset(
                $order['user_id'],
                $order['substation_user_id'],
                $order['card_id'],
                $order['coupon_id'],
                $order['pay_id']
            );
        }
        unset($order);

        return $this->json(data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function delivery(): array
    {
        $id = (int)($_POST['id'] ?? 0);
        $message = (string)($_POST['secret'] ?? '');

        if (trim($message) === '') {
            throw new JSONException("发货内容不能为空");
        }

        $order = \App\Model\Order::query()
            ->suppliedByMerchant((int)$this->getUser()->id)
            ->with('commodity:id,delivery_way')
            ->find($id);

        if (!$order) {
            throw new JSONException("要发货的订单不存在");
        }

        if ($order->status == 0) {
            throw new JSONException("该订单还未支付");
        }

        if (!$order->commodity || (int)$order->commodity->delivery_way !== 1) {
            throw new JSONException("该订单不是手动发货订单");
        }

        $order->secret = $message;
        $order->delivery_status = 1;
        $order->save();

        return $this->json(200, "发货成功");
    }
}
