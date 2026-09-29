<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Business;
use App\Model\BusinessLevel;
use App\Model\Commodity;
use App\Model\UserCategory;
use App\Model\UserCommodity;
use App\Model\UserGroup;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Util\Decimal;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Promote extends User
{
    #[Inject]
    private \App\Service\Order $order;

    private bool $siteLoaded = false;

    private ?Business $business = null;

    private ?UserGroup $businessGroup = null;

    private ?BusinessLevel $businessLevel = null;

    private array $custom = [];

    private array $hiddenCategories = [];

    /**
     * 商品预计收益表(推广者视角,口径与下单结算一致):
     * 预计收益 = 游客成交价 - 按我的会员等级计算的拿货价;类别(race)单独成行,SKU 走明细接口。
     * @return array
     */
    public function data(): array
    {
        $this->assertEnabled();
        $user = $this->getUser();
        $group = UserGroup::get((float)$user->recharge);
        $page = max(1, (int)$this->request->post("page"));
        $limit = (int)$this->request->post("limit") ?: 10;
        $limit = min(100, max(1, $limit));
        $search = trim((string)$this->request->post("search-name"));

        $rows = [];
        foreach ($this->catalog()->orderBy("sort", "asc")->orderBy("id", "asc")->get() as $commodity) {
            $name = $this->displayName($commodity);
            if ($search !== "" && mb_stripos($name, $search) === false) {
                continue;
            }

            //解析配置,拿类别(race)与 SKU 组
            $races = [];
            $skuCount = 0;
            try {
                $parsed = clone $commodity;
                $this->order->parseConfig($parsed, $group);
                $races = array_keys((array)($parsed->config['category'] ?? []));
                $skuCount = count((array)($parsed->config['sku'] ?? []));
            } catch (\Throwable) {
                //配置无法解析时按无类别处理
            }

            foreach (($races ?: [null]) as $race) {
                $row = $this->buildRow($commodity, $name, $race, $group, (int)$user->id);
                if ($row) {
                    $row['sku_count'] = $skuCount;
                    $rows[] = $row;
                }
            }
        }

        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * $limit, $limit);

        return $this->json(data: ["total" => $total, "list" => $rows]);
    }

    /**
     * SKU 明细:每个选项的加价,以及对预计收益的影响(会员折扣按比例作用于加价后的整价)
     * @return array
     * @throws JSONException
     */
    public function sku(): array
    {
        $this->assertEnabled();
        $commodityId = (int)$this->request->post("commodityId");
        $race = (string)$this->request->post("race");
        $commodity = $this->catalog()->find($commodityId);
        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $user = $this->getUser();
        $group = UserGroup::get((float)$user->recharge);

        $parsed = clone $commodity;
        $this->order->parseConfig($parsed, $group);
        $skuGroups = (array)($parsed->config['sku'] ?? []);
        $race = $race !== "" ? $race : (array_keys((array)($parsed->config['category'] ?? []))[0] ?? null);

        $base = $this->buildRow($commodity, $this->displayName($commodity), $race, $group, (int)$user->id);
        $baseProfit = $base ? (float)$base['profit'] : 0.0;
        $custom = $this->customFor($commodity);

        $list = [];
        foreach ($skuGroups as $groupName => $options) {
            foreach ((array)$options as $optionName => $premium) {
                try {
                    $quote = $this->quote($commodity, $race, [$groupName => $optionName], $group, (int)$user->id);
                } catch (\Throwable) {
                    continue;
                }
                $premium = is_numeric($premium) ? (string)$premium : "0";
                $profit = round($quote['profit'], 2);
                $list[] = [
                    "group" => (string)$groupName,
                    "option" => (string)$optionName,
                    "premium" => sprintf("%.2f", (float)($custom ? $custom->markup($premium) : $premium)),
                    "guest_price" => sprintf("%.2f", $quote['guest']),
                    "my_price" => sprintf("%.2f", $quote['mine']),
                    "profit" => sprintf("%.2f", $profit),
                    "delta" => sprintf("%+.2f", round($profit - $baseProfit, 2)),
                ];
            }
        }

        return $this->json(data: ["race" => $race, "base_profit" => sprintf("%.2f", $baseProfit), "list" => $list]);
    }

    /**
     * 单行报价:游客价 / 我的拿货价 / 预计收益 / 收益率
     */
    private function buildRow(Commodity $commodity, string $name, ?string $race, ?UserGroup $group, int $userId): ?array
    {
        try {
            $quote = $this->quote($commodity, $race, [], $group, $userId);
        } catch (\Throwable) {
            return null;
        }
        $profit = round($quote['profit'], 2);
        return [
            "id" => $commodity->id,
            "name" => $name,
            "cover" => $commodity->cover,
            "race" => $race,
            "guest_price" => sprintf("%.2f", $quote['guest']),
            "my_price" => sprintf("%.2f", $quote['mine']),
            "profit" => sprintf("%.2f", $profit),
            "rate" => $quote['guest'] > 0 ? round($profit / $quote['guest'] * 100, 1) : 0,
        ];
    }

    private function quote(Commodity $commodity, ?string $race, array $sku, ?UserGroup $group, int $userId): array
    {
        $this->loadSite();
        $custom = $this->customFor($commodity);
        $guest = $this->order->valuation($commodity, 1, $race, $sku, null, null, null);
        $mine = $this->order->valuation($commodity, 1, $race, $sku, null, null, $group);
        $rebate = "0";

        if ($this->business) {
            if ($commodity->owner === $this->business->user_id) {
                $cost = $this->businessLevel ? $this->businessLevel->cost : 0;
                $rebate = (new Decimal($guest))->sub((new Decimal($guest))->mul($cost)->getAmount())->getAmount();
            } else {
                $custom && $guest = $custom->markup($guest);
                $rebate = (new Decimal($guest))->sub($this->order->valuation($commodity, 1, $race, $sku, null, null, $this->businessGroup))->getAmount();
            }
        }

        if ((float)$rebate > 0) {
            $custom && $mine = $custom->markup($mine);
            $divide = (new Decimal($guest))->sub($mine)->getAmount();
            $profit = (float)$rebate > (float)$divide ? $divide : "0";
        } else {
            $profit = (new Decimal($guest))->sub($mine)->getAmount();
        }

        if ($commodity->owner === $userId || ($this->business && $this->business->user_id === $userId)) {
            $profit = "0";
        }

        return ["guest" => (float)$guest, "mine" => (float)$mine, "profit" => (float)$profit];
    }

    private function assertEnabled(): void
    {
        if (!\App\Util\Promotion::enabled()) {
            throw new JSONException("推广功能已关闭");
        }
    }

    private function catalog(): Builder
    {
        \App\Util\Schema::ensureCommodityControl();
        $this->loadSite();
        $query = Commodity::query()->where("status", 1)->where("hide", 0);

        if (!$this->business) {
            return $query->where("owner", 0);
        }

        $ownerId = $this->business->user_id;
        if ($this->business->master_display === 0) {
            return $query->where("owner", $ownerId);
        }

        $hidden = [];
        foreach ($this->custom as $commodityId => $custom) {
            if ($custom->status === 0) {
                $hidden[] = $commodityId;
            }
        }

        return $query
            ->whereNotIn("id", $hidden)
            ->whereNotIn("category_id", $this->hiddenCategories)
            ->where(fn(Builder $builder) => $builder
                ->where(fn(Builder $master) => $master->where("owner", 0)->where("substation_disable", 0))
                ->orWhere("owner", $ownerId));
    }

    private function displayName(Commodity $commodity): string
    {
        $this->loadSite();
        $custom = $this->business ? ($this->custom[$commodity->id] ?? null) : null;
        return $custom && $custom->name ? (string)$custom->name : (string)$commodity->name;
    }

    private function customFor(Commodity $commodity): ?UserCommodity
    {
        $this->loadSite();
        $custom = $this->business ? ($this->custom[$commodity->id] ?? null) : null;
        return $custom && $custom->premium > 0 ? $custom : null;
    }

    private function loadSite(): void
    {
        if ($this->siteLoaded) {
            return;
        }
        $this->siteLoaded = true;
        $this->business = Business::get();
        if (!$this->business) {
            return;
        }

        $owner = \App\Model\User::query()->find($this->business->user_id);
        if ($owner) {
            $this->businessGroup = UserGroup::get((float)$owner->recharge);
            $this->businessLevel = BusinessLevel::query()->find($owner->business_level);
        }
        $this->custom = UserCommodity::query()->where("user_id", $this->business->user_id)->get()->keyBy("commodity_id")->all();
        $this->hiddenCategories = UserCategory::query()->where("user_id", $this->business->user_id)->where("status", 0)->pluck("category_id")->all();
    }
}
