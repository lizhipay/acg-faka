<?php
declare(strict_types=1);

namespace App\Service\Impl;


use App\Service\Shared;
use App\Util\Str;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;

class SharedService implements Shared
{

    #[Inject]
    private Client $http;


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
            $response = $this->http->post($url, ["form_params" => $data, "verify" => false]);
        } catch (\Exception $e) {
            throw new JSONException("连接失败");
        }
        $contents = $response->getBody()->getContents();
        $result = json_decode($contents, true);
        if ($result['code'] != 200) {
            throw new JSONException("连接出错");
        }
        return (array)$result['data'];
    }

    public function connect(string $domain, string $appId, string $appKey): ?array
    {
        return $this->post($domain . "/shared/authentication/connect", $appId, $appKey);
    }

    public function items(\App\Model\Shared $shared): ?array
    {
        return $this->post($shared->domain . "/shared/commodity/items", $shared->app_id, $shared->app_key);
    }

    public function inventoryState(\App\Model\Shared $shared, string $sharedCode, int $cardId, int $num, string $race): bool
    {
        $this->post($shared->domain . "/shared/commodity/inventoryState", $shared->app_id, $shared->app_key, [
            "shared_code" => $sharedCode,
            "card_id" => $cardId,
            "num" => $num,
            "race" => $race
        ]);

        return true;
    }

    public function trade(\App\Model\Shared $shared, string $sharedCode, string $contact, int $num, int $cardId, int $device, string $password, string $race, ?string $widget, string $requestNo): string
    {
        $wg = (array)json_decode((string)$widget, true);
        $post = [
            "shared_code" => $sharedCode,
            "contact" => $contact,
            "num" => $num,
            "card_id" => $cardId,
            "device" => $device,
            "password" => $password,
            "race" => $race,
            "request_no" => $requestNo
        ];

        foreach ($wg as $key => $item) {
            $post[$key] = $item['value'];
        }

        $trade = $this->post($shared->domain . "/shared/commodity/trade", $shared->app_id, $shared->app_key, $post);  
        return (string)$trade['secret'];
    }

    /**
     * @param \App\Model\Shared $shared
     * @param string $sharedCode
     * @param int $limit
     * @param int $page
     * @param string $race
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function draftCard(\App\Model\Shared $shared, string $sharedCode, int $limit, int $page, string $race): array
    {
        $card = $this->post($shared->domain . "/shared/commodity/draftCard", $shared->app_id, $shared->app_key, [
            "sharedCode" => $sharedCode,
            "page" => $page,
            "race" => $race,
            "limit" => $limit
        ]);
        return (array)$card;
    }

    /**
     * @param \App\Model\Shared $shared
     * @param string $sharedCode
     * @param string $race
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function inventory(\App\Model\Shared $shared, string $sharedCode, string $race = ""): array
    {
        $inventory = $this->post($shared->domain . "/shared/commodity/inventory", $shared->app_id, $shared->app_key, [
            "sharedCode" => $sharedCode,
            "race" => $race
        ]);
        return (array)$inventory;
    }
}