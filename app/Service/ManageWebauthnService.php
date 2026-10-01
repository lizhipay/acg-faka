<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Config;
use App\Model\Manage;
use App\Model\ManageWebauthn;
use App\Util\Client;
use App\Util\Date;
use App\Util\Schema;
use App\Util\WebAuthn;
use Kernel\Exception\JSONException;
use Kernel\Util\Session;

/**
 * 后台 passkey(WebAuthn) 仪式编排：构造注册/登入/解锁选项、暂存挑战于 PHP 会话、
 * 校验回传并读写凭证。控制器只做参数收集与结果包装，安全逻辑集中在此。
 */
final class ManageWebauthnService
{
    /** 挑战有效期（秒） */
    private const CHALLENGE_TTL = 300;

    private const SESS_REG = 'webauthn.reg';
    private const SESS_LOGIN = 'webauthn.login';
    private const SESS_UNLOCK = 'webauthn.unlock';

    /* ------------------------------------------------------------------ 注册 */

    /**
     * 生成注册（create）选项，并把挑战暂存到当前管理员会话。
     */
    public static function registerOptions(Manage $manage): array
    {
        Schema::ensureManageWebauthnTable();
        [$rpId] = WebAuthn::relyingParty();
        $challenge = WebAuthn::challenge();
        Session::set(self::SESS_REG, ['c' => $challenge, 't' => time(), 'mid' => (int)$manage->id]);

        $exclude = [];
        foreach (self::listForManage($manage) as $cred) {
            $exclude[] = ['type' => 'public-key', 'id' => $cred['credential_id']];
        }

        return [
            'rp' => ['id' => $rpId, 'name' => self::siteName()],
            'user' => [
                'id' => WebAuthn::b64uEncode('m:' . (int)$manage->id),
                'name' => (string)$manage->email,
                'displayName' => (string)($manage->nickname ?: $manage->email),
            ],
            'challenge' => $challenge,
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
            'excludeCredentials' => $exclude,
        ];
    }

    /**
     * 校验注册回传并落库。
     * @throws JSONException
     */
    public static function register(Manage $manage, string $name, string $attestationObject, string $clientDataJSON, string $rawId, string $transports): void
    {
        Schema::ensureManageWebauthnTable();
        $state = Session::get(self::SESS_REG);
        Session::remove(self::SESS_REG);
        if (!is_array($state) || (int)($state['mid'] ?? 0) !== (int)$manage->id || (time() - (int)($state['t'] ?? 0)) > self::CHALLENGE_TTL) {
            throw new JSONException("注册会话已过期，请重试");
        }
        [$rpId, $origins] = WebAuthn::relyingParty();

        try {
            $result = WebAuthn::verifyRegistration($attestationObject, $clientDataJSON, (string)$state['c'], $rpId, $origins);
        } catch (\Throwable $e) {
            throw new JSONException("通行密钥校验失败：" . $e->getMessage());
        }

        if (ManageWebauthn::query()->where('credential_id', $result['credentialId'])->exists()) {
            throw new JSONException("该通行密钥已被注册");
        }

        $cred = new ManageWebauthn();
        $cred->manage_id = (int)$manage->id;
        $cred->credential_id = $result['credentialId'];
        $cred->public_key = $result['publicKeyPem'];
        $cred->sign_count = (int)$result['signCount'];
        $cred->transports = mb_substr(trim($transports), 0, 128) ?: null;
        $cred->aaguid = $result['aaguid'] ?: null;
        $cred->name = mb_substr(trim($name), 0, 64) ?: '通行密钥';
        $cred->created_time = Date::current();
        $cred->last_used_time = null;
        $cred->last_used_ip = null;
        $cred->saveOrFail();
    }

    /* ------------------------------------------------------------------ 登入 */

    /**
     * 生成登入（get）选项。usernameless：不指定 allowCredentials，由认证器直接选择。
     */
    public static function loginOptions(): array
    {
        Schema::ensureManageWebauthnTable();
        [$rpId] = WebAuthn::relyingParty();
        $challenge = WebAuthn::challenge();
        Session::set(self::SESS_LOGIN, ['c' => $challenge, 't' => time()]);

        return [
            'challenge' => $challenge,
            'timeout' => 60000,
            'rpId' => $rpId,
            'userVerification' => 'preferred',
            'allowCredentials' => [],
        ];
    }

    /**
     * 校验登入断言，回传对应管理员（控制器随后签发会话）。
     * @throws JSONException
     */
    /**
     * @return array{manage: Manage, userVerified: bool}
     */
    public static function login(string $rawId, string $authenticatorData, string $clientDataJSON, string $signature): array
    {
        Schema::ensureManageWebauthnTable();
        $state = Session::get(self::SESS_LOGIN);
        Session::remove(self::SESS_LOGIN);
        if (!is_array($state) || (time() - (int)($state['t'] ?? 0)) > self::CHALLENGE_TTL) {
            throw new JSONException("登录会话已过期，请重试");
        }

        $cred = ManageWebauthn::query()->where('credential_id', $rawId)->first();
        if (!$cred) {
            throw new JSONException("通行密钥不存在或已被移除");
        }
        $manage = Manage::query()->find((int)$cred->manage_id);
        if (!$manage) {
            throw new JSONException("账号不存在");
        }

        $userVerified = self::assertAndTouch($cred, $authenticatorData, $clientDataJSON, $signature, (string)$state['c']);
        return ['manage' => $manage, 'userVerified' => $userVerified];
    }

    /* ------------------------------------------------------------------ 解锁 */

    /**
     * 生成解锁（get）选项，限定为该管理员已注册的凭证。
     */
    public static function unlockOptions(Manage $manage): array
    {
        Schema::ensureManageWebauthnTable();
        [$rpId] = WebAuthn::relyingParty();
        $challenge = WebAuthn::challenge();
        Session::set(self::SESS_UNLOCK, ['c' => $challenge, 't' => time(), 'mid' => (int)$manage->id]);

        $allow = [];
        foreach (self::listForManage($manage) as $cred) {
            $allow[] = ['type' => 'public-key', 'id' => $cred['credential_id']];
        }

        return [
            'challenge' => $challenge,
            'timeout' => 60000,
            'rpId' => $rpId,
            'userVerification' => 'preferred',
            'allowCredentials' => $allow,
        ];
    }

    /**
     * 校验解锁断言，凭证必须属于该管理员。
     * @throws JSONException
     */
    public static function unlockVerify(Manage $manage, string $rawId, string $authenticatorData, string $clientDataJSON, string $signature): void
    {
        Schema::ensureManageWebauthnTable();
        $state = Session::get(self::SESS_UNLOCK);
        Session::remove(self::SESS_UNLOCK);
        if (!is_array($state) || (int)($state['mid'] ?? 0) !== (int)$manage->id || (time() - (int)($state['t'] ?? 0)) > self::CHALLENGE_TTL) {
            throw new JSONException("解锁会话已过期，请重试");
        }

        $cred = ManageWebauthn::query()->where('credential_id', $rawId)->where('manage_id', (int)$manage->id)->first();
        if (!$cred) {
            throw new JSONException("通行密钥不属于当前账号");
        }

        self::assertAndTouch($cred, $authenticatorData, $clientDataJSON, $signature, (string)$state['c']);
    }

    /* ------------------------------------------------------------------ 凭证 CRUD */

    /**
     * @return array<int, array{id:int, name:string, credential_id:string, created_time:string, last_used_time:?string}>
     */
    public static function listForManage(Manage $manage): array
    {
        Schema::ensureManageWebauthnTable();
        return ManageWebauthn::query()
            ->where('manage_id', (int)$manage->id)
            ->orderByDesc('id')
            ->get(['id', 'name', 'credential_id', 'created_time', 'last_used_time'])
            ->map(static function (ManageWebauthn $c): array {
                return [
                    'id' => (int)$c->id,
                    'name' => (string)$c->name,
                    'credential_id' => (string)$c->credential_id,
                    'created_time' => (string)$c->created_time,
                    'created_relative' => Date::sauce((string)$c->created_time),
                    'last_used_time' => $c->last_used_time ? (string)$c->last_used_time : null,
                    'last_used_relative' => $c->last_used_time ? Date::sauce((string)$c->last_used_time) : null,
                ];
            })
            ->all();
    }

    public static function rename(Manage $manage, int $id, string $name): bool
    {
        Schema::ensureManageWebauthnTable();
        $name = mb_substr(trim($name), 0, 64) ?: '通行密钥';
        return ManageWebauthn::query()
                ->where('id', $id)->where('manage_id', (int)$manage->id)
                ->update(['name' => $name]) >= 0;
    }

    public static function delete(Manage $manage, int $id): bool
    {
        Schema::ensureManageWebauthnTable();
        return ManageWebauthn::query()
                ->where('id', $id)->where('manage_id', (int)$manage->id)
                ->delete() > 0;
    }

    public static function countForManage(Manage $manage): int
    {
        Schema::ensureManageWebauthnTable();
        return ManageWebauthn::query()->where('manage_id', (int)$manage->id)->count();
    }

    /* ------------------------------------------------------------------ 内部 */

    /**
     * 校验断言并更新计数器/使用痕迹。
     * @throws JSONException
     */
    /**
     * @return bool 本次断言是否做了用户验证（UV：指纹/面容/PIN）
     */
    private static function assertAndTouch(ManageWebauthn $cred, string $authenticatorData, string $clientDataJSON, string $signature, string $challenge): bool
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
            throw new JSONException("通行密钥校验失败：" . $e->getMessage());
        }

        $cred->sign_count = (int)$result['signCount'];
        $cred->last_used_time = Date::current();
        $cred->last_used_ip = Client::getAddress();
        $cred->saveOrFail();
        return (bool)$result['userVerified'];
    }

    private static function siteName(): string
    {
        $name = (string)Config::get('title');
        return $name !== '' ? mb_substr($name, 0, 64) : '管理后台';
    }
}
