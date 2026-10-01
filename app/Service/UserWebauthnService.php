<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Config;
use App\Model\User;
use App\Model\UserLog;
use App\Model\UserWebauthn;
use App\Util\Client;
use App\Util\Date;
use App\Util\PasskeyProvider;
use App\Util\Schema;
use App\Util\UserAgent;
use App\Util\WebAuthn;
use Kernel\Exception\JSONException;
use Kernel\Util\Session;

/**
 * 会员 passkey(WebAuthn) 仪式编排（与后台 ManageWebauthnService 同构）：构造注册/登录选项、
 * 挑战暂存于 PHP 会话（单次、限时）、校验回传并读写凭证。控制器只做参数收集与结果包装。
 */
final class UserWebauthnService
{
    /** 每个会员最多保存的通行密钥数量 */
    public const MAX_PER_USER = 10;

    /** 登录用的通行密钥在服务端已不存在（被删除）：前端据此通知浏览器把它从密钥列表移除 */
    public const CODE_UNKNOWN_CREDENTIAL = 42004;

    /** 注册挑战有效期（秒） */
    private const CHALLENGE_TTL = 300;

    /**
     * 登录挑战有效期与保留份数：登录页的自动填充请求会一直挂着等用户选，挂上十几分钟很常见；
     * 同一会话可能开着好几个登录分页，各自拿到的挑战都要认得，所以按挑战值保留最近几份。
     */
    private const LOGIN_TTL = 900;
    private const LOGIN_KEEP = 5;

    /** 与后台的会话键分开：同一浏览器可能同时开着后台与会员中心 */
    private const SESS_REG = 'user.webauthn.reg';
    private const SESS_LOGIN = 'user.webauthn.login';

    private const TRANSPORTS = ['usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card'];

    /* ------------------------------------------------------------------ 注册 */

    /**
     * 生成注册（create）选项，挑战绑定当前会员暂存于会话。调用前控制器须已校验账号密码。
     * @throws JSONException
     */
    public static function registerOptions(User $user): array
    {
        Schema::ensureUserWebauthnTable();
        $credentials = self::credentials($user);
        if (count($credentials) >= self::MAX_PER_USER) {
            throw new JSONException("通行密钥数量已达上限，请先删除不再使用的");
        }

        [$rpId] = WebAuthn::relyingParty();
        $challenge = WebAuthn::challenge();
        Session::set(self::SESS_REG, ['c' => $challenge, 't' => time(), 'uid' => (int)$user->id]);

        $exclude = [];
        foreach ($credentials as $cred) {
            $exclude[] = ['type' => 'public-key', 'id' => (string)$cred->credential_id];
        }

        return [
            'rp' => ['id' => $rpId, 'name' => self::siteName()],
            'user' => [
                'id' => self::userHandle($user),
                'name' => (string)$user->username,
                'displayName' => (string)$user->username,
            ],
            'challenge' => $challenge,
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 120000,
            'attestation' => 'none',
            // 登录走 usernameless（不列 allowCredentials），非常驻凭证日后根本找不到，宁可注册时就报错
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'requireResidentKey' => true,
                'userVerification' => 'preferred',
            ],
            'excludeCredentials' => $exclude,
        ];
    }

    /**
     * 校验注册回传并落库。
     * @return array{id:int, name:string}
     * @throws JSONException
     */
    public static function register(User $user, string $name, string $attestationObject, string $clientDataJSON, string $rawId, string $transports): array
    {
        Schema::ensureUserWebauthnTable();
        $state = self::takeState(self::SESS_REG);
        if (!is_array($state) || (int)($state['uid'] ?? 0) !== (int)$user->id || (time() - (int)($state['t'] ?? 0)) > self::CHALLENGE_TTL) {
            throw new JSONException("添加会话已过期，请重新添加");
        }
        [$rpId, $origins] = WebAuthn::relyingParty();

        try {
            $result = WebAuthn::verifyRegistration($attestationObject, $clientDataJSON, (string)$state['c'], $rpId, $origins);
        } catch (\Throwable $e) {
            throw new JSONException("通行密钥校验失败，请重试");
        }

        $credentialId = $result['credentialId'];
        if ($rawId !== '' && !hash_equals($credentialId, rtrim($rawId, '='))) {
            throw new JSONException("通行密钥数据不一致，请重试");
        }
        if (strlen($credentialId) > 255) {
            throw new JSONException("该认证器的凭证过长，暂不支持");
        }
        if (UserWebauthn::query()->where('credential_id', $credentialId)->exists()) {
            throw new JSONException("该通行密钥已添加过");
        }
        if (self::countForUser($user) >= self::MAX_PER_USER) {
            throw new JSONException("通行密钥数量已达上限，请先删除不再使用的");
        }

        $transportList = self::cleanTransports($transports);
        $aaguid = self::formatAaguid((string)$result['aaguid']);
        $label = self::cleanName($name);
        if ($label === '') {
            $label = self::defaultName($aaguid, $transportList);
        }

        $cred = new UserWebauthn();
        $cred->user_id = (int)$user->id;
        $cred->credential_id = $credentialId;
        $cred->public_key = $result['publicKeyPem'];
        $cred->sign_count = (int)$result['signCount'];
        $cred->transports = $transportList ? implode(',', $transportList) : null;
        $cred->aaguid = $aaguid;
        $cred->name = $label;
        $cred->created_time = Date::current();
        $cred->last_used_time = null;
        $cred->last_used_ip = null;
        $cred->saveOrFail();

        return ['id' => (int)$cred->id, 'name' => $label];
    }

    /* ------------------------------------------------------------------ 登录 */

    /**
     * 生成登录（get）选项。usernameless：不指定 allowCredentials，由浏览器列出本站已保存的通行密钥。
     */
    public static function loginOptions(): array
    {
        [$rpId] = WebAuthn::relyingParty();
        $challenge = WebAuthn::challenge();

        Session::start();
        $now = time();
        $ring = array_values(array_filter(
            is_array($_SESSION[self::SESS_LOGIN] ?? null) ? $_SESSION[self::SESS_LOGIN] : [],
            static fn($c) => is_array($c) && isset($c['c'], $c['t']) && ($now - (int)$c['t']) <= self::LOGIN_TTL
        ));
        $ring[] = ['c' => $challenge, 't' => $now];
        $_SESSION[self::SESS_LOGIN] = array_slice($ring, -self::LOGIN_KEEP);
        Session::end();

        return [
            'challenge' => $challenge,
            'timeout' => 120000,
            'rpId' => $rpId,
            'userVerification' => 'preferred',
            'allowCredentials' => [],
        ];
    }

    /**
     * 校验登录断言，回传对应会员与本次是否做了用户验证（控制器据此决定是否免两步验证）。
     * 凭证能对上会员但校验失败时记一条安全日志。
     *
     * @return array{user:User, userVerified:bool}
     * @throws JSONException
     */
    public static function login(string $rawId, string $authenticatorData, string $clientDataJSON, string $signature, string $userHandle = ''): array
    {
        Schema::ensureUserWebauthnTable();
        // 挑战对不上（过期、或已被别的分页用掉）直接请用户重试，不当成攻击记风险日志
        $challenge = self::takeLoginChallenge($clientDataJSON);
        if ($challenge === null) {
            throw new JSONException("登录已超时，请重试");
        }

        /** @var UserWebauthn|null $cred */
        $cred = UserWebauthn::query()->where('credential_id', rtrim($rawId, '='))->first();
        $user = $cred ? User::query()->find((int)$cred->user_id) : null;
        if (!$cred || !$user) {
            throw new JSONException("这个通行密钥已失效，请用密码登录后重新添加", self::CODE_UNKNOWN_CREDENTIAL);
        }

        // 可发现凭证会带回注册时写入的 user.id，与凭证归属交叉核对
        if ($userHandle !== '' && !hash_equals(self::userHandle($user), rtrim($userHandle, '='))) {
            throw new JSONException("通行密钥与账号不匹配");
        }

        try {
            $userVerified = self::assertAndTouch($cred, $authenticatorData, $clientDataJSON, $signature, $challenge);
        } catch (JSONException $e) {
            UserLog::write($user, 'login_fail', '登录失败：通行密钥校验未通过', 1);
            throw $e;
        }

        return ['user' => $user, 'userVerified' => $userVerified];
    }

    /* ------------------------------------------------------------------ 凭证管理 */

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listForUser(User $user): array
    {
        $list = [];
        foreach (self::credentials($user) as $c) {
            $provider = PasskeyProvider::describe((string)$c->aaguid);
            $list[] = [
                'id' => (int)$c->id,
                'credential_id' => (string)$c->credential_id,
                'name' => (string)$c->name,
                'provider' => $provider['name'] ?? null,
                'kind' => $provider['kind'] ?? (self::isSecurityKey(self::splitTransports((string)$c->transports)) ? 'security_key' : 'unknown'),
                'created_time' => (string)$c->created_time,
                'created_relative' => Date::sauce((string)$c->created_time),
                'last_used_time' => $c->last_used_time ? (string)$c->last_used_time : null,
                'last_used_relative' => $c->last_used_time ? Date::sauce((string)$c->last_used_time) : null,
            ];
        }
        return $list;
    }

    /**
     * @throws JSONException
     */
    public static function rename(User $user, int $id, string $name): string
    {
        Schema::ensureUserWebauthnTable();
        $label = self::cleanName($name);
        if ($label === '') {
            throw new JSONException("请输入名称");
        }
        $cred = UserWebauthn::query()->where('id', $id)->where('user_id', (int)$user->id)->first();
        if (!$cred) {
            throw new JSONException("通行密钥不存在或已删除");
        }
        $cred->name = $label;
        $cred->save();
        return $label;
    }

    /**
     * @return string 被删除的通行密钥名称（写日志用）
     * @throws JSONException
     */
    public static function delete(User $user, int $id): string
    {
        Schema::ensureUserWebauthnTable();
        $cred = UserWebauthn::query()->where('id', $id)->where('user_id', (int)$user->id)->first();
        if (!$cred) {
            throw new JSONException("通行密钥不存在或已删除");
        }
        $name = (string)$cred->name;
        $cred->delete();
        return $name;
    }

    public static function countForUser(User $user): int
    {
        Schema::ensureUserWebauthnTable();
        return UserWebauthn::query()->where('user_id', (int)$user->id)->count();
    }

    /* ------------------------------------------------------------------ 内部 */

    /**
     * 按 clientDataJSON 里的挑战值取出并作废对应的登录挑战；读与删在同一次会话开启内完成。
     * 这里只拿它当查找键，真正的比对仍由 WebAuthn::verifyAssertionResult 用取回的挑战完成。
     */
    private static function takeLoginChallenge(string $clientDataJSON): ?string
    {
        $data = json_decode(WebAuthn::b64uDecode($clientDataJSON), true);
        $wanted = is_array($data) ? rtrim((string)($data['challenge'] ?? ''), '=') : '';
        if ($wanted === '') {
            return null;
        }

        Session::start();
        $now = time();
        $found = null;
        $keep = [];
        foreach (is_array($_SESSION[self::SESS_LOGIN] ?? null) ? $_SESSION[self::SESS_LOGIN] : [] as $c) {
            if (!is_array($c) || !isset($c['c'], $c['t']) || ($now - (int)$c['t']) > self::LOGIN_TTL) {
                continue;
            }
            if ($found === null && hash_equals((string)$c['c'], $wanted)) {
                $found = (string)$c['c'];
                continue;
            }
            $keep[] = $c;
        }
        $_SESSION[self::SESS_LOGIN] = $keep;
        Session::end();
        return $found;
    }

    /**
     * 取出并作废会话里的挑战：读与删在同一次会话开启内完成（文件会话在此期间持锁），
     * 并发请求无法同时读到同一个挑战，挑战真正只用一次。
     * @return mixed
     */
    private static function takeState(string $key)
    {
        Session::start();
        $state = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        Session::end();
        return $state;
    }

    /**
     * @return \Illuminate\Support\Collection<int, UserWebauthn>
     */
    private static function credentials(User $user): \Illuminate\Support\Collection
    {
        Schema::ensureUserWebauthnTable();
        return UserWebauthn::query()
            ->where('user_id', (int)$user->id)
            ->orderByDesc('id')
            ->get(['id', 'name', 'credential_id', 'transports', 'aaguid', 'created_time', 'last_used_time']);
    }

    /**
     * 校验断言并更新计数器/使用痕迹。
     * @return bool 本次是否做了用户验证
     * @throws JSONException
     */
    private static function assertAndTouch(UserWebauthn $cred, string $authenticatorData, string $clientDataJSON, string $signature, string $challenge): bool
    {
        [$rpId, $origins] = WebAuthn::relyingParty();
        try {
            $result = WebAuthn::verifyAssertionResult(
                $authenticatorData,
                $clientDataJSON,
                $signature,
                (string)$cred->public_key,
                $challenge,
                $rpId,
                $origins,
                (int)$cred->sign_count
            );
        } catch (\Throwable $e) {
            throw new JSONException("通行密钥校验失败，请重试");
        }

        $cred->sign_count = $result['signCount'];
        $cred->last_used_time = Date::current();
        $cred->last_used_ip = Client::getAddress();
        $cred->saveOrFail();
        return $result['userVerified'];
    }

    /** WebAuthn user.id：只放不可逆推个资的内部标识，避免把用户名/邮箱写进认证器 */
    public static function userHandle(User $user): string
    {
        return WebAuthn::b64uEncode('u:' . (int)$user->id);
    }

    private static function cleanName(string $name): string
    {
        $name = str_replace(['<', '>'], '', $name);
        $name = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        return mb_substr($name, 0, 32);
    }

    /**
     * 未命名时的默认名：先认 AAGUID 对应的提供方（iCloud 钥匙串 / Google 密码管理工具…）；
     * 认不出（Windows Hello、旧版 iOS 在 attestation=none 下给全零）再按传输方式与系统推断，最后退回浏览器与系统。
     * @param string[] $transports
     */
    private static function defaultName(?string $aaguid, array $transports): string
    {
        $provider = PasskeyProvider::describe((string)$aaguid);
        if ($provider) {
            return $provider['name'];
        }
        if (self::isSecurityKey($transports)) {
            return '安全密钥';
        }
        $ua = UserAgent::describe((string)Client::getUserAgent());
        // 只有认证器没给 AAGUID（全零）时才按系统推断；给了但不在清单里的多半是第三方密码管理器，别乱认成系统自带
        if ($aaguid === null && in_array('internal', $transports, true)) {
            if ($ua['os'] === 'Windows') {
                return 'Windows Hello';
            }
            if (in_array($ua['os'], ['iPhone', 'iPad'], true) || ($ua['os'] === 'macOS' && $ua['browser'] === 'Safari')) {
                return 'iCloud 钥匙串';
            }
        }
        return $ua['label'] !== '未知' ? mb_substr($ua['label'], 0, 32) : '通行密钥';
    }

    /** @param string[] $transports */
    private static function isSecurityKey(array $transports): bool
    {
        return !in_array('internal', $transports, true) && !in_array('hybrid', $transports, true)
            && array_intersect($transports, ['usb', 'nfc', 'ble', 'smart-card']) !== [];
    }

    /** @return string[] */
    private static function cleanTransports(string $transports): array
    {
        return array_values(array_intersect(self::TRANSPORTS, self::splitTransports($transports)));
    }

    /** @return string[] */
    private static function splitTransports(string $transports): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($transports)))));
    }

    /** 32 位十六进制 → 带连字号的标准形式；全零（认证器未提供）视为无 */
    private static function formatAaguid(string $hex): ?string
    {
        $hex = strtolower($hex);
        if (!preg_match('/^[0-9a-f]{32}$/', $hex) || trim($hex, '0') === '') {
            return null;
        }
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function siteName(): string
    {
        $name = trim((string)Config::get('shop_name'));
        return $name !== '' ? mb_substr($name, 0, 64) : 'ACGFAKA';
    }
}
