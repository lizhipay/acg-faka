<?php
declare(strict_types=1);

namespace App\Service;


use App\Model\Commodity;
use App\Model\PriceTemplate;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Shared::class)]
interface Shared
{

    /**
     * 连接店铺
     * @param string $domain
     * @param string $appId
     * @param string $appKey
     * @param int $type
     * @return array|null
     */
    public function connect(string $domain, string $appId, string $appKey, int $type = 0): ?array;


    /**
     * 获取店铺项目
     * @param \App\Model\Shared $shared
     * @return array|null
     */
    public function items(\App\Model\Shared $shared): ?array;

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @return array
     */
    public function item(\App\Model\Shared $shared, string $code): array;


    /**
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param int $cardId
     * @param int $num
     * @param string $race
     * @return bool
     */
    public function inventoryState(\App\Model\Shared $shared, Commodity $commodity, int $cardId, int $num, string $race): bool;

    /**
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param string $race
     * @return array
     */
    public function inventory(\App\Model\Shared $shared, Commodity $commodity, string $race = ""): array;


    /**
     * 远程购买卡密
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param string $contact
     * @param int $num
     * @param int $cardId
     * @param int $device
     * @param string $password
     * @param string $race
     * @param array|null $sku
     * @param string|null $widget
     * @param string $requestNo
     * @return string
     */
    public function trade(\App\Model\Shared $shared, Commodity $commodity, string $contact, int $num, int $cardId, int $device, string $password, string $race, ?array $sku, ?string $widget, string $requestNo): string;

    /**
     * 重新向上游取货：先按请求号查，查不到才用同一个请求号重新下单（#1172）
     * @throws \Kernel\Exception\JSONException
     */
    public function retrieve(\App\Model\Shared $shared, Commodity $commodity, \App\Model\Order $order): string;

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param array $map
     * @return array
     */
    public function draftCard(\App\Model\Shared $shared, string $code, array $map = []): array;

    /**
     * 上游预选卡的溢价；传入本单所选的种类与规格时一并交给上游确认这张卡正是该规格。
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param int $cardId
     * @param string|null $race
     * @param array|null $sku
     * @return array
     */
    public function getDraft(\App\Model\Shared $shared, string $code, int $cardId, ?string $race = null, ?array $sku = null): array;


    /**
     * @param Commodity $commodity
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param string|null $race
     * @param null|array $sku
     * @return string
     */
    public function getItemStock(Commodity $commodity, \App\Model\Shared $shared, string $code, ?string $race = null, ?array $sku = []): string;


    /**
     * @param Commodity $commodity
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param int $num
     * @param string|null $race
     * @param array|null $sku
     * @param int|null $cardId
     * @return string|float|int
     */
    public function getValuation(Commodity $commodity, \App\Model\Shared $shared, string $code, int $num, ?string $race = null, ?array $sku = [], ?int $cardId = 0): string|float|int;

    /**
     * @param string $config
     * @param string $price
     * @param string $userPrice
     * @param int $type
     * @param float $premium
     * @return array
     */
    public function AdjustmentPrice(string $config, string $price, string $userPrice, int $type, float $premium): array;


    /**
     * 按加价模板计算接入商品的整套价格，返回结构与 AdjustmentPrice 一致并多出 level_price
     *
     * @param PriceTemplate $template
     * @param string $config
     * @param string $price
     * @param string $userPrice
     * @param string $levelPrice
     * @param array{price:?string, category:array}|null $factory 按账号拿货价加价时的拿货价（factoryQuote() 的结果）；null = 按上游商品价
     * @return array
     */
    public function AdjustmentTemplate(PriceTemplate $template, string $config, string $price, string $userPrice, string $levelPrice = '', ?array $factory = null): array;

    /**
     * 这张模板、这家店铺该不该按账号拿货价加价。
     * $commodity 给了就顺带排除自动货源托管的商品：它的更新器只有列表价，两边口径不同价格会来回跳。
     */
    public function usesFactoryBase(\App\Model\Shared $shared, PriceTemplate $template, ?Commodity $commodity = null): bool;

    /**
     * 按账号拿货价加价要用的拿货价；查不到返回 null，调用方不许退回上游售价。
     *
     * @param array $remoteItem item() 的结果
     * @param array $config 上游配置（已解析）
     * @param string|null $cost remoteCost() 的结果
     * @return array{price:?string, category:array}|null
     */
    public function factoryQuote(\App\Model\Shared $shared, array $remoteItem, array $config, ?string $cost): ?array;

    /**
     * 这个实例上一次 syncRemoteItem() 是不是因为查不到拿货价而没有改价（后台同步日志据此如实显示）。
     */
    public function priceSkipped(): bool;


    /**
     * @param int $type
     * @param float $premium
     * @param string|int|float $amount
     * @return string
     */
    public function AdjustmentAmount(int $type, float $premium, string|int|float $amount): string;

    /**
     * 按商品自身的加价设置（固定/百分比/模板）折算一个附加金额，比如预选加价。
     * 直接调 AdjustmentAmount 的地方要换成它 —— 那个方法不认模板，
     * type=2 会掉进百分比分支并乘上为 0 的 premium，加价被静默抹平。
     *
     * @param \App\Model\Commodity|int $commodity
     */
    public function AdjustmentExtra(\App\Model\Commodity|int $commodity, string|int|float $amount): string;


    /**
     * @param Commodity|int $commodity
     * @return bool
     */
    public function syncRemoteItem(Commodity|int $commodity): bool;

    /**
     * 非种类商品在上游的拿货成本（已按汇率换算）。算不出来返回 null，调用方保持原值。
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity 需带 shared_code
     * @param array $remoteItem item() 的返回
     * @return string|null
     */
    public function remoteCost(\App\Model\Shared $shared, Commodity $commodity, array $remoteItem): ?string;

    /**
     * 上游发货留言的入库形态（#972）。上游没给（老版本上游、平台对接）或内容放不进本地列时返回 null，调用方保持本地留言不动。
     * @param array $remoteItem item() 的返回
     * @return string|null
     */
    public function remoteLeaveMessage(array $remoteItem): ?string;
}