<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Service\Email;
use App\Service\Sms;
use App\Service\UserWebauthnService;
use App\Util\Captcha;
use App\Util\QrCode;
use App\Util\Str;
use App\Util\Validation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Security extends User
{
    #[Inject]
    private Email $email;

    #[Inject]
    private Sms $sms;

    /**
     * 会话内「步进验证」（改绑定 / 改密码 / 开关两步验证）前的限流闸。
     * 这些端点校验账号密码或动态码，若不限流，被窃会话者可在线爆破密码或 6 位动态码。
     * 与 fundVerify / ipWhitelistAdd 同口径：按会员 10 次 / 300 秒，校验全部通过后由调用方 clear。
     * @throws JSONException
     */
    private function stepThrottle(int $uid): string
    {
        $key = "secstep:uid:" . $uid;
        if (\App\Util\Throttle::tooMany($key, 10, 300)) {
            throw new JSONException("验证过于频繁，请稍后再试");
        }
        return $key;
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function personal(): array
    {
        $user = $this->getUser();
        $user->avatar = $this->request->post("avatar");
        $user->qq = $this->request->post("qq");
        $user->alipay = $this->request->post("alipay");
        $user->nicename = $this->request->post("nicename");
        $user->settlement = $this->request->post("settlement", Filter::INTEGER);
        $user->wallet_address = $this->request->post("wallet_address");

        if (!in_array($user->settlement, [0, 1, 3])) {
            throw new JSONException("不支持的结算方式");
        }

        //wallet_address 是 varchar(64)，超长直接入库会触发 MySQL 1406→500。提前给出干净的业务错误。
        if (mb_strlen((string)$user->wallet_address) > 64) {
            throw new JSONException("钱包地址过长");
        }

        $plugin = (array)$this->request->post("plugin");

        $fields = [
            'username',
            'email',
            'phone',
            'qq',
            'password',
            'salt',
            'app_key',
            'avatar',
            'balance',
            'coin',
            'integral',
            'create_time',
            'login_time',
            'last_login_time',
            'login_ip',
            'last_login_ip',
            'pid',
            'recharge',
            'total_coin',
            'status',
            'business_level',
            'nicename',
            'alipay',
            'wechat',
            'settlement',
            'totp_secret',
            'totp_recovery',
            'fund_2fa',
            'id'
        ];

        foreach ($fields as $value) {
            unset($plugin[$value]);
        }

        foreach ($plugin as $key => $val) {
            $key = strtolower(trim((string)$key));

            if ($key === '') {
                throw new JSONException('非法字段名#0');
            }

            //必须是合法列名标识符（字母或下划线开头，不允许数字开头）。这样纯数字键（plugin 传标量时
            //(array) 强转出的 "0"）会被干净拒绝，而不是走到 $user->{'0'} 生成非法列名→PDOException→500。
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                throw new JSONException('非法字段名#1');
            }

            if (is_array($val) || is_object($val)) {
                throw new JSONException('字段格式错误：' . $key);
            }

            if (in_array($key, $fields)) {
                throw new JSONException("are you an idiot?");
            }

            $user->$key = $val;
        }

        $wechat = $this->request->post("wechat");
        if ($wechat != "") {

            $qrCode = QrCode::parse(BASE_PATH . $wechat);

            if ($qrCode == "") {
                throw new JSONException("您上传的微信二维码错误。");
            }

            $user->wechat = $qrCode;
        }

        $user->save();
        \App\Model\UserLog::write($user, 'settlement', '修改了个人资料/结算方式');
        return $this->json(200, "修改成功");
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function email(): array
    {
        //改绑前必须用登录密码二次验证：只凭会话（可能经 XSS/共享设备被窃）就能改绑，会被攻击者改到
        //自己的邮箱再走找回密码永久接管（F-33）。要求账号密码=只有会话也改不了绑定。
        $user = $this->getUser();
        $throttleKey = $this->stepThrottle((int)$user->id);
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("登录密码不正确");
        }
        \App\Util\Throttle::clear($throttleKey);
        if (!$this->email->checkCaptcha($_POST['email'], Email::CAPTCHA_BIND_NEW, (int)$_POST['email_captcha'])) {
            throw new JSONException("邮箱验证码不正确");
        }
        $user->email = $_POST['email'];
        $user->save();
        \App\Model\UserLog::write($user, 'email', '修改了绑定邮箱');

        $this->email->destroyCaptcha($user->email, Email::CAPTCHA_BIND_NEW);
        return $this->json(200, "修改成功");
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function phone(): array
    {
        //改绑前必须用登录密码二次验证（同 email()，防会话被窃后改绑手机再走找回密码永久接管，F-33）。
        $user = $this->getUser();
        $throttleKey = $this->stepThrottle((int)$user->id);
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("登录密码不正确");
        }
        \App\Util\Throttle::clear($throttleKey);
        if (!$this->sms->checkCaptcha($_POST['phone'], Sms::CAPTCHA_BIND_NEW, (int)$_POST['phone_captcha'])) {
            throw new JSONException("手机验证码不正确");
        }
        $user->phone = $_POST['phone'];
        $user->save();
        \App\Model\UserLog::write($user, 'phone', '修改了绑定手机号');

        $this->sms->destroyCaptcha($user->phone, Sms::CAPTCHA_BIND_NEW);
        return $this->json(200, "修改成功");
    }

    /**
     * @throws JSONException
     */
    public function password(): array
    {
        $oldPassword = (string)$_POST['old_password'];
        $password = (string)$_POST['password'];
        $rePassword = (string)$_POST['re_password'];
        $user = $this->getUser();
        $throttleKey = $this->stepThrottle((int)$user->id);
        //兼容旧清洗管线时代哈希的特殊字符密码（#833），改密成功后即升级为新形态
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, $oldPassword, (string)$this->request->unsafePost('old_password'))) {
            throw new JSONException("旧密码输入不正确");
        }
        \App\Util\Throttle::clear($throttleKey);
        if ($password != $rePassword) {
            throw new JSONException("两次密码输入不一致");
        }

        if (!Validation::password($password)) {
            throw new JSONException("新密码格式不正确，密码必须6位以上");
        }

        $user->password = Str::hashPassword($password);
        $user->save();
        //改密后吊销本账号其它设备的会话，仅保留当前会话
        \App\Service\UserSessionManager::revokeAll((int)$user->id, \App\Service\UserSessionManager::currentSessionId());
        \App\Model\UserLog::write($user, 'password', '修改了登录密码');
        return $this->json(200, "修改成功");
    }

    /**
     * @throws JSONException
     */
    public function emailBindNew(): array
    {
        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "emailBindNew")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['email']) || !Validation::email((string)$_POST['email'])) {
            throw new JSONException("邮箱地址不正确");
        }

        if (\App\Model\User::query()->where("email", $_POST['email'])->first()) {
            throw new JSONException("该邮箱已被他人绑定");
        }
        $this->email->sendCaptcha((string)$_POST['email'], Email::CAPTCHA_BIND_NEW);
        Captcha::destroy("emailBindNew");
        return $this->json(200, "验证码发送成功");
    }

    /**
     * @throws JSONException
     */
    public function phoneBindNew(): array
    {
        if (!isset($_POST['captcha']) || !Captcha::check((int)$_POST['captcha'], "phoneBindNew")) {
            throw new JSONException("验证码错误");
        }

        if (!isset($_POST['phone']) || !Validation::phone((string)$_POST['phone'])) {
            throw new JSONException("手机号码不正确");
        }

        if (\App\Model\User::query()->where("phone", $_POST['phone'])->first()) {
            throw new JSONException("该手机已被他人绑定");
        }

        $this->sms->sendCaptcha((string)$_POST['phone'], Sms::CAPTCHA_BIND_NEW);
        Captcha::destroy("phoneBindNew");
        return $this->json(200, "验证码发送成功");
    }


    /**
     * @return array
     */
    public function resetKey(): array
    {
        // 仅接受 POST：避免被 <img src="/user/api/security/resetKey"> 这类同源注入以 GET 触发（CSRF）。
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException("请求方式不正确");
        }
        $user = \App\Model\User::query()->find($this->getUser()->id);
        $user->app_key = strtoupper(Str::generateRandStr(16));
        $user->save();
        \App\Model\UserLog::write($user, 'app_key_reset', '重置了对接密钥(app_key)', 1);
        return $this->json(200, "重置成功", ["app_key" => $user->app_key]);
    }

    /**
     * 两步验证：当前是否已开启 + 剩余恢复码数量
     * @return array
     */
    public function totpStatus(): array
    {
        \App\Util\Schema::ensureUserTotp();
        $user = $this->getUser();
        return $this->json(data: [
            "bound" => !empty($user->totp_secret),
            "recovery_left" => \App\Util\RecoveryCode::remaining((string)$user->totp_recovery),
            "fund_2fa" => (int)$user->fund_2fa === 1,
            "ip_whitelist" => \App\Util\IpWhitelist::count((int)$user->id),
        ]);
    }

    /**
     * 两步验证：生成待绑定密钥（不落库，前端据 uri 出二维码/手动录入）
     * @return array
     */
    public function totpSecret(): array
    {
        $user = $this->getUser();
        if (!empty($user->totp_secret)) {
            throw new JSONException("已开启两步验证，请先关闭再重新绑定");
        }
        $secret = \App\Util\Totp::generateSecret();
        $issuer = (string)(\App\Model\Config::get("shop_name") ?: "ACGFAKA");
        $account = (string)($user->email ?: $user->username);
        $uri = \App\Util\Totp::keyUri($secret, $account, $issuer);
        return $this->json(data: ["secret" => $secret, "uri" => $uri]);
    }

    /**
     * 两步验证：开启（校验账号密码 + 一次动态码后保存密钥，并一次性返回备用恢复码）
     * @return array
     * @throws JSONException
     */
    public function totpEnable(): array
    {
        \App\Util\Schema::ensureUserTotp();
        $user = \App\Model\User::query()->find($this->getUser()->id);
        if (!empty($user->totp_secret)) {
            throw new JSONException("已开启两步验证，请先关闭再重新绑定");
        }
        $throttleKey = $this->stepThrottle((int)$user->id);
        //要求账号密码：只有会话也不能开启/改动两步验证（与改邮箱/手机同口径，防会话被盗后接管）
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("账号密码不正确");
        }
        $secret = strtoupper(trim((string)($_POST['secret'] ?? '')));
        if (!preg_match('/^[A-Z2-7]{16,64}$/', $secret)) {
            throw new JSONException("密钥格式错误，请刷新后重试");
        }
        if (!\App\Util\Totp::verify($secret, (string)($_POST['code'] ?? ''))) {
            throw new JSONException("验证码错误，请确认手机时间已同步后重试");
        }
        \App\Util\Throttle::clear($throttleKey);

        $recovery = \App\Util\RecoveryCode::generate(8);
        $user->totp_secret = $secret;
        $user->totp_recovery = \App\Util\RecoveryCode::hashAll($recovery);
        //开启两步验证时，默认一并开启「资金操作二次验证」
        $user->fund_2fa = 1;
        $user->save();
        //开启后吊销其它设备会话，仅留当前
        \App\Service\UserSessionManager::revokeAll((int)$user->id, \App\Service\UserSessionManager::currentSessionId());
        \App\Model\UserLog::write($user, 'totp_on', '开启了两步验证（含资金操作二次验证）');

        return $this->json(200, "两步验证已开启", ["recovery" => $recovery]);
    }

    /**
     * 两步验证：关闭（校验账号密码 + 动态码或恢复码）
     * @return array
     * @throws JSONException
     */
    public function totpDisable(): array
    {
        \App\Util\Schema::ensureUserTotp();
        $user = \App\Model\User::query()->find($this->getUser()->id);
        if (empty($user->totp_secret)) {
            throw new JSONException("尚未开启两步验证");
        }
        $throttleKey = $this->stepThrottle((int)$user->id);
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("账号密码不正确");
        }
        $code = trim((string)($_POST['code'] ?? ''));
        $ok = \App\Util\Totp::verifyAndConsume((string)$user->totp_secret, $code, "user:" . (int)$user->id)
            || \App\Util\RecoveryCode::consume((string)$user->totp_recovery, $code) !== null;
        if (!$ok) {
            throw new JSONException("验证码错误");
        }
        \App\Util\Throttle::clear($throttleKey);

        $user->totp_secret = null;
        $user->totp_recovery = null;
        $user->fund_2fa = 0;
        $user->save();
        \App\Model\UserLog::write($user, 'totp_off', '关闭了两步验证', 1);
        return $this->json(200, "两步验证已关闭");
    }

    /**
     * 两步验证：重新生成备用恢复码（校验账号密码 + 动态码，旧恢复码全部作废）
     * @return array
     * @throws JSONException
     */
    public function totpRecovery(): array
    {
        \App\Util\Schema::ensureUserTotp();
        $user = \App\Model\User::query()->find($this->getUser()->id);
        if (empty($user->totp_secret)) {
            throw new JSONException("尚未开启两步验证");
        }
        $throttleKey = $this->stepThrottle((int)$user->id);
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("账号密码不正确");
        }
        if (!\App\Util\Totp::verifyAndConsume((string)$user->totp_secret, (string)($_POST['code'] ?? ''), "user:" . (int)$user->id)) {
            throw new JSONException("验证码错误");
        }
        \App\Util\Throttle::clear($throttleKey);
        $recovery = \App\Util\RecoveryCode::generate(8);
        $user->totp_recovery = \App\Util\RecoveryCode::hashAll($recovery);
        $user->save();
        \App\Model\UserLog::write($user, 'totp_recovery', '重新生成了备用恢复码');
        return $this->json(200, "已重新生成", ["recovery" => $recovery]);
    }

    /**
     * 资金操作二次验证：校验一次动态码，开启步进窗口（窗口内的资金操作免重复验证）。
     * @return array
     * @throws JSONException
     */
    public function fundVerify(): array
    {
        \App\Util\Schema::ensureUserTotp();
        $user = $this->getUser();
        if (empty($user->totp_secret)) {
            throw new JSONException("尚未开启两步验证");
        }
        if (\App\Util\Throttle::tooMany("fundverify:uid:" . (int)$user->id, 10, 300)) {
            throw new JSONException("验证过于频繁，请稍后再试");
        }
        if (!\App\Util\Totp::verifyAndConsume((string)$user->totp_secret, trim((string)($_POST['code'] ?? '')), "user:" . (int)$user->id)) {
            throw new JSONException("验证码错误");
        }
        \App\Util\FundGuard::markVerified((int)$user->id);
        \App\Util\Throttle::clear("fundverify:uid:" . (int)$user->id);
        \App\Model\UserLog::write($user, 'fund_verify', '通过了资金操作二次验证');
        return $this->json(200, "验证成功");
    }

    /**
     * 资金操作二次验证开关（校验账号密码 + 动态码后切换）。
     * @return array
     * @throws JSONException
     */
    public function fundGuardSet(): array
    {
        \App\Util\Schema::ensureUserTotp();
        $user = \App\Model\User::query()->find($this->getUser()->id);
        if (empty($user->totp_secret)) {
            throw new JSONException("请先开启两步验证");
        }
        $throttleKey = $this->stepThrottle((int)$user->id);
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("账号密码不正确");
        }
        if (!\App\Util\Totp::verifyAndConsume((string)$user->totp_secret, trim((string)($_POST['code'] ?? '')), "user:" . (int)$user->id)) {
            throw new JSONException("验证码错误");
        }
        \App\Util\Throttle::clear($throttleKey);
        $enable = (string)($_POST['enable'] ?? '') === '1';
        $user->fund_2fa = $enable ? 1 : 0;
        $user->save();
        \App\Model\UserLog::write($user, $enable ? 'fund_2fa_on' : 'fund_2fa_off', $enable ? '开启了资金操作二次验证' : '关闭了资金操作二次验证', $enable ? 0 : 1);
        return $this->json(200, $enable ? "资金操作二次验证已开启" : "资金操作二次验证已关闭");
    }

    /**
     * 对接白名单 IP：清单 + 最近被拒的来源（开了资金操作二次验证后，对接接口只放行清单内的来源用余额下单）。
     * @return array
     */
    public function ipWhitelist(): array
    {
        \App\Util\Schema::ensureUserTotp();
        $user = $this->getUser();
        return $this->json(data: \App\Util\IpWhitelist::overview((int)$user->id) + [
            'bound' => !empty($user->totp_secret),
            'fund_2fa' => (int)$user->fund_2fa === 1,
        ]);
    }

    /**
     * 对接白名单 IP：新增。开了两步验证校验动态码，没开校验账号密码。只靠会话不能加：否则会话被盗者
     * 先加自己的 IP 再用 app_key 下单，等于绕过资金验证。
     * @return array
     * @throws JSONException
     */
    public function ipWhitelistAdd(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException("请求方式不正确");
        }
        \App\Util\Schema::ensureUserTotp();
        $user = \App\Model\User::query()->find($this->getUser()->id);
        $ip = \App\Util\IpWhitelist::prepare((int)$user->id, (string)($_POST['ip'] ?? ''));

        $throttleKey = "ipwhitelist:uid:" . (int)$user->id;
        if (\App\Util\Throttle::tooMany($throttleKey, 10, 300)) {
            throw new JSONException("验证过于频繁，请稍后再试");
        }
        if (!empty($user->totp_secret)) {
            if (!\App\Util\Totp::verifyAndConsume((string)$user->totp_secret, trim((string)($_POST['code'] ?? '')), "user:" . (int)$user->id)) {
                throw new JSONException("验证码错误");
            }
        } elseif (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("账号密码不正确");
        }
        \App\Util\Throttle::clear($throttleKey);

        $entry = \App\Util\IpWhitelist::add((int)$user->id, $ip, (string)$this->request->unsafePost('note'));
        \App\Model\UserLog::write($user, 'ip_whitelist_add', '添加了对接白名单 IP：' . $entry->ip . ($entry->note !== '' ? '（' . $entry->note . '）' : ''), 1);
        return $this->json(200, "已加入白名单", \App\Util\IpWhitelist::overview((int)$user->id));
    }

    /**
     * 对接白名单 IP：移除（只会缩小放行范围，不需要动态码）。
     * @return array
     * @throws JSONException
     */
    public function ipWhitelistRemove(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException("请求方式不正确");
        }
        $user = $this->getUser();
        $entry = \App\Util\IpWhitelist::remove((int)$user->id, (int)($_POST['id'] ?? 0));
        \App\Model\UserLog::write($user, 'ip_whitelist_remove', '移除了对接白名单 IP：' . $entry->ip);
        return $this->json(200, "已移出白名单", \App\Util\IpWhitelist::overview((int)$user->id));
    }

    /**
     * 当前会员的在线设备（会话）列表。响应不含会话标识/哈希/完整 UA。
     * @return array
     */
    public function deviceSessions(): array
    {
        $uid = (int)$this->getUser()->id;
        return $this->json(data: ['list' => \App\Service\UserSessionManager::listActive($uid)]);
    }

    /**
     * 退出指定的其他设备。
     * @return array
     * @throws JSONException
     */
    public function revokeDeviceSession(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException("请求方式不正确");
        }
        $uid = (int)$this->getUser()->id;
        $rawId = $this->request->post('id');
        if ((!is_int($rawId) && !(is_string($rawId) && ctype_digit(trim($rawId)))) || (int)$rawId <= 0) {
            throw new JSONException('设备会话参数无效');
        }
        $sessionId = (int)$rawId;
        if ($sessionId === \App\Service\UserSessionManager::currentSessionId()) {
            throw new JSONException('当前设备请使用「退出登录」退出');
        }
        if (!\App\Service\UserSessionManager::revokeSession($uid, $sessionId)) {
            throw new JSONException('设备会话不存在或已经退出');
        }
        \App\Model\UserLog::write($this->getUser(), 'device_revoke', '退出了一台其他设备（会话#' . $sessionId . '）');
        return $this->json(200, '该设备已退出');
    }

    /**
     * 退出当前设备以外的全部设备。
     * @return array
     * @throws JSONException
     */
    public function revokeOtherDeviceSessions(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException("请求方式不正确");
        }
        $uid = (int)$this->getUser()->id;
        $currentId = \App\Service\UserSessionManager::currentSessionId();
        if ($currentId <= 0) {
            throw new JSONException('当前设备会话无效，请重新登录');
        }
        $count = \App\Service\UserSessionManager::revokeAll($uid, $currentId);
        if ($count > 0) {
            \App\Model\UserLog::write($this->getUser(), 'device_revoke', '退出了其他全部设备（' . $count . ' 台）');
        }
        return $this->json(200, $count > 0 ? '其他设备已全部退出' : '没有其他在线设备', ['count' => $count]);
    }

    /**
     * 退出全部设备（含当前）。
     * @return array
     */
    public function revokeAllDeviceSessions(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException("请求方式不正确");
        }
        $uid = (int)$this->getUser()->id;
        $count = \App\Service\UserSessionManager::revokeAll($uid);
        \App\Model\UserLog::write($this->getUser(), 'device_revoke', '退出了全部设备（含当前，' . $count . ' 台）', 1);
        \App\Service\UserSessionManager::clearCookie();
        return $this->json(200, '全部设备已退出', ['count' => $count, 'reauthenticate' => true]);
    }


    /**
     * 当前会员的通行密钥列表。
     * @return array
     */
    public function passkeyList(): array
    {
        $user = $this->getUser();
        return $this->json(data: [
            'list' => UserWebauthnService::listForUser($user),
            'max' => UserWebauthnService::MAX_PER_USER,
            'totp' => !empty($user->totp_secret),
            //供前端调用 WebAuthn Signal API，让密码管理器隐藏已在本站删除的通行密钥
            'rp_id' => \App\Util\WebAuthn::relyingParty()[0],
            'user_handle' => UserWebauthnService::userHandle($user),
        ]);
    }

    /**
     * 添加通行密钥第一步：校验账号密码后下发注册选项。挑战只在密码校验通过后签发且绑定本会员，
     * 第二步 passkeyRegister 凭它完成——只有会话（可能被窃）加不了新的登录凭证（同 F-33 口径）。
     * @return array
     * @throws JSONException
     */
    public function passkeyRegisterOptions(): array
    {
        $user = \App\Model\User::query()->find($this->getUser()->id);
        $key = "passkeyreg:uid:" . (int)$user->id;
        if (\App\Util\Throttle::tooMany($key, 10, 300)) {
            throw new JSONException("尝试过于频繁，请稍后再试");
        }
        if (!Str::verifyPassword((string)$user->password, (string)$user->salt, (string)($_POST['password'] ?? ''), (string)$this->request->unsafePost('password'))) {
            throw new JSONException("账号密码不正确");
        }
        \App\Util\Throttle::clear($key);
        return $this->json(data: UserWebauthnService::registerOptions($user));
    }

    /**
     * 添加通行密钥第二步：校验认证器回传并保存。
     * @return array
     * @throws JSONException
     */
    public function passkeyRegister(): array
    {
        $user = $this->getUser();
        $attestationObject = (string)$this->request->post("attestationObject");
        $clientDataJSON = (string)$this->request->post("clientDataJSON");
        $rawId = (string)$this->request->post("id");
        foreach ([$attestationObject, $clientDataJSON, $rawId] as $part) {
            if (!preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $part)) {
                throw new JSONException("通行密钥数据不完整，请重试");
            }
        }
        $saved = UserWebauthnService::register(
            $user,
            (string)$this->request->unsafePost("name"),
            $attestationObject,
            $clientDataJSON,
            $rawId,
            (string)$this->request->post("transports")
        );
        \App\Model\UserLog::write($user, 'passkey_add', '添加了通行密钥「' . $saved['name'] . '」');
        return $this->json(200, "通行密钥已添加", $saved);
    }

    /**
     * 重命名通行密钥。
     * @return array
     * @throws JSONException
     */
    public function passkeyRename(): array
    {
        $id = (int)$this->request->post("id", Filter::INTEGER);
        if ($id <= 0) {
            throw new JSONException("参数无效");
        }
        $name = UserWebauthnService::rename($this->getUser(), $id, (string)$this->request->unsafePost("name"));
        return $this->json(200, "已重命名", ['name' => $name]);
    }

    /**
     * 删除通行密钥。
     * @return array
     * @throws JSONException
     */
    public function passkeyDelete(): array
    {
        $id = (int)$this->request->post("id", Filter::INTEGER);
        if ($id <= 0) {
            throw new JSONException("参数无效");
        }
        $user = $this->getUser();
        $name = UserWebauthnService::delete($user, $id);
        \App\Model\UserLog::write($user, 'passkey_remove', '删除了通行密钥「' . $name . '」');
        return $this->json(200, "已删除");
    }

    /**
     * 会员自己的安全稽核日志（仅本人，分页）。对用户只展示「浏览器 · 系统」，绝不直出原始 UA。
     * @return array
     */
    public function logs(): array
    {
        \App\Util\Schema::ensureUserLogTable();
        $uid = (int)$this->getUser()->id;
        $page = max(1, (int)($_POST['page'] ?? 1));
        $limit = 15;
        $onlyRisk = (string)($_POST['risk'] ?? '') === '1';

        $q = \App\Model\UserLog::query()->where('user_id', $uid);
        if ($onlyRisk) {
            $q->where('risk', 1);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(static function ($r): array {
            $d = \App\Util\UserAgent::describe((string)$r->ua);
            return [
                'action' => (string)$r->action,
                'content' => (string)$r->content,
                'create_time' => (string)$r->create_time,
                'create_relative' => \App\Util\Date::sauce((string)$r->create_time),
                'ip' => (string)$r->create_ip,
                'browser' => $d['browser_full'],
                'os' => $d['os'],
                'client' => $d['label_full'],
                'device_type' => $d['type'],
                'risk' => (int)$r->risk,
            ];
        })->all();

        return $this->json(data: [
            'list' => $list,
            'page' => $page,
            'total' => $total,
            'more' => ($page * $limit) < $total,
        ]);
    }

}