<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Model\Commodity;
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
                "timeout" => 30
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
                'timeout' => 30
            ]);
        } catch (\Exception $e) {
            throw new JSONException("连接失败");
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
            'minimum' => 0 //最低购买
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

        foreach ($item['sku'] as $sku) {
            $config['category'][$sku['name']] = $sku['stock_price'];
            $config['shared_mapping'][$sku['name']] = $sku['id'];
        }

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
        return $this->post($shared->domain . "/shared/commodity/item", $shared->app_id, $shared->app_key, [
            "code" => $code
        ]);
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
                $result['count'] = (int)$data['stock'];
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
     * @param \App\Model\Shared $shared
     * @param string $code
     * @param string|null $race
     * @param array|null $sku
     * @return string
     * @throws GuzzleException
     * @throws JSONException
     */
    public function getItemStock(\App\Model\Shared $shared, string $code, ?string $race = null, ?array $sku = []): string
    {
        $stock = $this->post($shared->domain . "/shared/commodity/stock", $shared->app_id, $shared->app_key, [
            "code" => $code,
            "race" => $race,
            "sku" => $sku
        ]);
        return $stock['stock'] ?? "0";
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
     * @param int $type
     * @param float $premium
     * @param float|int|string $amount
     * @return string
     */
    public function AdjustmentAmount(int $type, float $premium, float|int|string $amount): string
    {
        $_tmp = new Decimal($amount, 2);
        return $type == 0 ? $_tmp->add($premium)->getAmount() : $_tmp->add((new Decimal($premium, 3))->mul($amount)->getAmount())->getAmount();
    }
}