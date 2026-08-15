<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Model\Commodity;
use App\Model\PriceTemplate;
use App\Util\Http;
use App\Util\Ini;
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

    /**
     * @param string $url
     * @param string $appId
     * @param string $appKey
     * @param array $data
     * @return array
     * @throws JSONException
     */
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
                // A redirect to another host must never receive the signed
                // request headers. The configured endpoint has to answer
                // directly; operators can update the saved base URL instead.
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


    /**
     * @param string $url
     * @param string $appId
     * @param string $appKey
     * @param array $data
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    private function post(string $url, string $appId, string $appKey, array $data = []): array
    {
        $data = array_merge($data, ["app_id" => $appId, "app_key" => $appKey]);
        $data['sign'] = Str::generateSignature($data, $appKey);
        try {
            $response = Http::make()->post($url, [
                'form_params' => $data,
                'timeout' => 30,
                // app_key is part of this legacy request body. Disabling all
                // redirects prevents a 307/308 response from forwarding that
                // body to a different origin.
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

    /**
     * @param string $domain
     * @param string $appId
     * @param string $appKey
     * @param int $type
     * @return array|null
     * @throws GuzzleException
     * @throws JSONException
     */
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

    /**
     * @param array $item
     * @return array
     */
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
            'minimum' => 0, //最低购买，
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

    /**
     * @param \App\Model\Shared $shared
     * @return array|null
     * @throws GuzzleException
     * @throws JSONException
     */
    public function items(\App\Model\Shared $shared): ?array
    {
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

            return array_values($category);
        } elseif ($shared->type == 2) {
            return $this->post($shared->domain . "/plugin/SharedStock/api/items", $shared->app_id, $shared->app_key);
        }

        return $this->post($shared->domain . "/shared/commodity/items", $shared->app_id, $shared->app_key);
    }

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function item(\App\Model\Shared $shared, string $code): array
    {
        if ($shared->type == 1) {
            $data = $this->mcyRequest($shared->domain . "/plugin/open-api/item", $shared->app_id, $shared->app_key, [
                "id" => $code
            ]);
            $a = $this->createV4Item($data);

            if (!is_array($a['config'])) {
                $a['config'] = Ini::toArray((string)$a['config']);
            }

            return $a;
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

            return $b;
        }
        $a = $this->post($shared->domain . "/shared/commodity/item", $shared->app_id, $shared->app_key, [
            "code" => $code
        ]);

        //原生店铺返回的config是INI字符串，统一转成数组与type 1/2保持一致：
        //下游同步处会把config传给Ini::toConfig(array)，传字符串会直接TypeError
        if (isset($a['config']) && !is_array($a['config'])) {
            $a['config'] = Ini::toArray((string)$a['config']);
        }

        return $a;
    }


    /**
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param int $cardId
     * @param int $num
     * @param string $race
     * @return bool
     * @throws GuzzleException
     * @throws JSONException
     */
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

    /**
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
     * @throws GuzzleException
     * @throws JSONException
     * @throws \ReflectionException
     */
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

        /**
         * 更新缓存库存
         * @var \App\Service\Shop $shop
         */
        $shop = Di::inst()->make(\App\Service\Shop::class);
        $shop->updateSharedStock($commodity->id, $race, $sku);

        return (string)$trade['secret'];
    }

    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param array $map
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function draftCard(\App\Model\Shared $shared, string $code, array $map = []): array
    {
        $card = $this->post($shared->domain . "/shared/commodity/draftCard", $shared->app_id, $shared->app_key, array_merge([
            "code" => $code
        ], $map));
        return (array)$card;
    }


    /**
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param int $cardId
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function getDraft(\App\Model\Shared $shared, string $code, int $cardId): array
    {
        return $this->post($shared->domain . "/shared/commodity/draft", $shared->app_id, $shared->app_key, [
            "code" => $code,
            "card_id" => $cardId
        ]);
    }

    /**
     * @param \App\Model\Shared $shared
     * @param Commodity $commodity
     * @param string $race
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function inventory(\App\Model\Shared $shared, Commodity $commodity, string $race = ""): array
    {
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

            return $result;
        }

        $inventory = $this->post($shared->domain . "/shared/commodity/inventory", $shared->app_id, $shared->app_key, [
            "sharedCode" => $commodity->shared_code,
            "race" => $race
        ]);

        return (array)$inventory;
    }

    /**
     * @param Commodity $commodity
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param string|null $race
     * @param array|null $sku
     * @return string
     * @throws GuzzleException
     * @throws JSONException
     */
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
    public function getValuation(Commodity $commodity, \App\Model\Shared $shared, string $code, int $num, ?string $race = null, ?array $sku = [], ?int $cardId = 0): string|float|int
    {
        try {
            $config = is_array($commodity->config) ? $commodity->config : Ini::toArray($commodity->config);
            if ($shared->type == 1) { //V4
                $data = $this->mcyRequest($shared->domain . "/plugin/open-api/amount", $shared->app_id, $shared->app_key, [
                    'sku_id' => (int)$config['shared_mapping'][$race],
                    "quantity" => $num
                ]);
                return $data['amount'] ?? 0;
            } elseif ($shared->type == 2) {
                $data = $this->post($shared->domain . "/plugin/SharedStock/api/valuation", $shared->app_id, $shared->app_key, [
                    'code' => $code,
                    'num' => $num,
                    'race' => $race,
                    'card_id' => $cardId
                ]);
                return $data['price'] ?? 0;
            }

            $data = $this->post($shared->domain . "/shared/commodity/valuation", $shared->app_id, $shared->app_key, [
                'code' => $code,
                'num' => $num,
                'race' => $race,
                'sku' => $sku,
                'card_id' => $cardId
            ]);

            return $data['price'] ?? 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }


    /**
     * @param string $config
     * @param string $price
     * @param string $userPrice
     * @param int $type
     * @param float $premium
     * @return array
     * @throws JSONException
     */
    public function AdjustmentPrice(string $config, string $price, string $userPrice, int $type, float $premium): array
    {
        $this->assertPlainPremiumType($type);
        $_config = Ini::toArray($config);
        //race
        if (array_key_exists("category", $_config) && is_array($_config['category'])) {
            foreach ($_config['category'] as &$_price) {
                $_tmp = new Decimal($_price, 2);
                $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
            }
        }
        //sku
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

        //wholesale
        if (array_key_exists("wholesale", $_config) && is_array($_config['wholesale'])) {
            foreach ($_config['wholesale'] as &$_price) {
                $_tmp = new Decimal($_price, 2);
                $_price = $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($_price)->getAmount())->getAmount();
            }
        }

        //category_wholesale
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


    /**
     * 按加价模板计算接入商品的整套价格。
     *
     * 返回结构刻意与 AdjustmentPrice 对齐（config 同样是数组），调用方两种加价模式可以共用同一段代码；
     * 多出来的 level_price 是模板独有的能力——普通加价没法给每个会员等级单独定价。
     *
     * @param PriceTemplate $template
     * @param string $config
     * @param string $price
     * @param string $userPrice
     * @param string $levelPrice
     * @return array{config: array, price: string, user_price: string, level_price: string}
     */
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


    /**
     * @param int $type
     * @param float $premium
     * @param float|int|string $amount
     * @return string
     */
    public function AdjustmentAmount(int $type, float $premium, float|int|string $amount): string
    {
        $this->assertPlainPremiumType($type);
        $_tmp = new Decimal($amount, 2);
        return $type == PriceTemplate::TYPE_FIXED ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($amount)->getAmount())->getAmount();
    }

    /**
     * 这两个方法只会算「固定金额」和「百分比」两种加价。
     *
     * 它们原来的写法是 `$type == 0 ? 加固定值 : 加百分比` —— 任何不认识的 type
     * 都会被当成百分比。加价模板（type=2）的 premium 恒为 0，于是
     * `价格 + 0 × 价格 = 价格`，加价被静默抹平，商品按进货价卖出去。
     * 这个 bug 藏了很久才被发现（表现是首页价格来回跳），所以这里改成显式白名单：
     * 不认识的加价模式当场抛错，让它在第一次调用时就暴露，而不是变成收入损失。
     *
     * 模板模式请走 AdjustmentTemplate() 或 AdjustmentExtra()。
     *
     * @throws JSONException
     */
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


    /**
     * 取商品的加价模板。只有加价模式确实是「模板」时才认，
     * 避免旧数据里残留的 template_id 在其他模式下意外生效。
     */
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
            //选了模板却取不到，按原价返回并留下痕迹，绝不用错误的公式硬算
            \Kernel\Util\Log::inst()->error("商品[{$commodity->id}]的加价模板不可用，附加金额未加价");
            return (string)$amount;
        }

        return $this->AdjustmentAmount((int)$commodity->shared_premium_type, (float)$commodity->shared_premium, $amount);
    }

    /**
     * @param Commodity|int $commodity
     * @return bool
     * @throws GuzzleException
     * @throws JSONException
     */
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

        //入库时选了加价模板的商品，每次同步都要按模板重算，否则价格会退回"平进平出"
        $template = $this->resolvePremiumTemplate($commodity);
        $usesTemplate = (int)$commodity->shared_premium_type === PriceTemplate::SHARED_PREMIUM_TYPE;
        $priceSyncable = !($usesTemplate && !$template);
        if (!$priceSyncable) {
            //模板没了：宁可这次不同步价格，也不能退回无加价把售价刷成进货价
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
            //模板还负责各会员等级的价格，同步价格时一并按模板重算
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
        $commodity->seckill_status = $remoteItem['seckill_status'];
        $commodity->seckill_start_time = $remoteItem['seckill_start_time'];
        $commodity->seckill_end_time = $remoteItem['seckill_end_time'];
        $commodity->widget = is_array($remoteItem['widget']) ? json_encode($remoteItem['widget']) : $remoteItem['widget'];
        $commodity->minimum = $remoteItem['minimum'];
        $commodity->maximum = $remoteItem['maximum'];
        $commodity->stock = $remoteItem['stock'];
        $commodity->contact_type = $remoteItem['contact_type'];
        //详情页的库存走 shared_stock 缓存，而这份缓存此前只有「本站卖出一单」才会失效——
        //上游补货或在别处卖光都刷不掉它，商品能一直显示售罄或一直显示有货。
        //同步本来就是为了让接入商品跟上上游，顺手把它清掉，下次访问详情页重新拉。
        $commodity->shared_stock = [];
        $commodity->save();

        return true;
    }
}
