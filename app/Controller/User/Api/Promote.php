<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Commodity;
use App\Model\UserGroup;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Promote extends User
{
    #[Inject]
    private \App\Service\Order $order;

    /**
     * 商品预计收益表(推广者视角,口径与下单结算一致):
     * 预计收益 = 游客成交价 - 按我的会员等级计算的拿货价;类别(race)单独成行,SKU 走明细接口。
     * @return array
     */
    public function data(): array
    {
        $user = $this->getUser();
        $group = UserGroup::get((float)$user->recharge);
        $page = max(1, (int)$this->request->post("page"));
        $limit = (int)$this->request->post("limit") ?: 10;
        $limit = min(100, max(1, $limit));
        $search = trim((string)$this->request->post("search-name"));

        $query = Commodity::query()
            ->where("owner", 0)
            ->where("status", 1)
            ->where("hide", 0)
            ->orderBy("sort", "asc")
            ->orderBy("id", "asc");
        if ($search !== "") {
            $query->where("name", "like", "%{$search}%");
        }

        $rows = [];
        foreach ($query->get() as $commodity) {
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
                $row = $this->buildRow($commodity, $race, $group);
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
        $commodityId = (int)$this->request->post("commodityId");
        $race = (string)$this->request->post("race");
        $commodity = Commodity::query()->where("owner", 0)->find($commodityId);
        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $user = $this->getUser();
        $group = UserGroup::get((float)$user->recharge);

        $parsed = clone $commodity;
        $this->order->parseConfig($parsed, $group);
        $skuGroups = (array)($parsed->config['sku'] ?? []);
        $race = $race !== "" ? $race : (array_keys((array)($parsed->config['category'] ?? []))[0] ?? null);

        $base = $this->buildRow($commodity, $race, $group);
        $baseProfit = $base ? (float)$base['profit'] : 0.0;

        $list = [];
        foreach ($skuGroups as $groupName => $options) {
            foreach ((array)$options as $optionName => $premium) {
                try {
                    $guest = (float)$this->order->valuation($commodity, 1, $race, [$groupName => $optionName], null, null, null);
                    $mine = (float)$this->order->valuation($commodity, 1, $race, [$groupName => $optionName], null, null, $group);
                } catch (\Throwable) {
                    continue;
                }
                $profit = round($guest - $mine, 2);
                $list[] = [
                    "group" => (string)$groupName,
                    "option" => (string)$optionName,
                    "premium" => sprintf("%.2f", (float)$premium),
                    "guest_price" => sprintf("%.2f", $guest),
                    "my_price" => sprintf("%.2f", $mine),
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
    private function buildRow(Commodity $commodity, ?string $race, ?UserGroup $group): ?array
    {
        try {
            $guest = (float)$this->order->valuation($commodity, 1, $race, [], null, null, null);
            $mine = (float)$this->order->valuation($commodity, 1, $race, [], null, null, $group);
        } catch (\Throwable) {
            return null;
        }
        $profit = round($guest - $mine, 2);
        return [
            "id" => $commodity->id,
            "name" => $commodity->name,
            "cover" => $commodity->cover,
            "race" => $race,
            "guest_price" => sprintf("%.2f", $guest),
            "my_price" => sprintf("%.2f", $mine),
            "profit" => sprintf("%.2f", $profit),
            "rate" => $guest > 0 ? round($profit / $guest * 100, 1) : 0,
        ];
    }
}
