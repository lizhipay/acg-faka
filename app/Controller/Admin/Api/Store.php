<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\Shared;
use App\Service\Image;
use App\Service\Query;
use App\Util\Date;
use App\Util\Ini;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Store extends Manage
{

    private ?\HTMLPurifier $remoteContentPurifier = null;

    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Shared $shared;

    #[Inject]
    private Image $image;

    /**
     * @param mixed $value
     * @return int
     * @throws JSONException
     */
    private function sharedId(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_scalar($value) || !preg_match('/^\d+$/D', trim((string)$value))) {
            throw new JSONException('共享店铺 ID 格式不正确');
        }
        $id = (int)$value;
        if ($id < 1 || $id > 4294967295) {
            throw new JSONException('共享店铺 ID 超出有效范围');
        }
        return $id;
    }

    /**
     * Validate and normalize the remote store base URL before it is used by
     * the shared-store client. Credentials and URL fragments must never be
     * accepted as part of the persisted address.
     *
     * @param mixed $value
     * @return string
     * @throws JSONException
     */
    private function sharedDomain(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new JSONException('店铺地址格式不正确');
        }
        $domain = trim((string)$value);
        if ($domain === '' || strlen($domain) > 128 || preg_match('/[\x00-\x20\x7F\\\\]/', $domain)) {
            throw new JSONException('店铺地址必须是 128 个字符以内的 HTTP(S) 地址');
        }

        $parts = parse_url($domain);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new JSONException('店铺地址必须是完整的 HTTP(S) 地址，且不能包含账号、查询参数或锚点');
        }

        $host = trim((string)$parts['host'], '[]');
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isHostname = preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/iD',
            $host
        ) === 1;
        if (!$isIp && !$isHostname) {
            throw new JSONException('店铺地址中的域名或 IP 不正确');
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new JSONException('店铺地址端口不正确');
        }

        $path = (string)($parts['path'] ?? '');
        $decodedPath = rawurldecode($path);
        if (
            preg_match('/[\x00-\x20\x7F\\\\]/', $decodedPath)
            || preg_match('~(?:^|/)(?:\.{1,2})(?:/|$)~', $decodedPath)
        ) {
            throw new JSONException('店铺地址路径不正确');
        }
        $path = $path === '/' ? '' : rtrim($path, '/');

        $scheme = strtolower((string)$parts['scheme']);
        $normalizedHost = str_contains($host, ':') ? "[{$host}]" : strtolower($host);
        $normalized = $scheme . '://' . $normalizedHost . ($port === null ? '' : ":{$port}") . $path;
        if (strlen($normalized) > 128 || filter_var($normalized, FILTER_VALIDATE_URL) === false) {
            throw new JSONException('店铺地址格式不正确');
        }
        return $normalized;
    }

    /**
     * @param mixed $value
     * @return string
     * @throws JSONException
     */
    private function sharedAppId(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new JSONException('商户 ID 格式不正确');
        }
        $appId = trim((string)$value);
        if (!preg_match('/^[A-Za-z0-9._:@-]{1,32}$/D', $appId)) {
            throw new JSONException('商户 ID 必须是 1–32 位字母、数字或 . _ : @ -');
        }
        return $appId;
    }

    /**
     * @param mixed $value
     * @return string
     * @throws JSONException
     */
    private function sharedAppKey(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new JSONException('商户密钥格式不正确');
        }
        $appKey = (string)$value;
        if (preg_match('/^[^\s\x00-\x1F\x7F]{1,64}$/uD', $appKey) !== 1) {
            throw new JSONException('商户密钥必须是 1–64 位且不能包含空白或控制字符');
        }
        return $appKey;
    }

    /**
     * Normalize the identity fields returned by a remote shared store before
     * they are persisted or rendered in either admin layout.
     *
     * @return array{name: string, balance: float}
     * @throws JSONException
     */
    private function sharedConnectResult(mixed $connect): array
    {
        if (!is_array($connect) || !is_scalar($connect['shopName'] ?? null) || !is_numeric($connect['balance'] ?? null)) {
            throw new JSONException('远端店铺返回的数据格式不正确');
        }

        $name = trim(strip_tags((string)$connect['shopName']));
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $balance = (float)$connect['balance'];
        if ($name === '' || $nameLength > 128 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new JSONException('远端店铺名称格式不正确');
        }
        if (!is_finite($balance) || $balance < 0 || $balance > 999999999999.99) {
            throw new JSONException('远端店铺余额格式不正确');
        }
        return ['name' => $name, 'balance' => round($balance, 2)];
    }

    /**
     * @return int[]
     * @throws JSONException
     */
    private function sharedIds(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new JSONException('请至少选择一个共享店铺');
        }
        $ids = [];
        foreach ($value as $candidate) {
            $id = $this->sharedId($candidate);
            if ($id < 1) {
                throw new JSONException('共享店铺 ID 格式不正确');
            }
            $ids[$id] = $id;
        }
        $ids = array_values($ids);
        if (count($ids) > 100) {
            throw new JSONException('单次最多移除 100 个共享店铺');
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @return resource
     * @throws JSONException
     */
    private function acquireSyncLock(int $id)
    {
        if ($id < 1 || !is_dir(BASE_PATH . '/runtime')) {
            throw new JSONException('同步任务锁初始化失败');
        }
        $handle = @fopen(BASE_PATH . "/runtime/shared-store-sync-{$id}.lock", 'c');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new JSONException('该店铺已有同步或维护任务正在执行，请稍后重试');
        }
        return $handle;
    }

    /** @param resource $handle */
    private function releaseSyncLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Return only the fields needed by the tree picker. Full remote product
     * records are never sent back to the browser and therefore cannot be
     * modified client-side before import.
     *
     * @throws JSONException
     */
    private function remoteItemTree(mixed $groups): array
    {
        if (!is_array($groups) || count($groups) > 200) {
            throw new JSONException('远端商品分类数据格式不正确或数量过多');
        }

        $result = [];
        $total = 0;
        $seenIds = [];
        $seenCodes = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !is_scalar($group['name'] ?? null) || !is_array($group['children'] ?? null)) {
                throw new JSONException('远端商品分类结构不正确');
            }
            $name = trim(strip_tags((string)$group['name']));
            if ($name === '' || mb_strlen($name, 'UTF-8') > 128 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                throw new JSONException('远端商品分类名称不正确');
            }

            $children = [];
            foreach ($group['children'] as $item) {
                if (!is_array($item)) {
                    throw new JSONException('远端商品记录格式不正确');
                }
                $id = $this->positiveRemoteItemId($item['id'] ?? null);
                $code = $this->remoteItemCode($item['code'] ?? $item['id'] ?? null);
                if (isset($seenIds[$id]) || isset($seenCodes[$code])) {
                    throw new JSONException('远端商品 ID 或编号重复，已阻止接入');
                }
                $seenIds[$id] = true;
                $seenCodes[$code] = true;
                $itemName = is_scalar($item['name'] ?? null) ? trim(strip_tags((string)$item['name'])) : '';
                if ($itemName === '' || mb_strlen($itemName, 'UTF-8') > 255 || preg_match('/[\x00-\x1F\x7F]/u', $itemName)) {
                    throw new JSONException('远端商品名称不正确');
                }
                $children[] = ['id' => $id, 'name' => $itemName, 'code' => $code];
                $total++;
                if ($total > 2000) {
                    throw new JSONException('远端商品数量超过单次可展示上限 2000');
                }
            }
            $result[] = ['id' => 0, 'name' => $name, 'children' => $children];
        }
        return $result;
    }

    /** @throws JSONException */
    private function positiveRemoteItemId(mixed $value): int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && ctype_digit(trim($value))) {
            $id = (int)trim($value);
        } else {
            throw new JSONException('远端商品 ID 格式不正确');
        }
        if ($id < 1 || $id > 4294967295) {
            throw new JSONException('远端商品 ID 超出有效范围');
        }
        return $id;
    }

    /** @throws JSONException */
    private function remoteItemCode(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new JSONException('远端商品编号格式不正确');
        }
        $code = trim((string)$value);
        if ($code === '' || strlen($code) > 64 || preg_match('/[\x00-\x20\x7F]/', $code)) {
            throw new JSONException('远端商品编号必须是 1–64 位且不能包含空白或控制字符');
        }
        return $code;
    }

    /** @throws JSONException */
    private function binaryFlag(mixed $value, string $label): int
    {
        if (!is_scalar($value) || !in_array((string)$value, ['0', '1'], true)) {
            throw new JSONException("{$label}参数不正确");
        }
        return (int)$value;
    }

    /** @throws JSONException */
    private function remoteText(array $item, string $field, int $maxLength, bool $required = false): string
    {
        if (isset($item[$field]) && !is_scalar($item[$field])) {
            throw new JSONException("远端商品字段 {$field} 格式不正确");
        }
        $value = trim((string)($item[$field] ?? ''));
        if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            throw new JSONException("远端商品字段 {$field} 内容不正确");
        }
        return $value;
    }

    /** @throws JSONException */
    private function remoteInteger(array $item, string $field, int $min, int $max, int $default = 0): int
    {
        $value = $item[$field] ?? $default;
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/D', trim($value))) {
            $integer = (int)trim($value);
        } elseif (is_float($value) && floor($value) === $value) {
            $integer = (int)$value;
        } else {
            throw new JSONException("远端商品字段 {$field} 必须是整数");
        }
        if ($integer < $min || $integer > $max) {
            throw new JSONException("远端商品字段 {$field} 超出有效范围");
        }
        return $integer;
    }

    /** @throws JSONException */
    private function remoteAmount(array $item, string $field, float $max = 99999999.99, float $default = 0): float
    {
        $value = $item[$field] ?? $default;
        if (!is_numeric($value)) {
            throw new JSONException("远端商品字段 {$field} 必须是数字");
        }
        $amount = (float)$value;
        if (!is_finite($amount) || $amount < 0 || $amount > $max) {
            throw new JSONException("远端商品字段 {$field} 超出有效范围");
        }
        return $amount;
    }

    /** @throws JSONException */
    private function remoteWidget(mixed $widget): string
    {
        if (is_string($widget)) {
            if (strlen($widget) > 65535) {
                throw new JSONException('远端商品控件配置过大');
            }
            $widgets = json_decode($widget, true);
            $validJson = json_last_error() === JSON_ERROR_NONE;
        } elseif (is_array($widget)) {
            $widgets = $widget;
            $validJson = true;
        } else {
            throw new JSONException('远端商品控件配置格式不正确');
        }
        if (!$validJson || !is_array($widgets) || count($widgets) > 32) {
            throw new JSONException('远端商品控件配置不是有效 JSON 或数量过多');
        }

        $clean = [];
        foreach ($widgets as $entry) {
            if (!is_array($entry)) {
                throw new JSONException('远端商品控件字段格式不正确');
            }
            $name = is_scalar($entry['name'] ?? null) ? trim((string)$entry['name']) : '';
            $type = is_scalar($entry['type'] ?? null) ? trim((string)$entry['type']) : '';
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $name) || !in_array($type, ['text', 'password', 'number', 'select', 'checkbox', 'radio', 'textarea'], true)) {
                throw new JSONException('远端商品控件名称或类型不正确');
            }
            $plain = static function (mixed $value, int $max, string $label): string {
                if (!is_scalar($value)) {
                    throw new JSONException("远端商品控件{$label}格式不正确");
                }
                $text = trim(strip_tags((string)$value));
                if (mb_strlen($text, 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/u', $text)) {
                    throw new JSONException("远端商品控件{$label}内容不正确");
                }
                return $text;
            };
            $htmlText = static fn(mixed $value, int $max, string $label): string => htmlspecialchars(
                $plain($value, $max, $label),
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8'
            );
            $regex = $plain($entry['regex'] ?? '', 128, '正则');
            if ($regex !== '' && @preg_match('/' . $regex . '/u', '') === false) {
                throw new JSONException('远端商品控件正则表达式不正确');
            }

            $dictSource = $plain($entry['dict'] ?? '', 4096, '选项');
            $dict = [];
            foreach ($dictSource === '' ? [] : explode(',', $dictSource) as $pair) {
                if (count($dict) >= 100) {
                    throw new JSONException('远端商品控件选项过多');
                }
                $parts = explode('=', $pair, 2);
                if (count($parts) !== 2) {
                    throw new JSONException('远端商品控件选项格式不正确');
                }
                $label = $htmlText($parts[0], 64, '选项名称');
                $value = $htmlText($parts[1], 64, '选项值');
                if ($label === '' || $value === '') {
                    throw new JSONException('远端商品控件选项不能为空');
                }
                $dict[] = $label . '=' . $value;
            }
            $clean[] = [
                'cn' => $htmlText($entry['cn'] ?? '', 64, '标题'),
                'name' => $name,
                'placeholder' => $htmlText($entry['placeholder'] ?? '', 128, '提示'),
                'type' => $type,
                'regex' => $regex,
                'error' => $htmlText($entry['error'] ?? '', 128, '错误提示'),
                'dict' => implode(',', $dict),
            ];
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 65535) {
            throw new JSONException('远端商品控件配置无法安全保存');
        }
        return $json;
    }

    private function purifier(): \HTMLPurifier
    {
        if ($this->remoteContentPurifier) {
            return $this->remoteContentPurifier;
        }
        $cachePath = BASE_PATH . '/runtime/shared-store-purifier';
        if (!is_dir($cachePath) && !mkdir($cachePath, 0755, true) && !is_dir($cachePath)) {
            throw new JSONException('远端商品内容安全组件初始化失败');
        }
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('Cache.SerializerPermissions', 0755);
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,del,blockquote,ul,ol,li,h1,h2,h3,h4,h5,h6,hr,pre,code[class],a[href|title|target|rel],img[src|alt|title|width|height],table,thead,tbody,tr,th,td,div,span');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('URI.DisableExternalResources', false);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        $config->set('Attr.EnableID', false);
        $this->remoteContentPurifier = new \HTMLPurifier($config);
        return $this->remoteContentPurifier;
    }

    /** @throws JSONException */
    private function remoteAssetUrl(Shared $shared, mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new JSONException('远端图片地址格式不正确');
        }
        $source = trim(html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($source === '' || strlen($source) > 2048 || preg_match('/[\x00-\x20\x7F\\]/', $source)) {
            throw new JSONException('远端图片地址格式不正确');
        }
        try {
            $base = new \GuzzleHttp\Psr7\Uri(rtrim((string)$shared->domain, '/') . '/');
            $uri = \GuzzleHttp\Psr7\UriResolver::resolve($base, new \GuzzleHttp\Psr7\Uri($source));
        } catch (\Throwable) {
            throw new JSONException('远端图片地址格式不正确');
        }
        if (!in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '' || $uri->getUserInfo() !== '') {
            throw new JSONException('远端图片地址只允许 HTTP(S)');
        }
        $this->assertRemoteAssetDestination($shared, $uri);
        $url = (string)$uri->withFragment('');
        if (strlen($url) > 2048) {
            throw new JSONException('远端图片地址过长');
        }
        return $url;
    }

    /**
     * A configured store may itself be on a private network, but that store
     * must not be able to redirect image localization toward another private
     * host (for example a cloud metadata endpoint).
     *
     * @throws JSONException
     */
    private function assertRemoteAssetDestination(Shared $shared, \Psr\Http\Message\UriInterface $uri): void
    {
        $base = new \GuzzleHttp\Psr7\Uri((string)$shared->domain);
        $host = strtolower(trim($uri->getHost(), '[]'));
        $baseHost = strtolower(trim($base->getHost(), '[]'));
        $port = $uri->getPort() ?? (strtolower($uri->getScheme()) === 'https' ? 443 : 80);
        $basePort = $base->getPort() ?? (strtolower($base->getScheme()) === 'https' ? 443 : 80);
        if ($host === $baseHost && $port === $basePort) {
            return;
        }
        if (!in_array($port, [80, 443], true)) {
            throw new JSONException('第三方图片地址只允许标准 HTTP(S) 端口');
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address) && $address !== '') {
                    $addresses[$address] = $address;
                }
            }
        }
        if ($addresses === []) {
            throw new JSONException('第三方图片域名无法安全解析');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new JSONException('第三方图片地址不能指向内网或保留地址');
            }
        }
    }

    /** @throws JSONException */
    private function remoteDescription(Shared $shared, string $html, bool $download): string
    {
        if (strlen($html) > 1048576) {
            throw new JSONException('远端商品说明超过 1MB，已阻止接入');
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div data-shared-root="1">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new JSONException('远端商品说明无法解析');
        }
        foreach (iterator_to_array($document->getElementsByTagName('img')) as $image) {
            $source = $this->remoteAssetUrl($shared, $image->getAttribute('src'));
            if ($download) {
                $downloaded = $this->image->downloadRemoteImage($source);
                $source = (string)($downloaded[0] ?? '');
                if (!preg_match('#^/assets/cache/(?:general|user|\d+)/image/[A-Za-z0-9._/-]+$#i', $source)) {
                    throw new JSONException('远端商品图片本地化结果不正确');
                }
            }
            $image->setAttribute('src', $source);
        }
        $root = $document->getElementsByTagName('div')->item(0);
        if (!$root) {
            throw new JSONException('远端商品说明结构不正确');
        }
        $result = '';
        foreach ($root->childNodes as $node) {
            $result .= $document->saveHTML($node);
        }
        return $this->purifier()->purify($result);
    }

    /**
     * Validate a product freshly read from the configured remote store.
     * @throws JSONException
     */
    private function remoteItem(Shared $shared, array $item, string $requestedCode, bool $downloadImages): array
    {
        $name = trim(strip_tags($this->remoteText($item, 'name', 255, true)));
        if ($name === '') {
            throw new JSONException('远端商品名称不正确');
        }
        $description = $this->remoteDescription($shared, $this->remoteText($item, 'description', 1048576), $downloadImages);
        $coverSource = $this->remoteText($item, 'cover', 2048);
        $cover = '/favicon.ico';
        if ($coverSource !== '') {
            $coverUrl = $this->remoteAssetUrl($shared, $coverSource);
            if ($downloadImages) {
                $downloaded = $this->image->downloadRemoteImage($coverUrl);
                $cover = (string)($downloaded[0] ?? '');
                if (!preg_match('#^/assets/cache/(?:general|user|\d+)/image/[A-Za-z0-9._/-]+$#i', $cover)) {
                    throw new JSONException('远端商品封面本地化结果不正确');
                }
            } else {
                $cover = $coverUrl;
            }
        }

        $config = $item['config'] ?? '';
        if (is_array($config)) {
            $config = Ini::toConfig($config);
        } elseif (!is_scalar($config)) {
            throw new JSONException('远端商品价格配置格式不正确');
        } else {
            $config = (string)$config;
        }
        if (strlen($config) > 1048576) {
            throw new JSONException('远端商品价格配置过大');
        }

        $widget = $item['widget'] ?? '[]';
        if ($widget === '' || $widget === null) {
            $widget = '[]';
        }
        $widget = $this->remoteWidget($widget);

        $seckillStatus = $this->remoteInteger($item, 'seckill_status', 0, 1);
        $seckillStart = $this->remoteText($item, 'seckill_start_time', 32);
        $seckillEnd = $this->remoteText($item, 'seckill_end_time', 32);
        if ($seckillStatus === 1) {
            $startTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $seckillStart);
            $endTime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $seckillEnd);
            if (!$startTime || !$endTime || $endTime <= $startTime) {
                throw new JSONException('远端商品秒杀时间不正确');
            }
        }

        return [
            'name' => $name,
            'description' => $description,
            'cover' => $cover,
            'code' => $requestedCode,
            'contact_type' => $this->remoteInteger($item, 'contact_type', 0, 3),
            'password_status' => $this->remoteInteger($item, 'password_status', 0, 1),
            'seckill_status' => $seckillStatus,
            'seckill_start_time' => $seckillStart,
            'seckill_end_time' => $seckillEnd,
            'draft_status' => $this->remoteInteger($item, 'draft_status', 0, 1),
            'draft_premium' => $this->remoteAmount($item, 'draft_premium'),
            'inventory_hidden' => $this->remoteInteger($item, 'inventory_hidden', 0, 1),
            'only_user' => $this->remoteInteger($item, 'only_user', 0, 1),
            'purchase_count' => $this->remoteInteger($item, 'purchase_count', 0, 4294967295),
            'minimum' => $this->remoteInteger($item, 'minimum', 0, 4294967295),
            'stock' => $this->remoteInteger($item, 'stock', 0, 2147483647),
            'widget' => $widget,
            'config' => $config,
            'price' => $this->remoteAmount($item, 'price'),
            'user_price' => $this->remoteAmount($item, 'user_price'),
        ];
    }

    /**
     * @return array
     */
    public function data(): array
    {
        $map = array_intersect_key($_POST, array_flip([
            'search-name',
            'search-domain',
            'equal-type',
        ]));
        $page = max(1, (int)$this->request->post('page'));
        $limit = (int)$this->request->post('limit');
        if (!in_array($limit, [15, 30], true)) {
            $limit = 15;
        }
        $get = new Get(Shared::class);
        $get->setPaginate($page, $limit);
        // Explicit columns make app_key impossible to serialize into either the
        // desktop table or the mobile snapshot, regardless of request filters.
        $get->setColumn('id', 'type', 'name', 'domain', 'app_id', 'create_time', 'balance');
        $get->setWhere($map);
        $data = $this->query->get($get);
        return $this->json(data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $raw = $_POST;
        $allowed = ['id', 'type', 'domain', 'app_id', 'app_key'];
        foreach (array_keys($raw) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new JSONException('共享店铺保存请求包含未授权字段');
            }
        }

        $id = $this->sharedId($raw['id'] ?? null);
        $existing = $id > 0 ? Shared::query()->find($id) : null;
        if ($id > 0 && !$existing) {
            throw new JSONException('未找到该店铺');
        }

        $typeValue = $raw['type'] ?? $existing?->type;
        if (!is_scalar($typeValue) || !preg_match('/^[012]$/D', trim((string)$typeValue))) {
            throw new JSONException('共享协议不正确');
        }
        $type = (int)$typeValue;
        $domain = $this->sharedDomain($raw['domain'] ?? $existing?->domain);
        $appId = $this->sharedAppId($raw['app_id'] ?? $existing?->app_id);

        $newAppKey = $raw['app_key'] ?? '';
        if (!is_scalar($newAppKey)) {
            throw new JSONException('商户密钥格式不正确');
        }
        // An empty secret on edit is an explicit keep-existing operation. The
        // current secret is only read on the server and is never sent to JS.
        $appKey = (string)$newAppKey === '' && $existing
            ? (string)$existing->app_key
            : $this->sharedAppKey($newAppKey);

        $duplicate = Shared::query()->where('domain', $domain);
        if ($id > 0) {
            $duplicate->where('id', '!=', $id);
        }
        if ($duplicate->exists()) {
            throw new JSONException('该店铺地址已经存在');
        }

        // All local input is valid before an external connection is attempted.
        // Use a generic failure message so a hostile remote response cannot echo
        // the submitted credential into JSON or DEBUG output.
        try {
            $connect = $this->shared->connect($domain, $appId, $appKey, $type);
        } catch (\Throwable) {
            throw new JSONException('连接失败，请检查店铺地址、共享协议和商户凭据');
        }
        $identity = $this->sharedConnectResult($connect);

        $store = $existing ?? new Shared();
        $store->type = $type;
        $store->domain = $domain;
        $store->app_id = $appId;
        $store->app_key = $appKey;
        $store->name = $identity['name'];
        $store->balance = $identity['balance'];
        if (!$existing) {
            $store->create_time = Date::current();
        }

        try {
            $saved = $store->save();
        } catch (\Throwable) {
            throw new JSONException('保存失败，请确认店铺地址未被其他记录占用');
        }
        if (!$saved) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        ManageLog::log($this->getManage(), ($existing ? '[修改]' : '[新增]') . "共享店铺 ID：{$store->id}");
        return $this->json(200, '（＾∀＾）保存成功', ['id' => (int)$store->id]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function connect(): array
    {
        $id = $this->sharedId($_POST['id'] ?? null);
        if ($id < 1) {
            throw new JSONException('共享店铺 ID 格式不正确');
        }
        $shared = Shared::query()->find($id);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }
        try {
            $connect = $this->shared->connect($shared->domain, $shared->app_id, $shared->app_key, $shared->type);
        } catch (\Throwable) {
            throw new JSONException('连接失败，请检查店铺地址、共享协议和商户凭据');
        }
        $identity = $this->sharedConnectResult($connect);
        $shared->name = $identity['name'];
        $shared->balance = $identity['balance'];
        if (!$shared->save()) {
            throw new JSONException('店铺连接成功，但缓存状态保存失败');
        }
        return $this->json(200, 'success');
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function items(): array
    {
        $id = $this->sharedId($_POST['id'] ?? null);
        if ($id < 1) {
            throw new JSONException('共享店铺 ID 格式不正确');
        }
        $shared = Shared::query()->find($id);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }
        try {
            $items = $this->remoteItemTree($this->shared->items($shared));
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('远端商品列表读取失败');
        }
        return $this->json(200, 'success', $items);
    }

    /**
     * @throws JSONException
     */
    public function addItem(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);

        $categoryId = $this->sharedId($map['category_id'] ?? null);
        $storeId = $this->sharedId($_GET['storeId'] ?? null);
        if ($categoryId < 1 || $storeId < 1) {
            throw new JSONException('商品分类或共享店铺 ID 不正确');
        }
        $rawCodes = $map['item_codes'] ?? null;
        if (!is_array($rawCodes) || $rawCodes === [] || count($rawCodes) > 100) {
            throw new JSONException('单次必须选择 1–100 个远端商品');
        }
        $itemCodes = [];
        foreach ($rawCodes as $rawCode) {
            $code = $this->remoteItemCode($rawCode);
            $itemCodes[$code] = $code;
        }
        $itemCodes = array_values($itemCodes);

        if (!is_scalar($map['premium'] ?? null) || !is_numeric($map['premium'])) {
            throw new JSONException('加价数额必须是数字');
        }
        $premium = (float)$map['premium'];
        if (!is_finite($premium) || $premium < 0 || $premium > 99999999.99) {
            throw new JSONException('加价数额超出有效范围');
        }
        if (!is_scalar($map['premium_type'] ?? null) || !in_array((string)$map['premium_type'], ['0', '1'], true)) {
            throw new JSONException('加价模式不正确');
        }
        $premiumType = (int)$map['premium_type'];
        $imageDownload = $this->binaryFlag($map['image_download'] ?? 0, '远端图片本地化') === 1;
        $shelves = $this->binaryFlag($map['shelves'] ?? 0, '立即上架');
        $sharedSync = $this->binaryFlag($map['shared_sync'] ?? 0, '远端信息同步');
        $sharedAmountSync = $this->binaryFlag($map['shared_amount_sync'] ?? 0, '远端价格同步');
        $sharedConfigSync = $this->binaryFlag($map['shared_config_sync'] ?? 0, '远端配置同步');

        $shared = Shared::query()->find($storeId);

        if (!$shared) {
            throw new JSONException("未找到该店铺");
        }

        $date = Date::current();
        $count = count($itemCodes);
        $success = 0;
        $error = 0;

        foreach ($itemCodes as $itemCode) {
            try {
                $remote = $this->shared->item($shared, $itemCode);
                if (!is_array($remote)) {
                    throw new JSONException('远端商品详情格式不正确');
                }
                $item = $this->remoteItem($shared, $remote, $itemCode, $imageDownload);
                $commodity = new \App\Model\Commodity();
                $commodity->category_id = $categoryId;
                $commodity->name = $item['name'];
                $commodity->description = $item['description'];
                $commodity->cover = $item['cover'];

                $commodity->status = $shelves;
                $commodity->owner = 0;
                $commodity->create_time = $date;
                $commodity->api_status = 0;
                $commodity->code = strtoupper(Str::generateRandStr(16));
                $commodity->delivery_way = 1;
                $commodity->contact_type = $item['contact_type'];
                $commodity->password_status = $item['password_status'];
                $commodity->sort = 0;
                $commodity->coupon = 0;
                $commodity->shared_id = $storeId;
                $commodity->shared_code = $item['code'];
                $commodity->shared_premium = $premium;
                $commodity->shared_premium_type = $premiumType;
                $commodity->seckill_status = $item['seckill_status'];
                $commodity->shared_sync = $sharedSync;
                $commodity->shared_amount_sync = $sharedAmountSync;
                $commodity->shared_config_sync = $sharedConfigSync;

                if ($commodity->seckill_status == 1) {
                    $commodity->seckill_start_time = $item['seckill_start_time'];
                    $commodity->seckill_end_time = $item['seckill_end_time'];
                }

                $commodity->draft_status = $item['draft_status'];

                if ($commodity->draft_status) {
                    $commodity->draft_premium = $this->shared->AdjustmentAmount($premiumType, $premium, $item['draft_premium']);
                }

                //2022/01/05新增
                $commodity->inventory_hidden = $item['inventory_hidden'];
                $commodity->only_user = $item['only_user'];
                $commodity->purchase_count = $item['purchase_count'];
                $commodity->widget = $item['widget'];
                $commodity->minimum = $item['minimum'];
                $commodity->stock = $item['stock'];

                //自动加价
                $config = $this->shared->AdjustmentPrice((string)$item['config'], $item['price'], $item['user_price'], $premiumType, $premium);

                $_config = Ini::toArray((string)$item['config']);

                if (!empty($_config['sku_cost'])) {
                    unset($config['config']['sku_cost']);
                }

                if (!empty($_config['category_cost'])) {
                    unset($config['config']['category_cost']);
                }

                $commodity->config = Ini::toConfig($config['config']);
                $commodity->price = $config['price'];
                $commodity->factory_price = 0;
                $commodity->user_price = $config['user_price'];

                DB::transaction(function () use ($categoryId, $commodity): void {
                    $category = \App\Model\Category::query()
                        ->where('owner', 0)
                        ->where('id', $categoryId)
                        ->lockForUpdate()
                        ->first();
                    if (!$category) {
                        throw new JSONException('商品分类不存在或不属于系统');
                    }
                    if (\App\Model\Commodity::query()
                        ->where('shared_id', $commodity->shared_id)
                        ->where('shared_code', $commodity->shared_code)
                        ->lockForUpdate()
                        ->exists()) {
                        throw new JSONException('该远端商品已经接入');
                    }
                    $commodity->save();
                });
                $success++;
            } catch (\Throwable) {
                $error++;
            }
        }

        ManageLog::log($this->getManage(), "[店铺共享]进行了克隆商品({$shared->name})，总数量：{$count}，成功：{$success}，失败：{$error}");
        return $this->json(200, "拉取结束，总数量：{$count}，成功：{$success}，失败：{$error}");
    }


    /**
     * @param int $id
     * @return array
     */
    public function syncRemote(int $id): array
    {
        if ($id < 1 || !Shared::query()->whereKey($id)->exists()) {
            throw new JSONException('未找到该店铺');
        }
        $lock = $this->acquireSyncLock($id);
        try {
            $list = \App\Model\Commodity::query()->where("shared_id", $id)->get();
            $logName = "sync_remote_item_{$id}";
            \Kernel\Util\Log::inst()->clear($logName);
            foreach ($list as $e) {
                try {
                    $state = $this->shared->syncRemoteItem($e->id);
                    if ($state) {
                        \Kernel\Util\Log::inst()->write(strip_tags($e->name) . " -> 同步成功", $logName);
                    } else {
                        \Kernel\Util\Log::inst()->write(strip_tags($e->name) . " -> 同步失败", $logName);
                    }
                } catch (\Throwable $x) {
                    $failure = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($x->getMessage())));
                    \Kernel\Util\Log::inst()->write(strip_tags($e->name) . " -> 同步失败:" . mb_substr($failure, 0, 200), $logName);
                }
            }
        } finally {
            $this->releaseSyncLock($lock);
        }
        return $this->json();
    }

    /**
     * @param int $id
     * @return array
     */
    public function getSyncRemoteLog(int $id): array
    {
        if ($id < 1 || !Shared::query()->whereKey($id)->exists()) {
            throw new JSONException('未找到该店铺');
        }
        $logName = "sync_remote_item_{$id}";
        $log = \Kernel\Util\Log::inst()->get($logName);
        return $this->json(data: ["log" => $log]);
    }


    /**
     * @param int $id
     * @return array
     */
    public function clearSyncRemoteLog(int $id): array
    {
        if ($id < 1 || !Shared::query()->whereKey($id)->exists()) {
            throw new JSONException('未找到该店铺');
        }
        $lock = $this->acquireSyncLock($id);
        try {
            $logName = "sync_remote_item_{$id}";
            \Kernel\Util\Log::inst()->clear($logName);
        } finally {
            $this->releaseSyncLock($lock);
        }
        return $this->json();
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $ids = $this->sharedIds($_POST['list'] ?? null);
        $count = DB::transaction(function () use ($ids): int {
            $stores = Shared::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($stores->count() !== count($ids)) {
                throw new JSONException('部分共享店铺不存在，请刷新页面后重试');
            }
            $deleted = 0;
            foreach ($stores as $store) {
                if (!$store->delete()) {
                    throw new JSONException('共享店铺移除失败，操作已回滚');
                }
                $deleted++;
            }
            return $deleted;
        });

        ManageLog::log($this->getManage(), "[店铺共享]删除操作，共计：{$count}，ID：" . implode(',', $ids));
        return $this->json(200, '（＾∀＾）移除成功');
    }
}
