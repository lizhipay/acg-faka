<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Model\Commodity;
use App\Model\PriceTemplate;
use App\Util\Http;
use App\Util\Ini;
use App\Util\SharedCurrency;
use App\Util\Str;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Annotation\Inject;
use Kernel\Container\Di;
use Kernel\Exception\JSONException;
use Kernel\Util\Decimal;

class Shared implements \App\Service\Shared
{
    #[Inject]
    private Client $http;

    public function mcyRequest(string $url, string $appId, string $appKey, array $data = []): array
    {
        try {
            $response = Http::make()->post($url, [
                "headers" => [
                    "Api-Id" => $appId,
                    "Api-Signature" => Str::generateSignature($data, $appKey)
                ],
                "form_params" => $data,
                "timeout" => 30,

                'allow_redirects' => false,
            ]);

            $contents = json_decode($response->getBody()->getContents() ?: "", true) ?: [];

            if (!isset($contents['code'])) {
                throw new JSONException("连接失败#1");
            }

            if ($contents['code'] != 200) {
                throw new JSONException(strip_tags($contents['msg']) ?? "连接失败#2");
            }

            return $contents['data'] ?? [];
        } catch (\Throwable $e) {
            throw new JSONException("连接失败#0");
        }
    }

    private function post(string $url, string $appId, string $appKey, array $data = []): array
    {
        $data = array_merge($data, ["app_id" => $appId, "app_key" => $appKey]);
        $data['sign'] = Str::generateSignature($data, $appKey);
        try {
            $response = Http::make()->post($url, [
                'form_params' => $data,
                'timeout' => 30,

                'allow_redirects' => false,
            ]);
        } catch (\Exception $e) {
            throw new JSONException("连接失败, 疑似被对方防火墙拦截");
        }
        $contents = $response->getBody()->getContents();

        $result = json_decode($contents, true);
        if ($result['code'] != 200) {
            throw new JSONException(strip_tags((string)$result['msg']) ?: "连接失败");
        }
        return (array)$result['data'];
    }

    public function connect(string $domain, string $appId, string $appKey, int $type = 0): ?array
    {
        if ($type == 1) {
            $data = $this->mcyRequest($domain . "/plugin/open-api/connect", $appId, $appKey);
            return ["shopName" => $data['username'], "balance" => $data['balance']];
        } elseif ($type == 2) {
            return $this->post($domain . "/plugin/SharedStock/api/connect", $appId, $appKey);
        }
        return $this->post($domain . "/shared/authentication/connect", $appId, $appKey);
    }

    private function createV4Item(array $item): array
    {
        $arr = [
            'id' => $item['id'],
            'name' => $item['name'],
            'description' => $item['introduce'],
            'price' => $item['sku'][0]['stock_price'],
            'user_price' => $item['sku'][0]['stock_price'],
            'cover' => $item['picture_url'],
            'factory_price' => $item['sku'][0]['stock_price'],
            'delivery_way' => 0,
            'contact_type' => 0,
            'password_status' => 0,
            'sort' => 0,
            'code' => $item['id'],
            'seckill_status' => 0,
            'draft_status' => 0,
            'inventory_hidden' => 0,
            'only_user' => 0,
            'purchase_count' => 0,
            'minimum' => 0,
            'maximum' => 0
        ];

        $widget = json_decode($item['widget'] ?: "", true) ?: [];

        $wid = [];

        if (!empty($widget)) {
            foreach ($widget as $w) {
                $wid[] = [
                    'cn' => $w['title'],
                    'name' => $w['name'],
                    'placeholder' => $w['placeholder'],
                    'type' => $w['type'],
                    'regex' => $w['regex'],
                    'error' => $w['error'],
                    'dict' => str_replace(PHP_EOL, ',', $w['data'] ?? "")
                ];
            }
        }

        $arr['widget'] = json_encode($wid);

        $config = [];
        $arr['stock'] = 0;

        foreach ($item['sku'] as $sku) {
            $config['category'][$sku['name']] = $sku['stock_price'];
            $config['shared_mapping'][$sku['name']] = $sku['id'];
            if (is_numeric($sku['stock'])) {
                $arr['stock'] += $sku['stock'];
            }
        }
        $arr['stock'] == 0 && $arr['stock'] = 10000000;
        $arr['config'] = Ini::toConfig($config);

        return $arr;
    }

    public function items(\App\Model\Shared $shared): ?array
    {
        $factor = SharedCurrency::factor($shared);

        if ($shared->type == 1) {
            $data = $this->mcyRequest($shared->domain . "/plugin/open-api/items", $shared->app_id, $shared->app_key);

            $category = [];

            foreach ($data as $item) {
                $cateName = $item['category']['name'];
                if (!isset($category[$cateName])) {
                    $category[$cateName] = [
                        "name" => $cateName,
                        "id" => 0
                    ];
                }
                $category[$cateName]['children'][] = $this->createV4Item($item);
            }

            return SharedCurrency::tree(array_values($category), $factor);
        } elseif ($shared->type == 2) {
            return SharedCurrency::tree((array)$this->post($shared->domain . "/plugin/SharedStock/api/items", $shared->app_id, $shared->app_key), $factor);
        }

        return SharedCurrency::tree((array)$this->post($shared->domain . "/shared/commodity/items", $shared->app_id, $shared->app_key), $factor);
    }

    public function item(\App\Model\Shared $shared, string $code): array
    {
        $factor = SharedCurrency::factor($shared);
        if ($shared->type == 1) {
            $data = $this->mcyRequest($shared->domain . "/plugin/open-api/item", $shared->app_id, $shared->app_key, [
                "id" => $code
            ]);
            $a = $this->createV4Item($data);

            if (!is_array($a['config'])) {
                $a['config'] = Ini::toArray((string)$a['config']);
            }

            return SharedCurrency::item($a, $factor);
        } elseif ($shared->type == 2) {
            $a = $this->post($shared->domain . "/plugin/SharedStock/api/item", $shared->app_id, $shared->app_key, [
                "code" => $code
            ]);

            if (!isset($a[0]['children'][0])) {
                throw new JSONException("商品不存在#{$code}");
            }

            $b = $a[0]['children'][0];

            if (!is_array($b['config'])) {
                $b['config'] = Ini::toArray((string)$b['config']);
            }

            return SharedCurrency::item($b, $factor);
        }
        $a = $this->post($shared->domain . "/shared/commodity/item", $shared->app_id, $shared->app_key, [
            "code" => $code
        ]);

        if (isset($a['config']) && !is_array($a['config'])) {
            $a['config'] = Ini::toArray((string)$a['config']);
        }

        return SharedCurrency::item($a, $factor);
    }

    public function inventoryState(\App\Model\Shared $shared, Commodity $commodity, int $cardId, int $num, string $race): bool
    {
        if ($shared->type == 1) {
            $config = Ini::toArray($commodity->config);
            $data = $this->mcyRequest($shared->domain . "/plugin/open-api/sku/state", $shared->app_id, $shared->app_key, [
                'sku_id' => (int)$config['shared_mapping'][$race],
                'quantity' => $num
            ]);
            return (bool)$data['state'];
        }

        $this->post($shared->domain . "/shared/commodity/inventoryState", $shared->app_id, $shared->app_key, [
            "shared_code" => $commodity->shared_code,
            "card_id" => $cardId,
            "num" => $num,
            "race" => $race
        ]);

        return true;
    }

    public function trade(\App\Model\Shared $shared, Commodity $commodity, string $contact, int $num, int $cardId, int $device, string $password, string $race, ?array $sku, ?string $widget, string $requestNo): string
    {
        $wg = (array)json_decode((string)$widget, true);

        if ($shared->type == 1) {
            $config = Ini::toArray($commodity->config);

            $post = [
                'sku_id' => (int)$config['shared_mapping'][$race],
                'quantity' => $num,
                'trade_no' => substr(md5($requestNo), 0, 24)
            ];

            foreach ($wg as $key => $item) {
                $post[$key] = $item['value'];
            }

            $data = $this->mcyRequest($shared->domain . "/plugin/open-api/trade", $shared->app_id, $shared->app_key, $post);
            return $data['contents'] ?? "此商品没有发货信息或正在发货中";
        }

        $post = [
            "shared_code" => $commodity->shared_code,
            "contact" => $contact,
            "num" => $num,
            "card_id" => $cardId,
            "device" => $device,
            "password" => $password,
            "race" => $race,
            "request_no" => $requestNo,
            "sku" => $sku ?: []
        ];

        foreach ($wg as $key => $item) {
            $post[$key] = $item['value'];
        }

        $trade = $this->post($shared->domain . "/shared/commodity/trade", $shared->app_id, $shared->app_key, $post);

        $shop = Di::inst()->make(\App\Service\Shop::class);
        $shop->updateSharedStock($commodity->id, $race, $sku);

        return (string)$trade['secret'];
    }

    public function draftCard(\App\Model\Shared $shared, string $code, array $map = []): array
    {
        $card = $this->post($shared->domain . "/shared/commodity/draftCard", $shared->app_id, $shared->app_key, array_merge([
            "code" => $code
        ], $map));

        return SharedCurrency::draftPremiums((array)$card, SharedCurrency::factor($shared));
    }

    public function getDraft(\App\Model\Shared $shared, string $code, int $cardId): array
    {
        $draft = $this->post($shared->domain . "/shared/commodity/draft", $shared->app_id, $shared->app_key, [
            "code" => $code,
            "card_id" => $cardId
        ]);
        return SharedCurrency::draftPremiums((array)$draft, SharedCurrency::factor($shared));
    }

    public function inventory(\App\Model\Shared $shared, Commodity $commodity, string $race = ""): array
    {
        $factor = SharedCurrency::factor($shared);
        if ($shared->type == 1) {
            $config = Ini::toArray($commodity->config);

            $item = $this->mcyRequest($shared->domain . "/plugin/open-api/item", $shared->app_id, $shared->app_key, [
                'id' => (int)$commodity->shared_code
            ]);

            $v4Item = $this->createV4Item($item);

            $result = [
                'delivery_way' => 0,
                'draft_status' => 0,
                'price' => $v4Item['price'],
                'user_price' => $v4Item['user_price'],
                'config' => $v4Item['config'],
                'factory_price' => $v4Item['factory_price'],
                'is_category' => true,
                'count' => 0
            ];

            if (empty($race)) {
                foreach ($config['shared_mapping'] as $skuId) {
                    $data = $this->mcyRequest($shared->domain . "/plugin/open-api/sku/stock", $shared->app_id, $shared->app_key, [
                        'sku_id' => (int)$skuId,
                    ]);
                    $result['count'] += (int)$data['stock'];
                }
            } else {
                $data = $this->mcyRequest($shared->domain . "/plugin/open-api/sku/stock", $shared->app_id, $shared->app_key, [
                    'sku_id' => (int)$config['shared_mapping'][$race],
                ]);
                if (is_numeric($data['stock'])) {
                    $result['count'] = (int)$data['stock'];
                } else {
                    $result['count'] = 999;
                }
            }

            return SharedCurrency::item($result, $factor);
        }

        $inventory = $this->post($shared->domain . "/shared/commodity/inventory", $shared->app_id, $shared->app_key, [
            "sharedCode" => $commodity->shared_code,
            "race" => $race
        ]);

        return SharedCurrency::item((array)$inventory, $factor);
    }

    public function getItemStock(Commodity $commodity, \App\Model\Shared $shared, string $code, ?string $race = null, ?array $sku = []): string
    {
        if ($shared->type == 1) {
            $result = $this->inventory($shared, $commodity, $race);
            return isset($result['count']) ? (string)$result['count'] : "0";
        } elseif ($shared->type == 2) {
            $stock = $this->post($shared->domain . "/plugin/SharedStock/api/stock", $shared->app_id, $shared->app_key, [
                "code" => $code,
                "race" => $race
            ]);
            return $stock['stock'] ?? "0";
        }

        $stock = $this->post($shared->domain . "/shared/commodity/stock", $shared->app_id, $shared->app_key, [
            "code" => $code,
            "race" => $race,
            "sku" => $sku
        ]);
        return $stock['stock'] ?? "0";
    }

    public function getValuation(Commodity $commodity, \App\Model\Shared $shared, string $code, int $num, ?string $race = null, ?array $sku = [], ?int $cardId = 0): string|float|int
    {
        $factor = SharedCurrency::factor($shared);
        try {
            $config = is_array($commodity->config) ? $commodity->config : Ini::toArray($commodity->config);
            if ($shared->type == 1) {
                $data = $this->mcyRequest($shared->domain . "/plugin/open-api/amount", $shared->app_id, $shared->app_key, [
                    'sku_id' => (int)$config['shared_mapping'][$race],
                    "quantity" => $num
                ]);
                return SharedCurrency::amount($data['amount'] ?? 0, $factor);
            } elseif ($shared->type == 2) {
                $data = $this->post($shared->domain . "/plugin/SharedStock/api/valuation", $shared->app_id, $shared->app_key, [
                    'code' => $code,
                    'num' => $num,
                    'race' => $race,
                    'card_id' => $cardId
                ]);
                return SharedCurrency::amount($data['price'] ?? 0, $factor);
            }

            $data = $this->post($shared->domain . "/shared/commodity/valuation", $shared->app_id, $shared->app_key, [
                'code' => $code,
                'num' => $num,
                'race' => $race,
                'sku' => $sku,
                'card_id' => $cardId
            ]);

            $remoteCurrency = strtoupper(trim((string)($data['currency_code'] ?? '')));
            $configured = strtoupper(trim((string)($shared->currency ?? ''))) ?: \App\Util\Currency::DEFAULT_CODE;
            if ($remoteCurrency !== '' && $remoteCurrency !== $configured) {
                \Kernel\Util\Log::inst()->error("店铺对接[{$shared->domain}]实际货币为 {$remoteCurrency}，但店铺档案配置的对方货币是 {$configured}，换算可能用错汇率，请到「店铺共享」修正");
            }

            return SharedCurrency::amount($data['price'] ?? 0, $factor);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function AdjustmentPrice(string $config, string $price, string $userPrice, int $type, float $premium): array
    {
        $this->assertPlainPremiumType($type);
        $_config = Ini::toArray($config);

        if (array_key_exists("category", $_config) && is_array($_config['category'])) {
            foreach ($_config['category'] as &$_price) {
                $_tmp = new Decimal($_price, 2);
                $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
            }
        }

        if (array_key_exists("sku", $_config) && is_array($_config['sku'])) {
            foreach ($_config['sku'] as &$sku) {
                foreach ($sku as &$_price) {
                    if ($_price > 0) {
                        $_tmp = new Decimal($_price, 2);
                        $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
                    }
                }
            }
        }

        if (array_key_exists("wholesale", $_config) && is_array($_config['wholesale'])) {
            foreach ($_config['wholesale'] as &$_price) {
                $_tmp = new Decimal($_price, 2);
                $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
            }
        }

        if (array_key_exists("category_wholesale", $_config) && is_array($_config['category_wholesale'])) {
            foreach ($_config['category_wholesale'] as &$categoryWholesale) {
                foreach ($categoryWholesale as &$_price) {
                    $_tmp = new Decimal($_price, 2);
                    $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
                }
            }
        }

        $_tmp = new Decimal($price, 2);
        $price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($price)->getAmount())->getAmount();

        $_tmp = new Decimal($userPrice, 2);
        $userPrice = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($userPrice)->getAmount())->getAmount();

        return ["config" => $_config, "price" => $price, "user_price" => $userPrice];
    }

    public function AdjustmentTemplate(PriceTemplate $template, string $config, string $price, string $userPrice, string $levelPrice = ''): array
    {
        $result = $template->forShared($config, $price, $userPrice, $levelPrice);
        return [
            "config" => Ini::toArray($result['config']),
            "price" => $result['price'],
            "user_price" => $result['user_price'],
            "level_price" => $result['level_price'],
        ];
    }

    public function AdjustmentAmount(int $type, float $premium, float|int|string $amount): string
    {
        $this->assertPlainPremiumType($type);
        $_tmp = new Decimal($amount, 2);
        return $type == PriceTemplate::TYPE_FIXED ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($amount)->getAmount())->getAmount();
    }

    private function assertPlainPremiumType(int $type): void
    {
        if ($type === PriceTemplate::TYPE_FIXED || $type === PriceTemplate::TYPE_PERCENT) {
            return;
        }
        if ($type === PriceTemplate::SHARED_PREMIUM_TYPE) {
            throw new JSONException('加价模板不能用固定/百分比的算法计算，请改用 AdjustmentTemplate 或 AdjustmentExtra');
        }
        throw new JSONException("未知的加价模式({$type})");
    }

    private function resolvePremiumTemplate(Commodity $commodity): ?PriceTemplate
    {
        if ((int)$commodity->shared_premium_type !== PriceTemplate::SHARED_PREMIUM_TYPE) {
            return null;
        }
        $templateId = (int)($commodity->shared_premium_template ?? 0);
        return $templateId > 0 ? PriceTemplate::query()->find($templateId) : null;
    }

    public function AdjustmentExtra(Commodity|int $commodity, string|int|float $amount): string
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }
        if (!$commodity) {
            return (string)$amount;
        }

        $template = $this->resolvePremiumTemplate($commodity);
        if ($template) {
            return $template->markupExtra((float)$amount);
        }

        if ((int)$commodity->shared_premium_type === PriceTemplate::SHARED_PREMIUM_TYPE) {
            \Kernel\Util\Log::inst()->error("商品[{$commodity->id}]的加价模板不可用，附加金额未加价");
            return (string)$amount;
        }

        return $this->AdjustmentAmount((int)$commodity->shared_premium_type, (float)$commodity->shared_premium, $amount);
    }

    public function syncRemoteItem(Commodity|int $commodity): bool
    {
        if (is_int($commodity)) {
            $commodity = Commodity::query()->find($commodity);
        }

        if (!$commodity) {
            return false;
        }

        $shared = \App\Model\Shared::query()->find($commodity->shared_id);

        if (!$shared) {
            return false;
        }

        $remoteItem = $this->item($shared, $commodity->shared_code);
        $remoteConfig = Ini::toConfig($remoteItem['config'] ?: []);

        $template = $this->resolvePremiumTemplate($commodity);
        $usesTemplate = (int)$commodity->shared_premium_type === PriceTemplate::SHARED_PREMIUM_TYPE;
        $priceSyncable = !($usesTemplate && !$template);
        if (!$priceSyncable) {
            \Kernel\Util\Log::inst()->error("商品[{$commodity->id}]的加价模板不可用，本次跳过价格与配置同步");
        }

        $base = ['price' => null, 'user_price' => null, 'config' => []];
        if ($priceSyncable) {
            $base = $template
                ? $this->AdjustmentTemplate(
                    $template,
                    $remoteConfig,
                    (string)$remoteItem['price'],
                    (string)$remoteItem['user_price'],
                    (string)$commodity->getRawOriginal('level_price')
                )
                : $this->AdjustmentPrice(
                    $remoteConfig,
                    (string)$remoteItem['price'],
                    (string)$remoteItem['user_price'],
                    $commodity->shared_premium_type,
                    $commodity->shared_premium
                );
        }

        $_config = $remoteItem['config'] ?: [];

        if (!empty($_config['sku'])) {
            $base['config']['sku_cost'] = $_config['sku'];
        }

        if (!empty($_config['category'])) {
            $base['config']['category_cost'] = $_config['category'];
        }

        if ($priceSyncable && $commodity->shared_amount_sync === 1) {
            $commodity->price = $base['price'];
            $commodity->user_price = $base['user_price'];

            if ($template && ($base['level_price'] ?? '') !== '') {
                $commodity->level_price = $base['level_price'];
            }
        }

        if ($priceSyncable && $commodity->shared_config_sync === 1) {
            $commodity->config = Ini::toConfig($base['config']);
        }

        $commodity->draft_status = $remoteItem['draft_status'];
        if ($priceSyncable) {
            $commodity->draft_premium = $remoteItem['draft_premium'] > 0
                ? $this->AdjustmentExtra($commodity, $remoteItem['draft_premium'])
                : 0;
        }
        $commodity->widget = is_array($remoteItem['widget']) ? json_encode($remoteItem['widget']) : $remoteItem['widget'];
        $commodity->stock = $remoteItem['stock'];

        $commodity->shared_stock = [];
        $commodity->save();

        //上游同步会改写售价/库存/配置，对下游而言等同于一次商品变更
        $ebIds = [(int)$commodity->id];
        $ebAction = 'sync';
        $ebBefore = null;
        hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $ebBefore);

        return true;
    }
}
