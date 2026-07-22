<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Consts\Manage as ManageConst;
use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\Business;
use App\Model\Category;
use App\Model\Config as CFG;
use App\Model\ManageLog;
use App\Service\Email;
use App\Service\Query;
use App\Service\Sms;
use App\Util\Client;
use App\Util\Date;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;
use PHPMailer\PHPMailer\PHPMailer;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Config extends Manage
{

    private const SETTING_REQUEST_FIELDS = [
        'logo',
        'ip_get_mode',
        'closed_message',
        'background_mobile_url',
        'closed',
        'username_len',
        'user_theme',
        'user_mobile_theme',
        'user_center_theme',
        'user_center_mobile_theme',
        'background_url',
        'shop_name',
        'title',
        'description',
        'keywords',
        'registered_state',
        'registered_type',
        'registered_verification',
        'registered_phone_verification',
        'registered_email_verification',
        'login_verification',
        'forget_type',
        'notice',
        'trade_verification',
        'session_expire',
        'request_log',
        // The secret suffix keeps browser DEBUG output and RequestLogger from
        // exposing this security-sensitive route while it is being replaced.
        'admin_entrance_secret',
        'admin_entrance_clear',
    ];

    private const SETTING_BOOLEAN_FIELDS = [
        'closed',
        'registered_state',
        'registered_verification',
        'registered_phone_verification',
        'registered_email_verification',
        'login_verification',
        'trade_verification',
        'request_log',
    ];

    private const SMS_REQUEST_FIELDS = [
        'platform',
        'accessKeyId_secret',
        'accessKeySecret',
        'signName',
        'templateCode',
        'tencentSecretId',
        'tencentSecretKey',
        'tencentSdkAppId',
        'tencentSignName',
        'tencentTemplateId',
        'dxbao_username',
        'dxbao_password',
        'dxbao_template',
    ];

    private const EMAIL_REQUEST_FIELDS = [
        'smtp',
        'secure',
        'port',
        'username',
        'password',
    ];

    private const OTHER_REQUEST_FIELDS = [
        'callback_domain',
        'domain',
        'cname',
        'substation_display',
        'recharge_min',
        'recharge_max',
        'recharge_welfare',
        'recharge_welfare_config',
        'service_qq',
        'service_url',
        'cash_type_alipay',
        'cash_type_wechat',
        'cash_type_usdt',
        'cash_type_balance',
        'cash_cost',
        'cash_min',
        'default_category',
        'commodity_recommend',
        'commodity_name',
    ];

    private const OTHER_BOOLEAN_FIELDS = [
        'substation_display',
        'recharge_welfare',
        'cash_type_alipay',
        'cash_type_wechat',
        'cash_type_usdt',
        'cash_type_balance',
        'commodity_recommend',
    ];

    #[Inject]
    private Query $query;

    #[Inject]
    private Sms $sms;

    #[Inject]
    private Email $email;

    /**
     * @throws JSONException
     */
    private function settingString(array $post, string $key, int $maxLength, bool $required = false): string
    {
        if (!array_key_exists($key, $post) || (!is_scalar($post[$key]) && $post[$key] !== null)) {
            throw new JSONException('网站设置参数不完整，请刷新页面后重试');
        }
        $value = (string)($post[$key] ?? '');
        if (str_contains($value, "\0")) {
            throw new JSONException('网站设置内容包含不允许的字符');
        }
        if ($required && trim($value) === '') {
            throw new JSONException('网站设置必填项不能为空');
        }
        // config.value is a MySQL TEXT column (maximum 65,535 bytes). Keep
        // enough headroom for the row and reject oversized multibyte content.
        if (mb_strlen($value) > $maxLength || strlen($value) > 60000) {
            throw new JSONException('网站设置内容超出允许长度');
        }
        return $value;
    }

    /**
     * @throws JSONException
     */
    private function settingInteger(array $post, string $key, int $min, int $max): int
    {
        if (!array_key_exists($key, $post) || (!is_scalar($post[$key]) && $post[$key] !== null)) {
            throw new JSONException('网站设置参数不完整，请刷新页面后重试');
        }
        $value = filter_var($post[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new JSONException('网站设置数值超出允许范围');
        }
        return (int)$value;
    }

    /**
     * @throws JSONException
     */
    private function settingBoolean(array $post, string $key): int
    {
        if (!array_key_exists($key, $post)) {
            return 0;
        }
        if (!is_scalar($post[$key])) {
            throw new JSONException('网站设置开关参数不正确');
        }
        $value = filter_var($post[$key], FILTER_VALIDATE_INT);
        if ($value === false || !in_array($value, [0, 1], true)) {
            throw new JSONException('网站设置开关参数不正确');
        }
        return (int)$value;
    }

    /**
     * @throws JSONException
     */
    private function settingUrl(array $post, string $key): string
    {
        $value = trim($this->settingString($post, $key, 2048));
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            throw new JSONException('背景图片地址包含不允许的字符');
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new JSONException('背景图片地址仅支持站内路径或 HTTP/HTTPS 地址');
        }
        return $value;
    }

    /**
     * Validate an installed theme locally before the existing remote licence
     * check runs after the configuration batch has been persisted.
     * @throws JSONException
     */
    private function settingTheme(array $post, string $key, bool $allowFollow = false): string
    {
        $theme = trim($this->settingString($post, $key, 64, true));
        if ($allowFollow && $theme === '0') {
            return '0';
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $theme)
            || !is_dir(BASE_PATH . '/app/View/User/Theme/' . $theme)
            || !is_file(BASE_PATH . '/app/View/User/Theme/' . $theme . '/Config.php')) {
            throw new JSONException('所选网站模板未安装或已损坏');
        }
        return $theme;
    }

    /**
     * @throws JSONException
     */
    private function installFavicon(string $logo): void
    {
        if ($logo === '/favicon.ico') {
            return;
        }
        if (!preg_match('#^/assets/cache/general/image/[A-Za-z0-9._-]+\.(?:png|jpe?g|ico|webp|gif|bmp)$#i', $logo)) {
            throw new JSONException('LOGO 文件路径不正确，请重新上传');
        }
        $allowedDirectory = realpath(BASE_PATH . '/assets/cache/general/image');
        $source = realpath(BASE_PATH . '/' . ltrim($logo, '/'));
        if ($allowedDirectory === false || $source === false || !is_file($source)
            || !str_starts_with($source, $allowedDirectory . DIRECTORY_SEPARATOR)) {
            throw new JSONException('LOGO 文件不存在或不在允许目录');
        }
        $size = filesize($source);
        if ($size === false || $size > 10 * 1024 * 1024) {
            throw new JSONException('LOGO 文件大小不能超过 10MB');
        }

        try {
            $temporary = BASE_PATH . '/favicon.ico.setting-' . bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            throw new JSONException('无法创建安全的 LOGO 临时文件');
        }
        if (!copy($source, $temporary)) {
            throw new JSONException('LOGO 保存失败，请检查目录权限');
        }
        if (!rename($temporary, BASE_PATH . '/favicon.ico')) {
            @unlink($temporary);
            throw new JSONException('LOGO 保存失败，请检查目录权限');
        }
        // The uploaded source and its acg_upload record are intentionally kept.
        // A routine settings save must not physically delete an uploaded file.
    }

    /**
     * @throws JSONException
     */
    private function configPost(array $allowedFields, string $label): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException($label . '仅接受 POST 请求');
        }
        $post = $this->request->post(flags: Filter::NORMAL);
        if (!is_array($post)) {
            throw new JSONException($label . '参数不正确');
        }
        $unknownFields = array_diff(array_map('strval', array_keys($post)), $allowedFields);
        if ($unknownFields !== []) {
            throw new JSONException($label . '包含未允许的字段，请刷新页面后重试');
        }
        return $post;
    }

    /**
     * @throws JSONException
     */
    private function configString(
        array $post,
        string $key,
        int $maxLength,
        string $label,
        bool $required = false
    ): string {
        if (!array_key_exists($key, $post) || (!is_scalar($post[$key]) && $post[$key] !== null)) {
            throw new JSONException($label . '参数不完整，请刷新页面后重试');
        }
        $value = trim((string)($post[$key] ?? ''));
        if (str_contains($value, "\0")) {
            throw new JSONException($label . '包含不允许的字符');
        }
        if ($required && $value === '') {
            throw new JSONException($label . '不能为空');
        }
        if (mb_strlen($value) > $maxLength || strlen($value) > 60000) {
            throw new JSONException($label . '超出允许长度');
        }
        return $value;
    }

    /**
     * Secrets are deliberately accepted only from blank page fields. Blank (or
     * whitespace-only) input means preserve the value already stored server-side.
     * @throws JSONException
     */
    private function configSecret(array $post, string $key, int $maxLength, string $label): string
    {
        if (!array_key_exists($key, $post) || !is_scalar($post[$key])) {
            throw new JSONException($label . '参数不完整，请刷新页面后重试');
        }
        $value = (string)$post[$key];
        if (trim($value) === '') {
            return '';
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value)
            || mb_strlen($value) > $maxLength
            || strlen($value) > 4096) {
            throw new JSONException($label . '格式或长度不正确');
        }
        return $value;
    }

    /**
     * @throws JSONException
     */
    private function configInteger(array $post, string $key, int $min, int $max, string $label): int
    {
        if (!array_key_exists($key, $post) || !is_scalar($post[$key])) {
            throw new JSONException($label . '参数不完整，请刷新页面后重试');
        }
        $value = filter_var($post[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new JSONException($label . '超出允许范围');
        }
        return (int)$value;
    }

    /**
     * @throws JSONException
     */
    private function configBoolean(array $post, string $key, string $label): int
    {
        if (!array_key_exists($key, $post)) {
            return 0;
        }
        if (!is_scalar($post[$key])) {
            throw new JSONException($label . '参数不正确');
        }
        $value = filter_var($post[$key], FILTER_VALIDATE_INT);
        if ($value === false || !in_array($value, [0, 1], true)) {
            throw new JSONException($label . '参数不正确');
        }
        return (int)$value;
    }

    /**
     * @throws JSONException
     */
    private function configMoney(array $post, string $key, string $label): string
    {
        $value = $this->configString($post, $key, 16, $label, true);
        if (!preg_match('/^(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?$/', $value)) {
            throw new JSONException($label . '必须是 0 至 999999999.99 的金额，最多两位小数');
        }
        return $value;
    }

    /**
     * @throws JSONException
     */
    private function configHttpUrl(
        array $post,
        string $key,
        string $label,
        bool $allowLocal = false,
        bool $originOnly = false
    ): string
    {
        $value = $this->configString($post, $key, 2048, $label);
        if ($value === '') {
            return '';
        }
        if ($allowLocal && str_starts_with($value, '/') && !str_starts_with($value, '//')
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            return $value;
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($value, PHP_URL_HOST) === null
            || parse_url($value, PHP_URL_USER) !== null
            || parse_url($value, PHP_URL_PASS) !== null) {
            throw new JSONException($label . '仅支持不含账号密码的 HTTP/HTTPS 地址');
        }
        if ($originOnly && (
            !in_array((string)(parse_url($value, PHP_URL_PATH) ?? ''), ['', '/'], true)
            || parse_url($value, PHP_URL_QUERY) !== null
            || parse_url($value, PHP_URL_FRAGMENT) !== null
        )) {
            throw new JSONException($label . '只能填写协议、域名和可选端口，不能包含路径或参数');
        }
        return $originOnly ? rtrim($value, '/') : $value;
    }

    /**
     * @throws JSONException
     */
    private function configDomainList(array $post, string $key, string $label, bool $multiple): string
    {
        $value = $this->configString($post, $key, 2048, $label);
        if ($value === '') {
            return '';
        }
        $domains = $multiple ? explode(',', $value) : [$value];
        if (count($domains) > 50) {
            throw new JSONException($label . '数量不能超过 50 个');
        }
        $normalized = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '' || strlen($domain) > 253
                || !preg_match('/^(?:localhost|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?::[1-9]\d{0,4})?$/', $domain)) {
                throw new JSONException($label . '格式不正确，请填写域名，不要包含协议或路径');
            }
            if (preg_match('/:(\d+)$/', $domain, $port) && (int)$port[1] > 65535) {
                throw new JSONException($label . '端口必须在 1 至 65535 之间');
            }
            $normalized[] = $domain;
        }
        return implode(',', array_values(array_unique($normalized)));
    }

    /**
     * @throws JSONException
     */
    private function configWelfareRules(array $post): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $this->configString(
            $post,
            'recharge_welfare_config',
            10000,
            '充值赠送配置'
        )));
        if ($value === '') {
            return '';
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $value)), static fn(string $line): bool => $line !== ''));
        if (count($lines) > 100) {
            throw new JSONException('充值赠送配置最多支持 100 条规则');
        }
        $thresholds = [];
        foreach ($lines as $line) {
            if (!preg_match('/^(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?-(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?$/', $line)) {
                throw new JSONException('充值赠送配置规则应为“充值金额-赠送金额”，最多两位小数');
            }
            [$threshold, $gift] = explode('-', $line, 2);
            if ((float)$threshold <= 0 || (float)$gift <= 0) {
                throw new JSONException('充值赠送配置中的金额必须大于 0');
            }
            $thresholdKey = number_format((float)$threshold, 2, '.', '');
            if (isset($thresholds[$thresholdKey])) {
                throw new JSONException('充值赠送配置不能包含重复的充值金额');
            }
            $thresholds[$thresholdKey] = true;
        }
        return implode(PHP_EOL, $lines);
    }

    /**
     * @throws JSONException
     */
    private function normalizeDefaultCategory(array $post): string
    {
        $value = $this->configString($post, 'default_category', 32, '默认展开分类', true);
        if (in_array($value, ['0', 'recommend'], true)) {
            return $value;
        }
        if (!ctype_digit($value) || (int)$value < 1) {
            throw new JSONException('默认展开分类不存在、已停用或不属于主站');
        }
        return (string)(int)$value;
    }

    /**
     * The caller holds Config's file lock and an open database transaction.
     * Keep the category row locked through the guarded config write and commit.
     *
     * @throws JSONException
     */
    private function lockDefaultCategory(string $value): void
    {
        if (in_array($value, ['0', 'recommend'], true)) {
            return;
        }
        $category = Category::query()
            ->where('id', (int)$value)
            ->where('status', 1)
            ->where('owner', 0)
            ->lockForUpdate()
            ->first(['id']);
        if (!$category) {
            throw new JSONException('默认展开分类不存在、已停用或不属于主站');
        }
    }

    private function storedJsonConfig(string $key): array
    {
        try {
            $value = json_decode(CFG::get($key), true, 32, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @throws JSONException
     */
    private function encodeJsonConfig(array $config, string $label): string
    {
        try {
            return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new JSONException($label . '包含无法保存的字符');
        }
    }

    private function preserveSecret(array $stored, string $storedKey, string $incoming): string
    {
        return $incoming !== '' ? $incoming : (is_scalar($stored[$storedKey] ?? null) ? (string)$stored[$storedKey] : '');
    }

    /**
     * Atomically limit real test sends by login session, administrator and IP.
     * @throws JSONException
     */
    private function consumeTestSendQuota(string $channel): void
    {
        $directory = BASE_PATH . '/runtime/config-test-throttle';
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new JSONException('无法启用测试发送保护，请检查 runtime 目录权限');
        }
        $manageId = (int)($this->getManage()?->id ?? 0);
        $ip = Client::getAddress() ?: (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $sessionFingerprint = hash('sha256', (string)($_COOKIE[ManageConst::SESSION] ?? ''));
        $paths = [
            $directory . '/' . hash('sha256', 'session|' . $channel . '|' . $sessionFingerprint) . '.lock',
            $directory . '/' . hash('sha256', 'manage|' . $channel . '|' . $manageId) . '.lock',
            $directory . '/' . hash('sha256', 'ip|' . $channel . '|' . $ip) . '.lock',
        ];
        sort($paths, SORT_STRING); // deterministic lock order prevents deadlocks
        $handles = [];
        try {
            foreach ($paths as $path) {
                $handle = @fopen($path, 'c+');
                if (!$handle) {
                    throw new JSONException('无法启用测试发送保护，请稍后重试');
                }
                $handles[] = $handle;
                if (!flock($handle, LOCK_EX)) {
                    throw new JSONException('无法锁定测试发送状态，请稍后重试');
                }
            }

            $now = time();
            $records = [];
            foreach ($handles as $handle) {
                rewind($handle);
                $record = json_decode((string)stream_get_contents($handle), true);
                $count = 0;
                $reset = $now + 300;
                $last = 0;
                if (is_array($record) && (int)($record['reset'] ?? 0) > $now) {
                    $count = max(0, (int)($record['count'] ?? 0));
                    $reset = (int)$record['reset'];
                    $last = max(0, (int)($record['last'] ?? 0));
                }
                if ($last > 0 && $last + 30 > $now) {
                    throw new JSONException('测试发送过于频繁，请 30 秒后再试');
                }
                if ($count >= 3) {
                    throw new JSONException('测试发送次数过多，请稍后再试');
                }
                $records[] = ['count' => $count + 1, 'reset' => $reset, 'last' => $now];
            }

            foreach ($handles as $index => $handle) {
                $encoded = (string)json_encode($records[$index]);
                rewind($handle);
                $truncated = ftruncate($handle, 0);
                $written = $truncated ? fwrite($handle, $encoded) : false;
                if (!$truncated || $written !== strlen($encoded) || !fflush($handle)) {
                    throw new JSONException('无法记录测试发送状态，请稍后重试');
                }
            }
        } finally {
            foreach (array_reverse($handles) as $handle) {
                @flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     * @throws \Throwable
     */
    public function setting(Request $request): array
    {
        if (strtoupper($request->method()) !== 'POST') {
            throw new JSONException('网站设置仅接受 POST 请求');
        }
        $post = $request->post(flags: Filter::NORMAL);
        if (!is_array($post)) {
            throw new JSONException('网站设置参数不正确');
        }
        $unknownFields = array_diff(array_map('strval', array_keys($post)), self::SETTING_REQUEST_FIELDS);
        if ($unknownFields !== []) {
            throw new JSONException('网站设置包含未允许的字段，请刷新页面后重试');
        }

        $logo = trim($this->settingString($post, 'logo', 255, true));
        $ipGetMode = $this->settingInteger($post, 'ip_get_mode', 0, 8);
        $registeredType = $this->settingInteger($post, 'registered_type', 0, 2);
        $forgetType = $this->settingInteger($post, 'forget_type', 0, 1);
        $usernameLength = $this->settingInteger($post, 'username_len', 1, 64);
        $sessionExpire = $this->settingInteger($post, 'session_expire', 0, 31536000);
        if ($sessionExpire > 0 && $sessionExpire < 120) {
            throw new JSONException('会话保持时间必须为 0，或至少 120 秒');
        }

        $entranceInput = trim($this->settingString($post, 'admin_entrance_secret', 65));
        $clearEntrance = $this->settingBoolean($post, 'admin_entrance_clear');
        if ($clearEntrance === 1 && $entranceInput !== '') {
            throw new JSONException('不能同时设置新后台入口并关闭后台入口');
        }
        if ($clearEntrance === 1) {
            $entrance = '';
        } elseif ($entranceInput === '') {
            // The current entrance never leaves the server. Blank means keep it.
            $entrance = CFG::get('admin_entrance');
        } else {
            if (!preg_match('#^/?[A-Za-z0-9][A-Za-z0-9_-]{0,63}$#', $entranceInput)) {
                throw new JSONException('后台安全入口仅支持单段字母、数字、下划线和短横线');
            }
            $entranceName = strtolower(ltrim($entranceInput, '/'));
            if (in_array($entranceName, ['admin', 'user', 'shared', 'plugin', 'install', 'assets', 'index.php', 'favicon.ico'], true)) {
                throw new JSONException('后台安全入口不能使用系统保留地址');
            }
            $entrance = '/' . ltrim($entranceInput, '/');
        }

        $settings = [
            'closed_message' => $this->settingString($post, 'closed_message', 5000),
            'background_mobile_url' => $this->settingUrl($post, 'background_mobile_url'),
            'username_len' => $usernameLength,
            'user_theme' => $this->settingTheme($post, 'user_theme'),
            'user_mobile_theme' => $this->settingTheme($post, 'user_mobile_theme', true),
            'user_center_theme' => $this->settingTheme($post, 'user_center_theme'),
            'user_center_mobile_theme' => $this->settingTheme($post, 'user_center_mobile_theme', true),
            'background_url' => $this->settingUrl($post, 'background_url'),
            'shop_name' => trim($this->settingString($post, 'shop_name', 128, true)),
            'title' => trim($this->settingString($post, 'title', 256, true)),
            'description' => $this->settingString($post, 'description', 2000),
            'keywords' => $this->settingString($post, 'keywords', 1000),
            'registered_type' => $registeredType,
            'forget_type' => $forgetType,
            'notice' => $this->settingString($post, 'notice', 60000),
            'session_expire' => $sessionExpire,
            'admin_entrance' => $entrance,
        ];
        foreach (self::SETTING_BOOLEAN_FIELDS as $key) {
            $settings[$key] = $this->settingBoolean($post, $key);
        }

        // Validate every value before the first filesystem or database write.
        // Favicon and client-IP mode are filesystem state and cannot join the
        // database transaction. Run the failure-prone file work first; if the
        // later database commit fails these idempotent side effects may already
        // be visible and a retry is required, while all config rows stay atomic.
        $this->installFavicon($logo);
        try {
            Client::setClientMode($ipGetMode);
            clearstatcache(true, BASE_PATH . '/runtime/mode');
            $savedClientMode = @file_get_contents(BASE_PATH . '/runtime/mode');
            if ($savedClientMode === false || trim($savedClientMode) !== (string)$ipGetMode) {
                throw new RuntimeException('客户端 IP 获取方式写入失败');
            }
            CFG::putMany($settings);
        } catch (\Throwable $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        // Preserve the pre-existing theme/plugin licence validation semantics.
        // This is deliberately not exercised by mobile QA because it may update
        // plugin/theme state according to the remote licence response.
        _plugin_start($settings['user_theme'], true);
        ManageLog::log($this->getManage(), "修改了网站设置");
        return $this->json(200, '保存成功');
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function other(): array
    {
        $map = $this->configPost(self::OTHER_REQUEST_FIELDS, '其他设置');
        $rechargeMin = $this->configMoney($map, 'recharge_min', '单次最低充值金额');
        $rechargeMax = $this->configMoney($map, 'recharge_max', '单次最高充值金额');
        if ((float)$rechargeMin > 0 && (float)$rechargeMax > 0 && (float)$rechargeMin > (float)$rechargeMax) {
            throw new JSONException('单次最低充值金额不能高于单次最高充值金额');
        }

        $settings = [
            'callback_domain' => $this->configHttpUrl($map, 'callback_domain', '自定义支付回调域名', false, true),
            'domain' => $this->configDomainList($map, 'domain', '主站域名', true),
            'cname' => $this->configDomainList($map, 'cname', 'DNS-CNAME', false),
            'recharge_min' => $rechargeMin,
            'recharge_max' => $rechargeMax,
            'recharge_welfare_config' => $this->configWelfareRules($map),
            'service_qq' => $this->configString($map, 'service_qq', 128, '客服 QQ'),
            'service_url' => $this->configHttpUrl($map, 'service_url', '网页客服地址', true),
            'cash_cost' => $this->configMoney($map, 'cash_cost', '提现手续费'),
            'cash_min' => $this->configMoney($map, 'cash_min', '最低提现金额'),
            'default_category' => $this->normalizeDefaultCategory($map),
            'commodity_name' => $this->configString($map, 'commodity_name', 128, '推荐分类名称'),
        ];
        foreach (self::OTHER_BOOLEAN_FIELDS as $key) {
            $settings[$key] = $this->configBoolean($map, $key, '其他设置开关');
        }

        // Serialize validation with category deletion. Config acquires the file
        // lock first, opens a transaction, then this guard locks/revalidates the
        // category row before the compensated MyISAM batch is written. The file
        // lock remains held until the database transaction commits.
        try {
            CFG::putManyGuarded($settings, function () use ($settings): void {
                $this->lockDefaultCategory($settings['default_category']);
            });
        } catch (JSONException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        ManageLog::log($this->getManage(), "修改了其他设置");
        return $this->json(200, '保存成功');
    }


    /**
     * @return array
     * @throws RuntimeException
     */
    public function setSubstationDisplayList(): array
    {
        $userId = (int)$_POST['id'];
        $type = (int)$_POST['type'];
        $list = json_decode(CFG::get("substation_display_list"), true);
        if ($type == 0) {
            //添加过滤
            if (!in_array($userId, $list)) {
                $list[] = $userId;
            }
        } else {
            //解除过滤
            if (($key = array_search($userId, $list)) !== false) {
                unset($list[$key]);
                $list = array_values($list);
            }
        }

        ManageLog::log($this->getManage(), "修改了子站显示列表");
        CFG::put("substation_display_list", json_encode($list));
        return $this->json(200, "成功", $list);
    }

    /**
     * @throws JSONException
     */
    public function sms(): array
    {
        $map = $this->configPost(self::SMS_REQUEST_FIELDS, '短信设置');
        $stored = $this->storedJsonConfig('sms_config');
        $platform = $this->configInteger($map, 'platform', 0, 2, '短信平台');
        $config = [
            'platform' => $platform,
            'accessKeyId' => $this->preserveSecret(
                $stored,
                'accessKeyId',
                $this->configSecret($map, 'accessKeyId_secret', 256, 'AccessKeyId')
            ),
            'accessKeySecret' => $this->preserveSecret(
                $stored,
                'accessKeySecret',
                $this->configSecret($map, 'accessKeySecret', 512, 'AccessKeySecret')
            ),
            'signName' => $this->configString($map, 'signName', 128, '阿里云短信签名'),
            'templateCode' => $this->configString($map, 'templateCode', 128, '阿里云模板 CODE'),
            'tencentSecretId' => $this->preserveSecret(
                $stored,
                'tencentSecretId',
                $this->configSecret($map, 'tencentSecretId', 256, '腾讯云 SecretId')
            ),
            'tencentSecretKey' => $this->preserveSecret(
                $stored,
                'tencentSecretKey',
                $this->configSecret($map, 'tencentSecretKey', 512, '腾讯云 SecretKey')
            ),
            'tencentSdkAppId' => $this->configString($map, 'tencentSdkAppId', 32, '腾讯云 SDK AppId'),
            'tencentSignName' => $this->configString($map, 'tencentSignName', 128, '腾讯云短信签名'),
            'tencentTemplateId' => $this->configString($map, 'tencentTemplateId', 64, '腾讯云模板 ID'),
            'dxbao_username' => $this->configString($map, 'dxbao_username', 128, '短信宝账号'),
            'dxbao_password' => $this->preserveSecret(
                $stored,
                'dxbao_password',
                $this->configSecret($map, 'dxbao_password', 512, '短信宝密码')
            ),
            'dxbao_template' => $this->configString($map, 'dxbao_template', 2000, '短信宝模板'),
        ];

        $required = match ($platform) {
            0 => ['accessKeyId', 'accessKeySecret', 'signName', 'templateCode'],
            1 => ['tencentSecretId', 'tencentSecretKey', 'tencentSdkAppId', 'tencentSignName', 'tencentTemplateId'],
            2 => ['dxbao_username', 'dxbao_password', 'dxbao_template'],
        };
        foreach ($required as $key) {
            if (trim((string)$config[$key]) === '') {
                throw new JSONException('当前短信平台配置不完整，请补充必填项');
            }
        }
        if ($platform === 0 && !preg_match('/^[A-Za-z0-9_-]+$/', $config['templateCode'])) {
            throw new JSONException('阿里云模板 CODE 格式不正确');
        }
        if ($platform === 1
            && (!ctype_digit($config['tencentSdkAppId']) || !ctype_digit($config['tencentTemplateId']))) {
            throw new JSONException('腾讯云 SDK AppId 和模板 ID 必须是数字');
        }
        if ($platform === 2 && !str_contains($config['dxbao_template'], '{code}')) {
            throw new JSONException('短信宝模板必须包含 {code} 验证码占位符');
        }

        try {
            CFG::put('sms_config', $this->encodeJsonConfig($config, '短信设置'));
        } catch (JSONException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        ManageLog::log($this->getManage(), "修改了短信配置");
        return $this->json(200, '保存成功');
    }

    /**
     * @throws JSONException
     */
    public function email(): array
    {
        $map = $this->configPost(self::EMAIL_REQUEST_FIELDS, '邮箱设置');
        $stored = $this->storedJsonConfig('email_config');
        $smtp = $this->configString($map, 'smtp', 253, 'SMTP 服务器', true);
        $smtpAddress = trim($smtp, '[]');
        if (filter_var($smtpAddress, FILTER_VALIDATE_IP) === false
            && !preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $smtp)) {
            throw new JSONException('SMTP 服务器应为有效的主机名或 IP 地址');
        }
        $config = [
            'smtp' => $smtp,
            'secure' => $this->configInteger($map, 'secure', 0, 1, 'SMTP 加密方式'),
            'port' => $this->configInteger($map, 'port', 1, 65535, 'SMTP 端口'),
            'username' => $this->configString($map, 'username', 320, 'SMTP 用户名', true),
            'password' => $this->preserveSecret(
                $stored,
                'password',
                $this->configSecret($map, 'password', 1024, 'SMTP 授权码')
            ),
        ];
        if ($config['password'] === '') {
            throw new JSONException('SMTP 授权码尚未配置，请输入授权码');
        }

        try {
            CFG::put('email_config', $this->encodeJsonConfig($config, '邮箱设置'));
        } catch (JSONException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        ManageLog::log($this->getManage(), "修改了邮件配置");
        return $this->json(200, '保存成功');
    }


    public function smsTest(): array
    {
        $map = $this->configPost(['phone'], '测试短信');
        $phone = $this->configString($map, 'phone', 20, '手机号', true);
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            throw new JSONException('请输入正确的国内手机号');
        }
        $this->consumeTestSendQuota('sms');
        $this->sms->sendCaptcha($phone, Sms::CAPTCHA_REGISTER);

        ManageLog::log($this->getManage(), "测试了短信发送");
        return $this->json(200, "短信发送成功");
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function emailTest(): array
    {
        $map = $this->configPost(['email'], '测试邮件');
        $address = $this->configString($map, 'email', 320, '邮箱地址', true);
        if (!PHPMailer::validateAddress($address)) {
            throw new JSONException('请输入正确的邮箱地址');
        }
        $this->consumeTestSendQuota('email');
        $shopName = CFG::get("shop_name");
        $result = $this->email->send($address, $shopName . "-手动测试邮件", '测试邮件，发送时间：' . Date::current());
        if (!$result) {
            throw new JSONException("发送失败");
        }
        ManageLog::log($this->getManage(), "测试了邮件发送");
        return $this->json(200, "成功!");
    }

    /**
     * @return array
     */
    public function getBusiness(): array
    {
        $get = new Get(Business::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with(['user' => function (Relation $relation) {
                $relation->with(['businessLevel'])->select(["id", "business_level", "username", "avatar"]);
            }]);
        });
        return $this->json(data: $data);
    }
}
