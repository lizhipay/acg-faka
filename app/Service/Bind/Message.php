<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Model\Manage;
use App\Model\ManageLog;
use App\Model\Config;
use App\Model\SystemMessage;
use App\Model\Upload as UploadModel;
use App\Model\User;
use App\Model\UserGroup;
use App\Model\UserMessage;
use App\Util\Date;
use App\Util\Throttle;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Util\File;

class Message implements \App\Service\Message
{
    private const MAX_TITLE_CHARACTERS = 100;
    private const MAX_CONTENT_BYTES = 102400;
    private const MAX_CONTENT_IMAGES = 8;
    private const MAX_IMAGE_BYTES = 10485760;
    private const USER_PAGE_SIZE = 10;

    #[Inject]
    private \App\Service\Upload $uploadService;

    #[Inject]
    private \App\Service\Email $emailService;

    private ?\HTMLPurifier $purifier = null;

    public function ready(): bool
    {
        try {
            $schema = DB::schema();
            return $schema->hasTable('system_message') && $schema->hasTable('user_message');
        } catch (\Throwable) {
            return false;
        }
    }

    private function requireReady(): void
    {
        if (!$this->ready()) {
            throw new JSONException('消息数据库尚未升级，请先完成 3.5.1 数据库升级');
        }
    }

    public function emailAvailable(): bool
    {
        try {
            $config = json_decode(Config::get('email_config'), true);
            if (!is_array($config)) {
                return false;
            }

            foreach (['smtp', 'port', 'username', 'password'] as $key) {
                if (!array_key_exists($key, $config) || !is_scalar($config[$key]) || trim((string)$config[$key]) === '') {
                    return false;
                }
            }

            $port = (string)$config['port'];
            return preg_match('/^(?:[1-9][0-9]{0,4})$/D', $port) === 1
                && (int)$port >= 1
                && (int)$port <= 65535;
        } catch (\Throwable) {
            return false;
        }
    }

    private function page(array $filter): int
    {
        return max(1, (int)($filter['page'] ?? 1));
    }

    private function adminLimit(array $filter): int
    {
        $limit = (int)($filter['limit'] ?? 20);
        return max(1, min(100, $limit > 0 ? $limit : 20));
    }

    private function cleanTitle(string $title): string
    {
        if (function_exists('mb_check_encoding') && !mb_check_encoding($title, 'UTF-8')) {
            throw new JSONException('消息标题编码无效');
        }
        for ($round = 0; $round < 2; $round++) {
            $decoded = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $title) {
                break;
            }
            $title = $decoded;
        }
        $title = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($title)));
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE_CHARACTERS) {
            throw new JSONException('消息标题不能为空且不能超过 100 个字符');
        }
        return $title;
    }

    private function purifier(): \HTMLPurifier
    {
        if ($this->purifier) {
            return $this->purifier;
        }

        $cachePath = BASE_PATH . '/runtime/message-purifier';
        if (!is_dir($cachePath) && !mkdir($cachePath, 0755, true) && !is_dir($cachePath)) {
            throw new JSONException('消息内容安全组件初始化失败');
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cachePath);
        $config->set('Cache.SerializerPermissions', 0755);
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,del,blockquote,ul,ol,li,h1,h2,h3,h4,h5,h6,hr,pre,code[class],a[href|title|target|rel],img[src|alt|title|width|height],table,thead,tbody,tr,th,td');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('URI.DisableExternalResources', false);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        $config->set('Attr.EnableID', false);
        $this->purifier = new \HTMLPurifier($config);
        return $this->purifier;
    }

    /**
     * @return string[]
     */
    private function imageSources(string $html): array
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $tags);
        $sources = [];
        foreach ($tags[0] ?? [] as $tag) {
            if (!preg_match('/\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', (string)$tag, $source)) {
                throw new JSONException('消息图片缺少有效地址');
            }
            $value = $source[1] !== '' ? $source[1] : ($source[2] !== '' ? $source[2] : ($source[3] ?? ''));
            $sources[] = html_entity_decode(trim((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $sources;
    }

    private function isMessageImagePath(string $path): bool
    {
        return (bool)preg_match('#^/assets/cache/general/message/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)$#i', $path);
    }

    private function cleanContent(string $content): string
    {
        $content = trim($content);
        if ($content === '' || strlen($content) > self::MAX_CONTENT_BYTES || (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8'))) {
            throw new JSONException('消息内容不能为空且不能超过 100KB');
        }

        $rawSources = $this->imageSources($content);
        if (count($rawSources) > self::MAX_CONTENT_IMAGES) {
            throw new JSONException('消息内容最多插入 8 张图片');
        }
        foreach ($rawSources as $source) {
            if (!$this->isMessageImagePath($source)) {
                throw new JSONException('消息图片必须通过消息图片上传功能添加');
            }
        }

        $safe = trim($this->purifier()->purify($content));
        if ($safe === '' || strlen($safe) > self::MAX_CONTENT_BYTES) {
            throw new JSONException('消息内容为空或净化后超过 100KB');
        }

        $safeSources = $this->imageSources($safe);
        if (count($safeSources) > self::MAX_CONTENT_IMAGES) {
            throw new JSONException('消息内容最多插入 8 张图片');
        }
        foreach ($safeSources as $source) {
            if (!$this->isMessageImagePath($source)) {
                throw new JSONException('消息图片必须通过消息图片上传功能添加');
            }
        }
        if (array_diff(array_unique($rawSources), array_unique($safeSources))) {
            throw new JSONException('消息图片安全检查失败，请重新上传');
        }

        $paths = array_values(array_unique($safeSources));
        if ($paths) {
            $uploads = UploadModel::query()
                ->whereNull('user_id')
                ->where('type', 'message')
                ->whereIn('path', $paths)
                ->lockForUpdate()
                ->get(['id', 'path']);
            $invalid = $uploads->contains(static function (UploadModel $upload): bool {
                $file = BASE_PATH . (string)$upload->path;
                return !is_file($file) || !@getimagesize($file);
            });
            if ($uploads->count() !== count($paths) || $invalid) {
                throw new JSONException('消息图片无效或不属于消息管理');
            }
        }

        $plain = trim((string)preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $safe)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));
        if ($plain === '' && !$safeSources) {
            throw new JSONException('请填写消息内容或插入图片');
        }

        return $safe;
    }

    private function summary(string $content): string
    {
        $content = (string)preg_replace('/<img\b[^>]*>/i', ' [图片] ', $content);
        for ($round = 0; $round < 2; $round++) {
            $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $content) {
                break;
            }
            $content = $decoded;
        }
        $plain = trim((string)preg_replace('/\s+/u', ' ', strip_tags($content)));
        return mb_substr($plain !== '' ? $plain : '[图片]', 0, 180);
    }

    private function cleanJumpUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return null;
        }
        if (strlen($url) > 2048 || (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8')) || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw new JSONException('点击跳转地址无效');
        }
        if (preg_match('#^/(?!/)#', $url)) {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new JSONException('点击跳转地址仅支持站内地址或 HTTP(S) 地址');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new JSONException('点击跳转地址仅支持站内地址或 HTTP(S) 地址');
        }
        return $url;
    }

    private function sendEmailFlag(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }
        throw new JSONException('发送邮件通知参数无效');
    }

    private function sourceName(mixed $source): string
    {
        if (!is_string($source)) {
            throw new JSONException('消息来源无效');
        }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($source, 'UTF-8')) {
            throw new JSONException('消息来源编码无效');
        }
        for ($round = 0; $round < 2; $round++) {
            $decoded = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $source) {
                break;
            }
            $source = $decoded;
        }
        $source = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($source)));
        return mb_substr($source !== '' ? $source : '系统通知', 0, 64);
    }

    private function siteBaseUrl(): ?string
    {
        $candidates = [];
        try {
            $candidates[] = trim(Config::get('callback_domain'));
        } catch (\Throwable) {
            // 当前请求地址仍可作为邮件中的站点地址。
        }

        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            $https = strtolower((string)($_SERVER['HTTPS'] ?? '')) === 'on';
            $scheme = $https ? 'https' : strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? 'http'));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $scheme = 'http';
            }
            $candidates[] = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        foreach ($candidates as $candidate) {
            $candidate = rtrim(trim((string)$candidate), '/');
            if ($candidate === '' || strlen($candidate) > 2048 || preg_match('/[\x00-\x20\x7F<>"\'\\\\]/', $candidate)) {
                continue;
            }
            $parts = parse_url($candidate);
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
                continue;
            }
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = (string)($parts['host'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                continue;
            }
            $port = isset($parts['port']) ? (int)$parts['port'] : null;
            if ($port !== null && ($port < 1 || $port > 65535)) {
                continue;
            }
            $origin = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
            if (filter_var($origin, FILTER_VALIDATE_URL) !== false) {
                return $origin;
            }
        }
        return null;
    }

    private function emailHtml(SystemMessage $message): string
    {
        $baseUrl = $this->siteBaseUrl();
        $content = (string)$message->content;
        if ($baseUrl !== null) {
            $content = (string)preg_replace_callback(
                '#(<img\b[^>]*\bsrc\s*=\s*)(["\'])(/assets/cache/general/message/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp))\2#i',
                static fn(array $match): string => $match[1] . $match[2]
                    . htmlspecialchars($baseUrl . $match[3], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
                    . $match[2],
                $content
            );
        }

        $jumpUrl = $message->jump_url === null ? null : (string)$message->jump_url;
        if ($jumpUrl !== null && $baseUrl !== null && preg_match('#^/(?!/)#', $jumpUrl)) {
            $jumpUrl = $baseUrl . $jumpUrl;
        }

        try {
            $shopName = trim(strip_tags(Config::get('shop_name')));
        } catch (\Throwable) {
            $shopName = '';
        }
        $shopName = $shopName !== '' ? $shopName : '网站消息';
        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
        $button = $jumpUrl === null ? '' : '<p style="margin:28px 0 0"><a href="' . $escape($jumpUrl)
            . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:11px 22px;border-radius:10px;background:#6750a4;color:#fff;text-decoration:none;font-weight:600">前往地址</a></p>';

        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f5f3f8;color:#27242d;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif">'
            . '<div style="max-width:680px;margin:0 auto;padding:28px;background:#fff;border:1px solid #e8e1ee;border-radius:18px">'
            . '<div style="margin-bottom:8px;color:#6750a4;font-size:13px;font-weight:700">' . $escape($shopName) . '</div>'
            . '<h1 style="margin:0 0 8px;font-size:22px;line-height:1.45">' . $escape((string)$message->title) . '</h1>'
            . '<div style="margin-bottom:22px;color:#77717d;font-size:13px">' . $escape((string)$message->create_time) . '</div>'
            . '<div style="font-size:15px;line-height:1.75;overflow-wrap:anywhere">' . $content . '</div>'
            . $button
            . '</div></body></html>';
    }

    /**
     * @param string[] $emails
     * @return array{sent: int, failed: int}
     */
    private function sendEmailBatch(array $emails, string $title, string $content): array
    {
        $total = count($emails);
        if ($total === 0) {
            return ['sent' => 0, 'failed' => 0];
        }

        if (is_callable([$this->emailService, 'sendMany'])) {
            try {
                /** @phpstan-ignore-next-line Optional extension method for compatible mail services. */
                $result = $this->emailService->sendMany($emails, $title, $content);
                if (is_array($result) && (array_key_exists('sent', $result) || array_key_exists('failed', $result))) {
                    if (array_key_exists('sent', $result)) {
                        $sent = max(0, min($total, (int)$result['sent']));
                        return ['sent' => $sent, 'failed' => $total - $sent];
                    }
                    $failed = max(0, min($total, (int)$result['failed']));
                    return ['sent' => $total - $failed, 'failed' => $failed];
                }
                if (is_array($result)) {
                    $sent = count(array_filter($result, static fn(mixed $value): bool => $value === true));
                    return ['sent' => min($total, $sent), 'failed' => max(0, $total - $sent)];
                }
                if (is_int($result)) {
                    $sent = max(0, min($total, $result));
                    return ['sent' => $sent, 'failed' => $total - $sent];
                }
                if (is_bool($result)) {
                    return ['sent' => $result ? $total : 0, 'failed' => $result ? 0 : $total];
                }
                return ['sent' => 0, 'failed' => $total];
            } catch (\Throwable) {
                return ['sent' => 0, 'failed' => $total];
            }
        }

        $sent = 0;
        foreach ($emails as $email) {
            try {
                if ($this->emailService->send($email, $title, $content)) {
                    $sent++;
                }
            } catch (\Throwable) {
                // 单个邮箱发送失败不能影响其他收件人，也不能回滚站内消息。
            }
        }
        return ['sent' => $sent, 'failed' => $total - $sent];
    }

    /**
     * @return array{enabled: bool, requested: int, eligible: int, sent: int, failed: int, skipped: int}
     */
    private function sendMessageEmail(SystemMessage $message, bool $requested): array
    {
        $stats = [
            'enabled' => $requested,
            'requested' => $requested ? (int)$message->recipient_count : 0,
            'eligible' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
        if (!$requested) {
            return $stats;
        }

        try {
            $html = $this->emailHtml($message);
            DB::table('user_message AS receipt')
                ->join('user AS recipient', 'recipient.id', '=', 'receipt.user_id')
                ->where('receipt.message_id', (int)$message->id)
                ->select(['receipt.id AS receipt_id', 'recipient.email'])
                ->chunkById(200, function ($rows) use (&$stats, $message, $html): bool {
                    $emails = [];
                    foreach ($rows as $row) {
                        if ($stats['eligible'] + $stats['skipped'] + count($emails) >= $stats['requested']) {
                            break;
                        }
                        $email = trim((string)($row->email ?? ''));
                        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                            $stats['skipped']++;
                            continue;
                        }
                        $emails[] = $email;
                    }

                    if ($emails) {
                        $batch = $this->sendEmailBatch($emails, (string)$message->title, $html);
                        $stats['eligible'] += count($emails);
                        $stats['sent'] += $batch['sent'];
                        $stats['failed'] += $batch['failed'];
                    }
                    return $stats['eligible'] + $stats['skipped'] < $stats['requested'];
                }, 'receipt.id', 'receipt_id');

            // 收件快照在邮件发送前若因用户删除而减少，归入跳过人数。
            $stats['skipped'] += max(0, $stats['requested'] - $stats['eligible'] - $stats['skipped']);
        } catch (\Throwable) {
            // 站内消息已经提交；尚未处理的收件人按邮件失败计，保证统计闭合。
            $remaining = max(0, $stats['requested'] - $stats['eligible'] - $stats['skipped']);
            $stats['eligible'] += $remaining;
            $stats['failed'] += $remaining;
        }
        return $stats;
    }

    public function sendToUser(
        int $userId,
        string $title,
        string $content,
        ?string $jumpUrl = null,
        array $options = []
    ): array {
        $this->requireReady();
        if ($userId <= 0) {
            throw new JSONException('指定用户不存在或状态异常');
        }
        if (array_key_exists('source', $options) && !is_string($options['source'])) {
            throw new JSONException('消息来源无效');
        }
        $source = $this->sourceName((string)($options['source'] ?? '系统通知'));
        $sendEmail = array_key_exists('send_email', $options)
            ? $this->sendEmailFlag($options['send_email'])
            : false;
        if ($sendEmail && !$this->emailAvailable()) {
            throw new JSONException('邮件功能尚未配置完整，无法发送邮件通知');
        }

        $title = $this->cleanTitle($title);
        $jumpUrl = $this->cleanJumpUrl((string)($jumpUrl ?? ''));
        $message = DB::transaction(function () use ($userId, $title, $content, $jumpUrl, $source): SystemMessage {
            $user = User::query()->where('status', 1)->lockForUpdate()->find($userId, ['id', 'username']);
            if (!$user) {
                throw new JSONException('指定用户不存在或状态异常');
            }
            $safeContent = $this->cleanContent($content);
            $date = Date::current();

            $message = new SystemMessage();
            $message->audience_type = SystemMessage::AUDIENCE_USER;
            $message->audience_id = (int)$user->id;
            $message->audience_name = (string)$user->username;
            $message->title = $title;
            $message->content = $safeContent;
            $message->summary = $this->summary($safeContent);
            $message->jump_url = $jumpUrl;
            $message->recipient_count = 1;
            $message->created_by = null;
            $message->updated_by = null;
            $message->manage_name = $source;
            $message->update_manage_name = $source;
            $message->create_time = $date;
            $message->update_time = $date;
            $message->save();

            $receipt = new UserMessage();
            $receipt->message_id = (int)$message->id;
            $receipt->user_id = (int)$user->id;
            $receipt->read_time = null;
            $receipt->create_time = $date;
            $receipt->save();
            return $message;
        });

        $result = $this->adminItem($message, true);
        $result['email'] = $this->sendMessageEmail($message, $sendEmail);
        return $result;
    }

    private function manageName(Manage $manage): string
    {
        return mb_substr(strip_tags((string)($manage->nickname ?: $manage->email ?: '管理员')), 0, 64);
    }

    private function safeManageLog(Manage $manage, string $content): void
    {
        try {
            ManageLog::log($manage, $content);
        } catch (\Throwable) {
            try {
                @error_log('消息管理审计日志写入失败，管理员ID：' . (int)$manage->id);
            } catch (\Throwable) {
                // 审计表是 MyISAM；核心事务或文件登记成功后，日志失败不能改变接口结果。
            }
        }
    }

    private function decimalInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > 4294967295) {
                throw new JSONException($field . '必须是有效的十进制整数');
            }
            return $value;
        }
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            throw new JSONException($field . '必须是有效的十进制整数');
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        if (strlen($normalized) > 10 || (strlen($normalized) === 10 && strcmp($normalized, '4294967295') > 0)) {
            throw new JSONException($field . '必须是有效的十进制整数');
        }
        return (int)$normalized;
    }

    /**
     * @return array{0: Builder, 1: string}
     */
    private function recipientScope(int $audienceType, int $audienceId): array
    {
        if (!in_array($audienceType, [
            SystemMessage::AUDIENCE_ALL,
            SystemMessage::AUDIENCE_GROUP,
            SystemMessage::AUDIENCE_USER,
        ], true)) {
            throw new JSONException('请选择有效的接收范围');
        }

        $query = User::query()->where('status', 1);
        if ($audienceType === SystemMessage::AUDIENCE_ALL) {
            return [$query, '全体用户'];
        }

        if ($audienceId <= 0) {
            throw new JSONException($audienceType === SystemMessage::AUDIENCE_GROUP ? '请选择会员等级' : '请选择指定用户');
        }

        if ($audienceType === SystemMessage::AUDIENCE_USER) {
            $user = User::query()->where('status', 1)->find($audienceId, ['id', 'username']);
            if (!$user) {
                throw new JSONException('指定用户不存在或状态异常');
            }
            return [$query->where('id', $audienceId), (string)$user->username];
        }

        $groups = UserGroup::query()->orderBy('recharge', 'desc')->get(['id', 'name', 'recharge']);
        $selected = null;
        $upperRecharge = null;
        foreach ($groups as $index => $group) {
            if ((int)$group->id === $audienceId) {
                $selected = $group;
                $upperRecharge = $index > 0 ? (string)$groups[$index - 1]->recharge : null;
                break;
            }
        }
        if (!$selected) {
            throw new JSONException('会员等级不存在');
        }

        $query->where('recharge', '>=', (string)$selected->recharge);
        if ($upperRecharge !== null) {
            $query->where('recharge', '<', $upperRecharge);
        }
        return [$query, (string)$selected->name];
    }

    private function adminItem(SystemMessage $message, bool $withContent = false): array
    {
        $item = [
            'id' => (int)$message->id,
            'title' => (string)$message->title,
            'summary' => (string)$message->summary,
            'audience_type' => (int)$message->audience_type,
            'audience_id' => $message->audience_id === null ? null : (int)$message->audience_id,
            'audience_name' => (string)$message->audience_name,
            'recipient_count' => (int)$message->recipient_count,
            'jump_url' => $message->jump_url === null ? null : (string)$message->jump_url,
            'create_time' => (string)$message->create_time,
            'update_time' => (string)$message->update_time,
            'manage_name' => (string)$message->manage_name,
            'update_manage_name' => (string)$message->update_manage_name,
        ];
        if ($withContent) {
            $item['content'] = (string)$message->content;
        }
        return $item;
    }

    private function userItem(UserMessage $receipt, bool $withContent = false): array
    {
        $message = $receipt->message;
        if (!$message) {
            throw new JSONException('消息内容不存在');
        }
        $item = [
            'id' => (int)$receipt->id,
            'message_id' => (int)$message->id,
            'title' => (string)$message->title,
            'summary' => (string)$message->summary,
            'jump_url' => $message->jump_url === null ? null : (string)$message->jump_url,
            'create_time' => (string)$message->create_time,
            'update_time' => (string)$message->update_time,
            'read_time' => $receipt->read_time === null ? null : (string)$receipt->read_time,
        ];
        if ($withContent) {
            $item['content'] = (string)$message->content;
        }
        return $item;
    }

    public function adminData(array $filter): array
    {
        $this->requireReady();
        $query = SystemMessage::query();

        $keyword = trim(strip_tags((string)($filter['keyword'] ?? $filter['title'] ?? '')));
        if ($keyword !== '') {
            $query->where('title', 'like', '%' . $keyword . '%');
        }
        $audienceType = $filter['equal-audience_type'] ?? $filter['audience_type'] ?? '';
        if ($audienceType !== '' && $audienceType !== null && is_scalar($audienceType) && in_array((int)$audienceType, [0, 1, 2], true)) {
            $query->where('audience_type', (int)$audienceType);
        }
        $start = (string)($filter['betweenStart-create_time'] ?? $filter['create_time_start'] ?? '');
        if ($start !== '' && strtotime($start) !== false) {
            $query->where('create_time', '>=', date('Y-m-d H:i:s', (int)strtotime($start)));
        }
        $end = (string)($filter['betweenEnd-create_time'] ?? $filter['create_time_end'] ?? '');
        if ($end !== '' && strtotime($end) !== false) {
            $query->where('create_time', '<=', date('Y-m-d H:i:s', (int)strtotime($end)));
        }

        $count = (int)(clone $query)->count();
        $page = $this->page($filter);
        $limit = $this->adminLimit($filter);
        $list = (clone $query)
            ->orderBy('id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(fn(SystemMessage $message) => $this->adminItem($message))
            ->values()
            ->all();

        return ['list' => $list, 'total' => $count, 'count' => $count];
    }

    public function adminDetail(int $id): array
    {
        $this->requireReady();
        $message = SystemMessage::query()->find($id);
        if (!$message) {
            throw new JSONException('消息不存在');
        }
        return $this->adminItem($message, true);
    }

    public function save(Manage $manage, array $map): array
    {
        $this->requireReady();
        foreach (['id', 'title', 'content', 'jump_url', 'audience_type', 'audience_id', 'group_id', 'user_id', 'send_email'] as $key) {
            if (array_key_exists($key, $map) && $map[$key] !== null && !is_scalar($map[$key])) {
                throw new JSONException('消息表单参数无效');
            }
        }
        $integers = [];
        $integerLabels = [
            'id' => '消息ID',
            'audience_type' => '接收范围',
            'audience_id' => '接收对象ID',
            'group_id' => '会员等级ID',
            'user_id' => '用户ID',
        ];
        foreach ($integerLabels as $key => $label) {
            if (array_key_exists($key, $map)) {
                $integers[$key] = $this->decimalInteger($map[$key], $label);
            }
        }

        $id = $integers['id'] ?? 0;
        $hasSendEmail = array_key_exists('send_email', $map);
        $sendEmail = $hasSendEmail ? $this->sendEmailFlag($map['send_email']) : false;
        if ($id > 0 && $hasSendEmail) {
            throw new JSONException('编辑消息不能重复发送邮件通知');
        }
        if ($sendEmail && !$this->emailAvailable()) {
            throw new JSONException('邮件功能尚未配置完整，无法发送邮件通知');
        }
        $title = $this->cleanTitle((string)($map['title'] ?? ''));
        $rawContent = (string)($map['content'] ?? '');
        $jumpUrl = $this->cleanJumpUrl((string)($map['jump_url'] ?? ''));
        $manageName = $this->manageName($manage);

        if ($id > 0) {
            $message = DB::transaction(function () use ($id, $title, $rawContent, $jumpUrl, $manage, $manageName) {
                $message = SystemMessage::query()->lockForUpdate()->find($id);
                if (!$message) {
                    throw new JSONException('消息不存在');
                }
                $content = $this->cleanContent($rawContent);
                $message->title = $title;
                $message->content = $content;
                $message->summary = $this->summary($content);
                $message->jump_url = $jumpUrl;
                $message->updated_by = (int)$manage->id;
                $message->update_manage_name = $manageName;
                $message->update_time = Date::current();
                $message->save();
                return $message;
            });
            $this->safeManageLog($manage, "编辑了消息(#{$message->id})");
            $result = $this->adminItem($message, true);
            $result['email'] = $this->sendMessageEmail($message, false);
            return $result;
        }

        if (!array_key_exists('audience_type', $integers)) {
            throw new JSONException('请选择接收范围');
        }
        $audienceType = $integers['audience_type'];
        if (!in_array($audienceType, [
            SystemMessage::AUDIENCE_ALL,
            SystemMessage::AUDIENCE_GROUP,
            SystemMessage::AUDIENCE_USER,
        ], true)) {
            throw new JSONException('请选择有效的接收范围');
        }

        $audienceId = $integers['audience_id'] ?? 0;
        if ($audienceType === SystemMessage::AUDIENCE_GROUP) {
            $groupId = $integers['group_id'] ?? 0;
            if ($audienceId > 0 && $groupId > 0 && $audienceId !== $groupId) {
                throw new JSONException('接收对象与会员等级不一致');
            }
            $audienceId = $audienceId > 0 ? $audienceId : $groupId;
        } elseif ($audienceType === SystemMessage::AUDIENCE_USER) {
            $userId = $integers['user_id'] ?? 0;
            if ($audienceId > 0 && $userId > 0 && $audienceId !== $userId) {
                throw new JSONException('接收对象与指定用户不一致');
            }
            $audienceId = $audienceId > 0 ? $audienceId : $userId;
        } elseif ($audienceId > 0 || ($integers['group_id'] ?? 0) > 0 || ($integers['user_id'] ?? 0) > 0) {
            throw new JSONException('全体用户消息不能指定会员等级或用户');
        }

        $message = DB::transaction(function () use ($title, $rawContent, $jumpUrl, $manage, $manageName, $audienceType, $audienceId) {
            [$recipients, $audienceName] = $this->recipientScope($audienceType, $audienceId);
            $content = $this->cleanContent($rawContent);
            $date = Date::current();

            $message = new SystemMessage();
            $message->audience_type = $audienceType;
            $message->audience_id = $audienceType === SystemMessage::AUDIENCE_ALL ? null : $audienceId;
            $message->audience_name = $audienceName;
            $message->title = $title;
            $message->content = $content;
            $message->summary = $this->summary($content);
            $message->jump_url = $jumpUrl;
            $message->recipient_count = 0;
            $message->created_by = (int)$manage->id;
            $message->updated_by = (int)$manage->id;
            $message->manage_name = $manageName;
            $message->update_manage_name = $manageName;
            $message->create_time = $date;
            $message->update_time = $date;
            $message->save();

            $select = (clone $recipients)
                ->selectRaw('? AS message_id, id AS user_id, ? AS create_time', [(int)$message->id, $date])
                ->getQuery();
            $count = DB::table('user_message')->insertUsing(
                ['message_id', 'user_id', 'create_time'],
                $select
            );
            if ($count <= 0) {
                throw new JSONException('当前接收范围没有正常用户，消息未发送');
            }
            $message->recipient_count = $count;
            $message->save();
            return $message;
        });

        $this->safeManageLog($manage, "发送了消息(#{$message->id})，接收人数：{$message->recipient_count}");
        $result = $this->adminItem($message, true);
        $result['email'] = $this->sendMessageEmail($message, $sendEmail);
        return $result;
    }

    public function adminDelete(Manage $manage, array $ids): array
    {
        $this->requireReady();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0))), 0, 500);
        if (!$ids) {
            throw new JSONException('请选择要删除的消息');
        }

        $count = DB::transaction(function () use ($ids): int {
            $existing = SystemMessage::query()->whereIn('id', $ids)->lockForUpdate()->pluck('id')->all();
            if (!$existing) {
                throw new JSONException('消息不存在');
            }
            return (int)SystemMessage::query()->whereIn('id', $existing)->delete();
        });
        $this->safeManageLog($manage, '删除了消息，共计：' . $count . ' 条');
        return ['count' => $count];
    }

    public function users(array $filter): array
    {
        $this->requireReady();
        $keyword = trim(strip_tags((string)($filter['keyword'] ?? $filter['q'] ?? $filter['search'] ?? '')));
        $limit = max(1, min(20, (int)($filter['limit'] ?? 20)));
        $query = User::query()->where('status', 1);
        if ($keyword !== '') {
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('username', 'like', '%' . $keyword . '%')
                    ->orWhere('email', 'like', '%' . $keyword . '%')
                    ->orWhere('phone', 'like', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $builder->orWhere('id', (int)$keyword);
                }
            });
        }

        $groups = UserGroup::query()->orderBy('recharge', 'desc')->get(['name', 'recharge']);
        $list = $query->orderBy('id', 'desc')->limit($limit)->get(['id', 'username', 'avatar', 'recharge'])
            ->map(function (User $user) use ($groups): array {
                $groupName = '';
                foreach ($groups as $group) {
                    if ((float)$user->recharge >= (float)$group->recharge) {
                        $groupName = (string)$group->name;
                        break;
                    }
                }
                return [
                    'id' => (int)$user->id,
                    'username' => (string)$user->username,
                    'avatar' => (string)($user->avatar ?? ''),
                    'group_name' => $groupName,
                ];
            })->values()->all();
        return ['list' => $list];
    }

    public function audienceCount(int $audienceType, int $audienceId = 0): array
    {
        $this->requireReady();
        [$recipients] = $this->recipientScope($audienceType, $audienceId);
        return ['count' => (int)$recipients->count()];
    }

    private function unreadCount(User $user): int
    {
        return (int)UserMessage::query()
            ->where('user_id', (int)$user->id)
            ->whereNull('read_time')
            ->count();
    }

    public function recent(User $user): array
    {
        if (!$this->ready()) {
            return ['count' => 0, 'list' => []];
        }
        $list = UserMessage::query()
            ->where('user_id', (int)$user->id)
            ->with('message')
            ->orderBy('id', 'desc')
            ->limit(6)
            ->get()
            ->map(fn(UserMessage $receipt) => $this->userItem($receipt))
            ->values()
            ->all();
        return ['count' => $this->unreadCount($user), 'list' => $list];
    }

    public function userData(User $user, array $filter): array
    {
        $this->requireReady();
        $query = UserMessage::query()->where('user_id', (int)$user->id);
        $status = $filter['equal-status'] ?? $filter['status'] ?? '';
        if (is_scalar($status) && (string)$status === '0') {
            $query->whereNull('read_time');
        } elseif (is_scalar($status) && (string)$status === '1') {
            $query->whereNotNull('read_time');
        }
        $keyword = trim(strip_tags((string)($filter['keyword'] ?? '')));
        if ($keyword !== '') {
            $query->whereHas('message', static function (Builder $builder) use ($keyword) {
                $builder->where('title', 'like', '%' . $keyword . '%');
            });
        }

        $count = (int)(clone $query)->count();
        $page = $this->page($filter);
        $list = (clone $query)
            ->with('message')
            ->orderBy('id', 'desc')
            ->offset(($page - 1) * self::USER_PAGE_SIZE)
            ->limit(self::USER_PAGE_SIZE)
            ->get()
            ->map(fn(UserMessage $receipt) => $this->userItem($receipt))
            ->values()
            ->all();
        return ['list' => $list, 'total' => $count, 'count' => $count];
    }

    public function userDetail(User $user, int $id): array
    {
        $this->requireReady();
        if ($id <= 0) {
            throw new JSONException('消息不存在');
        }

        return DB::transaction(function () use ($user, $id): array {
            $receipt = UserMessage::query()
                ->where('user_id', (int)$user->id)
                ->lockForUpdate()
                ->find($id);
            if (!$receipt) {
                throw new JSONException('消息不存在');
            }
            $message = SystemMessage::query()->find((int)$receipt->message_id);
            if (!$message) {
                throw new JSONException('消息内容不存在');
            }
            $receipt->setRelation('message', $message);
            $becameRead = $receipt->read_time === null;
            if ($becameRead) {
                $receipt->read_time = Date::current();
                $receipt->save();
            }
            return array_merge($this->userItem($receipt, true), [
                'became_read' => $becameRead,
                'unread_count' => $this->unreadCount($user),
            ]);
        });
    }

    public function userDelete(User $user, array $ids): array
    {
        $this->requireReady();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0))), 0, 500);
        if (!$ids) {
            throw new JSONException('请选择要删除的消息');
        }
        $count = (int)UserMessage::query()
            ->where('user_id', (int)$user->id)
            ->whereIn('id', $ids)
            ->delete();
        return ['count' => $count, 'unread_count' => $this->unreadCount($user)];
    }

    public function userClear(User $user): array
    {
        $this->requireReady();
        $count = (int)UserMessage::query()->where('user_id', (int)$user->id)->delete();
        return ['count' => $count, 'unread_count' => 0];
    }

    private function validateImage(array $file): string
    {
        if (!$file || !isset($file['tmp_name'], $file['name'], $file['size'], $file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
            throw new JSONException('请选择要上传的图片');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > self::MAX_IMAGE_BYTES) {
            throw new JSONException('图片大小不能超过 10MB');
        }

        $extension = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new JSONException('仅支持 JPG、PNG、WebP 图片');
        }
        $allowed = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $info = @getimagesize((string)$file['tmp_name']);
        $mime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
        if (!$info || $mime !== $allowed[$extension]) {
            throw new JSONException('文件内容不是有效图片');
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected = $finfo ? strtolower((string)finfo_file($finfo, (string)$file['tmp_name'])) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
            if ($detected !== '' && $detected !== $allowed[$extension]) {
                throw new JSONException('图片格式与文件内容不一致');
            }
        }
        return $extension;
    }

    private function registerUpload(string $path): UploadModel
    {
        $actualHash = (string)md5_file(BASE_PATH . $path);
        $existing = UploadModel::query()
            ->whereNull('user_id')
            ->where('type', 'message')
            ->where('hash', $actualHash)
            ->first();
        if ($existing) {
            return $this->reuseUploadRecord($existing, $path);
        }

        $upload = new UploadModel();
        $upload->user_id = null;
        $upload->hash = UploadModel::query()->where('hash', $actualHash)->exists()
            ? md5($actualHash . ':message:' . bin2hex(random_bytes(8)))
            : $actualHash;
        $upload->type = 'message';
        $upload->path = $path;
        $upload->create_time = Date::current();
        try {
            $upload->save();
        } catch (\Throwable $exception) {
            $sameMessage = UploadModel::query()
                ->whereNull('user_id')
                ->where('type', 'message')
                ->where('hash', $actualHash)
                ->first();
            if ($sameMessage) {
                return $this->reuseUploadRecord($sameMessage, $path);
            }
            if (UploadModel::query()->where('hash', $actualHash)->exists()) {
                $upload->hash = md5($actualHash . ':message:' . bin2hex(random_bytes(8)));
                $upload->save();
            } else {
                throw $exception;
            }
        }
        return $upload;
    }

    private function reuseUploadRecord(UploadModel $existing, string $newPath): UploadModel
    {
        $oldPath = (string)$existing->path;
        if ($oldPath === $newPath) {
            return $existing;
        }

        $oldAbsolute = BASE_PATH . $oldPath;
        $newAbsolute = BASE_PATH . $newPath;
        if (is_file($oldAbsolute) && @getimagesize($oldAbsolute)) {
            File::remove($newAbsolute);
            return $existing;
        }

        if (is_file($oldAbsolute)) {
            File::remove($oldAbsolute);
        }
        $directory = dirname($oldAbsolute);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new JSONException('消息图片目录不可写，请稍后重试');
        }
        if (@rename($newAbsolute, $oldAbsolute)) {
            return $existing;
        }
        if (@copy($newAbsolute, $oldAbsolute)) {
            File::remove($newAbsolute);
            return $existing;
        }
        throw new JSONException('消息图片恢复失败，请稍后重试');
    }

    public function upload(Manage $manage, array $file): array
    {
        $this->requireReady();
        if (Throttle::tooMany('message:upload:manage:' . (int)$manage->id, 40, 600)) {
            throw new JSONException('上传过于频繁，请稍后再试');
        }
        $extension = $this->validateImage($file);
        $staticPath = '/assets/cache/general/message/';
        $secureName = bin2hex(random_bytes(16)) . '.' . $extension;
        $handle = $this->uploadService->handle(
            $file,
            BASE_PATH . rtrim($staticPath, '/'),
            ['jpg', 'jpeg', 'png', 'webp'],
            10240,
            $secureName
        );
        if (!is_array($handle)) {
            throw new JSONException((string)$handle);
        }

        $path = $staticPath . $secureName;
        try {
            $record = $this->registerUpload($path);
        } catch (\Throwable $exception) {
            File::remove(BASE_PATH . $path);
            throw $exception;
        }
        $this->safeManageLog($manage, "上传了消息图片(#{$record->id})");
        return ['url' => (string)$record->path, 'upload_id' => (int)$record->id];
    }
}
