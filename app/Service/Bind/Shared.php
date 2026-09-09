<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Model\Commodity;
use App\Model\PriceTemplate;
use App\Util\Http;
use App\Util\Ini;
use App\Util\SharedCurrency;
use App\Util\SharedPayload;
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
        return (array)$this->request($url, $appId, $appKey, $data, false);
    }

    /**
     * 和 post() 一样，但**端点不存在时返回 null 而不是抛异常**。
     *
     * `stock` / `draft` / `valuation` 是 3.1.2 之后才有的接口，对着老上游打过去只会拿到
     * 一张 404 页面。调用方据此降级到老协议的等价做法。只认 404/405——其它状态码、
     * 超时、连不上都照旧报错，绝不把「对方挂了」当成「对方是老版本」。
     */
    private function postOptional(string $url, string $appId, string $appKey, array $data = []): ?array
    {
        return $this->request($url, $appId, $appKey, $data, true);
    }

    private function request(string $url, string $appId, string $appKey, array $data, bool $optional): ?array
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
            if ($optional
                && $e instanceof \GuzzleHttp\Exception\BadResponseException
                && in_array($e->getResponse()->getStatusCode(), [404, 405], true)) {
                return null;
            }
            //「对方」这个说法本身就是转售身份的暗示——它会出现在免登录的商品详情页上。
            //站长自己操作时保留原文，其余场合只给通用文案。
            throw new JSONException(SharedPayload::guardMessage(
                "连接失败, 疑似被对方防火墙拦截",
                "商品暂时无法购买，请稍后重试"
            ));
        }
        $contents = $response->getBody()->getContents();

        $result = json_decode($contents, true);
        if (!is_array($result) || ($result['code'] ?? null) != 200) {
            //上游的报错文本会一路冒泡出去：syncRemoteItem 跑在**免登录的商品详情页**上，
            //下单失败则原样回给下游。上游常在 msg 里写自己的站名、域名和充值地址，
            //那就是转售身份。出站前抹掉地址类标识，原文进日志；站长在后台操作时保留原文。
            throw new JSONException(SharedPayload::upstreamError($result['msg'] ?? null) ?: "连接失败");
        }
        return (array)$result['data'];
    }

    /** 上游协议代次：还没探明 */
    public const PROTOCOL_UNKNOWN = 0;

    /** 上游协议代次：3.1.2 及以后（item 收 code 返回单商品，有 stock/draft/valuation） */
    public const PROTOCOL_MODERN = 1;

    /** 上游协议代次：3.1.1 及更老（item 收 sharedCode 返回分类树，没有 stock/draft/valuation） */
    public const PROTOCOL_LEGACY = 2;

    /**
     * 这家上游是哪一代协议。老库没有 protocol 列时读到 null，按「未探明」处理——
     * 未探明不是老版本，只是还没问过，走的仍是先试新端点的路。
     */
    private function protocolOf(\App\Model\Shared $shared): int
    {
        $value = (int)($shared->protocol ?? self::PROTOCOL_UNKNOWN);
        return in_array($value, [self::PROTOCOL_MODERN, self::PROTOCOL_LEGACY], true)
            ? $value
            : self::PROTOCOL_UNKNOWN;
    }

    /**
     * 记住探明的代次。**先写内存再写库**：同一个请求里后续的调用立刻少一次空探测，
     * 就算补列失败（数据库账号没有 ALTER 权限）本次请求也不白探。
     */
    private function rememberProtocol(\App\Model\Shared $shared, int $protocol): void
    {
        if ($this->protocolOf($shared) === $protocol) {
            return;
        }

        $shared->protocol = $protocol;

        if ((int)$shared->id <= 0) {
            return;
        }

        try {
            \App\Util\Schema::ensureSharedProtocol();
            \App\Model\Shared::query()->whereKey((int)$shared->id)->update(['protocol' => $protocol]);
        } catch (\Throwable $e) {
            //记不住只是每次都要多探一次，不影响功能
        }
    }

    /**
     * 老协议的 inventory：**两代都认这个接口、且入参名都叫 sharedCode**，
     * 所以它是老上游那边唯一还能问出「库存 + 拿货价」的地方。
     */
    private function legacyInventory(\App\Model\Shared $shared, string $code, ?string $race = null): array
    {
        return $this->post($shared->domain . "/shared/commodity/inventory", $shared->app_id, $shared->app_key, [
            "sharedCode" => $code,
            "race" => (string)$race
        ]);
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

    /**
     * 老版本的 `/shared/commodity/item` 与今天不是同一个接口。**3.1.1 及以前**：
     *   - 入参叫 `sharedCode`，不是 `code`；
     *   - 返回的是**分类树**（那时的 `item()` 实为 `getItems($sharedCode)`），
     *     3.1.2 起才改成返回单个商品对象。
     *
     * 而 `items()` 的形状从头到尾没变过，于是「新版本对接老版本」的表现很有迷惑性：
     * **货源列表拉得到、点接入全部失败**，报错是 `远端商品字段 name 内容不正确`——
     * 树被当成一个商品去读，`name` 自然不存在。
     *
     * 这里按**形状**识别，两种口径都收。入参那头在调用点两个名字一起发：各版本只认
     * 自己那个、多出来的忽略；签名是收发两侧各自按收到的全量字段现算的，多带一个
     * 字段不影响验签。
     */
    private function unwrapRemoteItem(array $data, string $code): array
    {
        //新口径：已经是单个商品
        if (array_key_exists('name', $data) || array_key_exists('price', $data)) {
            return $data;
        }

        $flat = [];
        foreach ($data as $group) {
            if (!is_array($group) || !is_array($group['children'] ?? null)) {
                continue;
            }
            foreach ($group['children'] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                //老版本的 getItems($code) 已经按 code 过滤过了，这里仍然按 code 认人：
                //万一对方没过滤，取「第一个」就会静默接错商品——那比报错难查得多
                if ((string)($child['code'] ?? '') === $code || (string)($child['id'] ?? '') === $code) {
                    return $child;
                }
                $flat[] = $child;
            }
        }

        //没有 code 可比对（更老的树里商品行不带 code）但整棵树就一个商品：那它必然是要找的那个
        if (count($flat) === 1) {
            return $flat[0];
        }

        //认不出来就原样退回，让调用方按自己的口径报错——这里不猜
        return $data;
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

            //SharedStock 协议返回的就是一棵树，与老版本 item() 同形，识别逻辑共用一份。
            //原来盲取第一个 child，对方没按 code 过滤时会静默接错商品。
            $b = $this->unwrapRemoteItem($a, $code);

            if (!array_key_exists('name', $b) && !array_key_exists('price', $b)) {
                //$code 是**上游的商品编号**：这条异常会出现在免登录的商品详情页和
                //下游的接口响应里，把编号原样打出去等于把上游的货架指给别人看。
                throw new JSONException(SharedPayload::guardMessage(
                    "商品不存在#{$code}",
                    "商品暂时无法购买，请稍后重试"
                ));
            }

            if (isset($b['config']) && !is_array($b['config'])) {
                $b['config'] = Ini::toArray((string)$b['config']);
            }

            return SharedCurrency::item($b, $factor);
        }
        $raw = $this->post($shared->domain . "/shared/commodity/item", $shared->app_id, $shared->app_key, [
            "code" => $code,
            //3.1.1 及以前读的是 sharedCode，两个一起发，新旧上游各取所需
            "sharedCode" => $code
        ]);

        $a = $this->unwrapRemoteItem($raw, $code);

        //响应形状就是协议代次的**免费探针**：树 = ≤3.1.1，单商品 = 3.1.2+。
        //认不出形状时什么都不记——宁可下次多探一次，也不能记错代次去走死路。
        if ($a !== $raw) {
            $this->rememberProtocol($shared, self::PROTOCOL_LEGACY);
        } elseif (array_key_exists('name', $raw) || array_key_exists('price', $raw)) {
            $this->rememberProtocol($shared, self::PROTOCOL_MODERN);
        }

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
        $post = array_merge(["code" => $code], $map);

        //≤3.1.1 的 draftCard 是 `#[Post] string $sharedCode, int $page, int $limit, string $race`
        //四个强类型注入参数，名字和必填性都和今天不一样。四个都补齐，新版本会忽略多出来的。
        $post['sharedCode'] = $code;
        $post['page'] = max(1, (int)($post['page'] ?? 0));
        $post['limit'] = max(1, (int)($post['limit'] ?? 0) ?: 10);
        $post['race'] = (string)($post['race'] ?? '');

        $card = $this->post($shared->domain . "/shared/commodity/draftCard", $shared->app_id, $shared->app_key, $post);

        return SharedCurrency::draftPremiums($this->normalizeDraftCards((array)$card), SharedCurrency::factor($shared));
    }

    /**
     * 预选卡列表的两代形状归一。
     *
     * ≤3.1.1 直接把 Laravel 分页器 `toArray()` 丢了出来（`{current_page,data,total,…}`），
     * 3.1.2 起统一成 `{list,total}`；老版本的字段清单还只有 `['id','draft']`——
     * 那时 card 表**根本没有 draft_premium 列**，单卡溢价是后来才有的概念，
     * 所以这里补的 0 不是兜底估算，而是老协议下的准确值。
     */
    private function normalizeDraftCards(array $data): array
    {
        if (!isset($data['list']) && isset($data['data']) && is_array($data['data'])) {
            $data = [
                'list' => $data['data'],
                'total' => (int)($data['total'] ?? count($data['data'])),
            ];
        }

        if (!isset($data['list']) || !is_array($data['list'])) {
            return $data;
        }

        foreach ($data['list'] as $index => $row) {
            if (is_array($row) && !array_key_exists('draft_premium', $row)) {
                $data['list'][$index]['draft_premium'] = 0;
            }
        }

        return $data;
    }

    public function getDraft(\App\Model\Shared $shared, string $code, int $cardId): array
    {
        $draft = $this->protocolOf($shared) === self::PROTOCOL_LEGACY
            ? null
            : $this->postOptional($shared->domain . "/shared/commodity/draft", $shared->app_id, $shared->app_key, [
                "code" => $code,
                "card_id" => $cardId
            ]);

        if ($draft === null) {
            //≤3.1.1 没有 draft 端点，它那边的 card 表也没有 draft_premium 列——
            //单卡溢价是 3.1.2 之后才有的概念，所以 0 是准确答案而不是兜底。
            $this->rememberProtocol($shared, self::PROTOCOL_LEGACY);
            return ['draft_premium' => 0];
        }

        $this->rememberProtocol($shared, self::PROTOCOL_MODERN);

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

        $stock = $this->protocolOf($shared) === self::PROTOCOL_LEGACY
            ? null
            : $this->postOptional($shared->domain . "/shared/commodity/stock", $shared->app_id, $shared->app_key, [
                "code" => $code,
                "race" => $race,
                "sku" => $sku
            ]);

        if ($stock !== null) {
            $this->rememberProtocol($shared, self::PROTOCOL_MODERN);
            return (string)($stock['stock'] ?? "0");
        }

        //≤3.1.1 没有 stock 端点，用 inventory 的 count 顶上。老协议本来就没有 SKU，
        //$sku 在这条路上无处可传——真要卖 SKU 商品，下单时上游那边会自己拦。
        $this->rememberProtocol($shared, self::PROTOCOL_LEGACY);
        $inventory = $this->legacyInventory($shared, $code, $race);

        return (string)($inventory['count'] ?? "0");
    }

    /**
     * ≤3.1.1 没有 valuation 端点时的进货成本估算。
     *
     * 老版本的 inventory **会按请求方身份现算拿货价**：纯种类商品在
     * `config[category_factory][种类]`，其余在 `factory_price`。按单价 × 数量算总额。
     *
     * **这是估算，不是准数**：批发档、SKU 加价都不在那个接口里（老协议压根没有 SKU）。
     * 算不出来就返回 0——调用方 `Bind\Order::trade()` 里 `$rent == 0` 会回退到本地
     * 成本口径（`getCost()`），订单照常成交，只是 `order.rent` 这个统计字段按本地价记。
     * 宁可退化统计口径，也不能因为老上游少一个接口就让订单下不了。
     */
    private function legacyValuation(\App\Model\Shared $shared, string $code, int $num, ?string $race, string $factor): string|float|int
    {
        $inventory = $this->legacyInventory($shared, $code, $race);

        $config = $inventory['config'] ?? null;
        if (!is_array($config)) {
            $config = is_scalar($config) ? Ini::toArray((string)$config) : [];
        }

        $unit = null;
        $factory = $config['category_factory'] ?? null;
        if ((string)$race !== '' && is_array($factory) && isset($factory[$race]) && is_numeric($factory[$race])) {
            $unit = (string)$factory[$race];
        } elseif (is_numeric($inventory['factory_price'] ?? null)) {
            $unit = (string)$inventory['factory_price'];
        }

        if ($unit === null || (float)$unit <= 0) {
            return 0;
        }

        return SharedCurrency::amount(
            (new Decimal($unit, 6))->mul((string)max(1, $num))->getAmount(6),
            $factor
        );
    }

    public function getValuation(Commodity $commodity, \App\Model\Shared $shared, string $code, int $num, ?string $race = null, ?array $sku = [], ?int $cardId = 0): string|float|int
    {
        $factor = SharedCurrency::factor($shared);
        try {
            //config 为 null 的商品（没配种类/SKU）在 strict_types 下会让 Ini::toArray 抛
            //TypeError，被下面的 catch 吞成 0——表现是成本静默按本地口径记。补个转型。
            $config = is_array($commodity->config) ? $commodity->config : Ini::toArray((string)$commodity->config);
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

            $data = $this->protocolOf($shared) === self::PROTOCOL_LEGACY
                ? null
                : $this->postOptional($shared->domain . "/shared/commodity/valuation", $shared->app_id, $shared->app_key, [
                    'code' => $code,
                    'num' => $num,
                    'race' => $race,
                    'sku' => $sku,
                    'card_id' => $cardId
                ]);

            if ($data === null) {
                $this->rememberProtocol($shared, self::PROTOCOL_LEGACY);
                return $this->legacyValuation($shared, $code, $num, $race, $factor);
            }

            $this->rememberProtocol($shared, self::PROTOCOL_MODERN);

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
