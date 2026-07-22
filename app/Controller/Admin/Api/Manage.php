<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Interceptor\Super;
use App\Model\ManageLog;
use App\Service\ManageSessionManager;
use App\Service\Query;
use App\Util\Str;
use App\Util\Validation;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Manage extends \App\Controller\Base\API\Manage
{

    #[Inject]
    private Query $query;

    /**
     * @param mixed $value
     * @return int[]
     * @throws JSONException
     */
    private function manageIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $candidate) {
            if (is_int($candidate)) {
                $id = $candidate;
            } elseif (is_string($candidate) && ctype_digit(trim($candidate))) {
                $id = (int)trim($candidate);
            } else {
                throw new JSONException('管理员 ID 必须是正整数');
            }
            if ($id <= 0) {
                throw new JSONException('管理员 ID 必须是正整数');
            }
            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw new JSONException('请至少选择一个管理员');
        }
        if (count($ids) > 100) {
            throw new JSONException('单次最多删除 100 个管理员');
        }
        return $ids;
    }

    /**
     * @return array
     */
    #[Interceptor(Super::class, Interceptor::TYPE_API)]
    public function data(): array
    {
        $map = array_intersect_key($_POST, array_flip([
            'search-email',
            'search-nickname',
            'equal-type',
            'search-login_ip',
            'between-create_time',
            'equal-status',
        ]));
        $page = max(1, (int)$this->request->post('page'));
        $limit = (int)$this->request->post('limit');
        if (!in_array($limit, [15, 30, 50, 100], true)) {
            $limit = 15;
        }
        $get = new Get(\App\Model\Manage::class);
        $get->setPaginate($page, $limit);
        $get->setWhere($map);
        // The list is also used to prefill the edit form. Never serialize
        // authentication material (password/salt/2FA/security password) into
        // either the desktop table or the mobile card snapshot.
        $get->setColumn(
            'id',
            'email',
            'nickname',
            'avatar',
            'status',
            'type',
            'note',
            'create_time',
            'login_time',
            'login_ip',
            'last_login_time',
            'last_login_ip'
        );
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->where("type", "!=", 0);
        });
        return $this->json(data: $data);
    }


    /**
     * @return array
     * @throws JSONException
     */
    #[Interceptor(Super::class, Interceptor::TYPE_API)]
    public function save(): array
    {
        $map = $_POST;

        $email = is_scalar($map['email'] ?? null) ? trim((string)$map['email']) : '';
        if (
            $email === ''
            || strlen($email) > 64
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\x00-\x20\x7F]/', $email)
        ) {
            throw new JSONException("邮箱格式不正确");
        }
        $map['email'] = $email;

        $rawId = $map['id'] ?? 0;
        if (!is_int($rawId) && !(is_string($rawId) && ctype_digit(trim($rawId)))) {
            throw new JSONException('管理员 ID 格式不正确');
        }
        $id = (int)$rawId;
        $map['id'] = $id;

        $nickname = is_scalar($map['nickname'] ?? null) ? trim((string)$map['nickname']) : '';
        if ($nickname === '' || mb_strlen($nickname, 'UTF-8') > 32 || preg_match('/[\x00-\x1F\x7F]/u', $nickname)) {
            throw new JSONException('昵称不能为空且不能超过 32 个字符');
        }
        $map['nickname'] = $nickname;

        $note = is_scalar($map['note'] ?? null) ? trim((string)$map['note']) : '';
        if (mb_strlen($note, 'UTF-8') > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $note)) {
            throw new JSONException('备注不能超过 255 个字符且不能包含控制字符');
        }
        $map['note'] = $note;

        $avatar = is_scalar($map['avatar'] ?? null) ? trim((string)$map['avatar']) : '';
        if ($avatar === '') {
            $avatar = '/favicon.ico';
        }
        $localAvatar = preg_match(
            '#^/assets/cache/(?:general/image|images)/[A-Za-z0-9._/-]+\.(?:jpe?g|png|webp|gif|bmp|ico)$#iD',
            $avatar
        ) === 1;
        $remoteAvatar = false;
        if (strlen($avatar) <= 128 && !preg_match('/[\x00-\x20\x7F\\\\\'"<>]/', $avatar)) {
            $parts = parse_url($avatar);
            $remoteAvatar = is_array($parts)
                && isset($parts['scheme'], $parts['host'])
                && in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
                && !isset($parts['user'], $parts['pass']);
        }
        if ($avatar !== '/favicon.ico' && !$localAvatar && !$remoteAvatar) {
            throw new JSONException('头像地址无效，请重新上传');
        }
        $map['avatar'] = $avatar;

        if (isset($map['password']) && !is_scalar($map['password'])) {
            throw new JSONException('密码格式不正确');
        }
        $plainPassword = (string)($map['password'] ?? '');
        if ($id === 0 && $plainPassword === '') {
            throw new JSONException("请设置密码");
        }
        if ($plainPassword !== '' && (mb_strlen($plainPassword, 'UTF-8') > 256 || !Validation::password($plainPassword))) {
            throw new JSONException("密码太过简单");
        }

        if (!isset($map['type']) || !is_scalar($map['type']) || !in_array((string)$map['type'], ['1', '2', '3'], true)) {
            throw new JSONException("账号类型有问题");
        }
        $map['type'] = (int)$map['type'];

        if (!isset($map['status']) || !is_scalar($map['status']) || !in_array((string)$map['status'], ['0', '1'], true)) {
            throw new JSONException('账号状态有问题');
        }
        $map['status'] = (int)$map['status'];

        $currentId = (int)($this->getManage()?->id ?? 0);
        if ($currentId <= 0) {
            throw new JSONException('当前管理员会话无效，请重新登录');
        }

        try {
            DB::transaction(function () use ($map, $id, $plainPassword, $currentId): void {
            // Match del() exactly: lock every type 0/1 account before the
            // selected record. Concurrent demotions and deletions therefore
            // cannot both observe another type 1 and remove the final one.
            $privileged = \App\Model\Manage::query()
                ->whereIn('type', [0, 1])
                ->orderBy('id')
                ->select(['id', 'type', 'status'])
                ->lockForUpdate()
                ->get();
            $actor = $privileged->firstWhere('id', $currentId);
            if (!$actor || (int)$actor->status !== 1) {
                throw new JSONException('当前管理员权限已发生变化，请重新登录');
            }

            $saveMap = $map;
            $salt = Str::generateRandStr();

            if ($id > 0) {
                $target = \App\Model\Manage::query()->lockForUpdate()->find($id);
                if (!$target) {
                    throw new JSONException("管理员不存在");
                }
                if ((int)$target->type === 0) {
                    throw new JSONException("系统保留管理员账号不能编辑");
                }

                $nextStatus = array_key_exists('status', $saveMap)
                    ? (int)$saveMap['status']
                    : (int)$target->status;
                if ((int)$target->type === 1 && ($saveMap['type'] !== 1 || $nextStatus !== 1)) {
                    $remainingSuper = $privileged->filter(
                        static fn($manage): bool =>
                            (int)$manage->type === 1
                            && (int)$manage->status === 1
                            && (int)$manage->id !== (int)$target->id
                    );
                    if ($remainingSuper->isEmpty()) {
                        throw new JSONException("至少需要保留一个启用的超级管理员账号");
                    }
                }

                $salt = $target->salt;
            }

            if ($plainPassword !== '') {
                $saveMap['salt'] = $salt;
                $saveMap['password'] = Str::generatePassword($plainPassword, $salt);
            }

            $save = new Save(\App\Model\Manage::class);
            $save->setMap($saveMap, [
                'avatar',
                'email',
                'nickname',
                'password',
                'salt',
                'type',
                'note',
                'status',
            ]);
            $save->enableCreateTime();
            if (!$this->query->save($save)) {
                throw new JSONException("保存失败，请检查信息填写是否完整");
            }
            if ($id > 0 && ($plainPassword !== '' || (int)$map['status'] !== 1)) {
                ManageSessionManager::revokeAll($id);
            }
            });
        } catch (JSONException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JSONException('管理员保存失败，请确认邮箱未被使用并重试');
        }

        $reauthenticate = $id === $currentId && ($plainPassword !== '' || (int)$map['status'] !== 1);
        if ($reauthenticate) {
            ManageSessionManager::clearCookie();
        }
        ManageLog::log($this->getManage(), "[新增/修改]管理员({$map['email']})");
        return $this->json(200, '（＾∀＾）保存成功', ['reauthenticate' => $reauthenticate]);
    }


    /**
     * @return array
     * @throws JSONException
     */
    #[Interceptor(Super::class, Interceptor::TYPE_API)]
    public function del(): array
    {
        $requestedIds = $this->manageIds($_POST['list'] ?? []);
        $currentId = (int)($this->getManage()?->id ?? 0);
        if ($currentId <= 0) {
            throw new JSONException('当前管理员会话无效，请重新登录');
        }
        if (in_array($currentId, $requestedIds, true)) {
            throw new JSONException('不能删除当前登录的管理员账号');
        }

        $count = DB::transaction(function () use ($requestedIds, $currentId): int {
            // Type 0 and type 1 both pass the existing Super interceptor. Lock
            // that complete set first so concurrent delete batches always use
            // the same lock order and cannot remove the final privileged role.
            $privileged = \App\Model\Manage::query()
                ->whereIn('type', [0, 1])
                ->orderBy('id')
                ->select(['id', 'type', 'status'])
                ->lockForUpdate()
                ->get();
            $actor = $privileged->firstWhere('id', $currentId);
            if (!$actor || (int)$actor->status !== 1) {
                throw new JSONException('当前管理员权限已发生变化，请重新登录');
            }

            $selected = \App\Model\Manage::query()
                ->whereIn('id', $requestedIds)
                ->orderBy('id')
                ->select(['id', 'type'])
                ->lockForUpdate()
                ->get();
            if ($selected->count() !== count($requestedIds)) {
                throw new JSONException('部分管理员不存在，请刷新后重试');
            }
            if ($selected->contains(static fn($manage): bool => (int)$manage->type === 0)) {
                throw new JSONException('系统保留管理员账号不能删除');
            }

            // Type 1 is also the UI's explicit “超级管理员” role and must
            // retain at least one account, even beside the protected type 0.
            $deleting = array_fill_keys($requestedIds, true);
            $remainingPrivileged = $privileged->filter(
                static fn($manage): bool => !isset($deleting[(int)$manage->id])
            );
            if ($remainingPrivileged->isEmpty()) {
                throw new JSONException('至少需要保留一个高权限管理员账号');
            }

            $deletesSuper = $selected->contains(static fn($manage): bool => (int)$manage->type === 1);
            if ($deletesSuper) {
                $remainingSuper = $remainingPrivileged->filter(
                    static fn($manage): bool =>
                        (int)$manage->type === 1 && (int)$manage->status === 1
                );
                if ($remainingSuper->isEmpty()) {
                    throw new JSONException('至少需要保留一个启用的超级管理员账号');
                }
            }

            ManageSessionManager::revokeAll($requestedIds);
            $deleted = \App\Model\Manage::query()
                ->whereIn('id', $requestedIds)
                ->where('type', '!=', 0)
                ->delete();
            if ($deleted !== count($requestedIds)) {
                throw new JSONException('管理员删除数量异常，操作已回滚，请刷新后重试');
            }
            return $deleted;
        });

        ManageLog::log($this->getManage(), "删除了管理员(ID:" . implode(',', $requestedIds) . ")");
        return $this->json(200, '（＾∀＾）移除成功', ['count' => $count]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function set(): array
    {
        $map = $_POST;
        if (!array_key_exists('nickname', $map) || !array_key_exists('avatar', $map)) {
            throw new JSONException('个人资料字段不完整，请刷新页面后重试');
        }

        $nickname = trim((string)$map['nickname']);
        if ($nickname === '' || mb_strlen($nickname) > 32 || preg_match('/[\x00-\x1F\x7F]/u', $nickname)) {
            throw new JSONException('昵称不能为空且不能超过 32 个字符');
        }

        $avatar = trim((string)$map['avatar']);
        if ($avatar === '') {
            $avatar = '/favicon.ico';
        }
        if (strlen($avatar) > 128 || preg_match('/[\x00-\x20\x7F\\\\\'"<>]/', $avatar)) {
            throw new JSONException('头像地址无效，请重新上传');
        }

        $newPassword = (string)($map['password'] ?? '');
        $repeatPassword = (string)($map['re_password'] ?? '');
        $oldPassword = (string)($map['old_password'] ?? '');
        if ($newPassword === '' && ($repeatPassword !== '' || $oldPassword !== '')) {
            throw new JSONException('请填写新密码，或清空全部密码输入框');
        }
        if ($newPassword !== '' && (mb_strlen($newPassword) > 256 || !Validation::password($newPassword))) {
            throw new JSONException('新密码不能少于 6 位或超过 256 位');
        }
        if ($newPassword !== '' && $newPassword !== $repeatPassword) {
            throw new JSONException('两次密码输入不一致');
        }
        if ($newPassword !== '' && $oldPassword === '') {
            throw new JSONException('请输入原密码');
        }

        $manageId = (int)($this->getManage()?->id ?? 0);
        if ($manageId <= 0) {
            throw new JSONException('当前管理员会话无效，请重新登录');
        }

        $passwordChanged = false;
        $updatedManage = null;
        DB::transaction(function () use (
            $manageId,
            $nickname,
            $avatar,
            $newPassword,
            $oldPassword,
            &$passwordChanged,
            &$updatedManage
        ): void {
            $manage = \App\Model\Manage::query()->lockForUpdate()->find($manageId);
            if (!$manage || (int)$manage->status !== 1) {
                throw new JSONException('当前管理员账号不可用，请重新登录');
            }

            $currentAvatar = trim((string)$manage->avatar);
            if ($avatar !== $currentAvatar && $avatar !== '/favicon.ico') {
                $validPath = preg_match(
                    '#^/assets/cache/(?:general/image|images)/[A-Za-z0-9._/-]+\.(?:jpe?g|png|webp|gif|bmp|ico)$#i',
                    $avatar
                ) === 1;
                $knownUpload = $validPath && \App\Model\Upload::query()
                    ->where('path', $avatar)
                    ->where('type', 'image')
                    ->exists();
                if (!$knownUpload) {
                    throw new JSONException('头像必须使用已上传的图片');
                }
            }

            if ($newPassword !== '') {
                $currentPassword = Str::generatePassword($oldPassword, (string)$manage->salt);
                if (!hash_equals((string)$manage->password, $currentPassword)) {
                    throw new JSONException('原密码输入不正确');
                }
                $manage->password = Str::generatePassword($newPassword, (string)$manage->salt);
                $passwordChanged = true;
            }

            $manage->avatar = $avatar;
            $manage->nickname = $nickname;
            if (!$manage->save()) {
                throw new JSONException('个人设置保存失败，请重试');
            }
            if ($passwordChanged) {
                ManageSessionManager::revokeAll($manageId);
            }
            $updatedManage = $manage;
        });

        if ($passwordChanged) {
            ManageSessionManager::clearCookie();
        }
        ManageLog::log($updatedManage, $passwordChanged ? '修改了个人资料和密码' : '修改了个人资料');
        return $this->json(200, "修改成功", ['reauthenticate' => $passwordChanged]);
    }

    /**
     * 当前管理员的有效设备会话列表。响应不包含会话标识、哈希或完整 User-Agent。
     */
    public function deviceSessions(): array
    {
        $manageId = (int)($this->getManage()?->id ?? 0);
        return $this->json(data: ['list' => ManageSessionManager::listActive($manageId)]);
    }

    /**
     * 撤销指定的其他设备会话。
     * @throws JSONException
     */
    public function revokeDeviceSession(): array
    {
        $manageId = (int)($this->getManage()?->id ?? 0);
        $rawId = $this->request->post('id');
        if ((!is_int($rawId) && !(is_string($rawId) && ctype_digit(trim($rawId)))) || (int)$rawId <= 0) {
            throw new JSONException('设备会话参数无效');
        }
        $sessionId = (int)$rawId;
        if ($sessionId === ManageSessionManager::currentSessionId()) {
            throw new JSONException('当前设备请使用“注销登录”退出');
        }
        if (!ManageSessionManager::revokeSession($manageId, $sessionId)) {
            throw new JSONException('设备会话不存在或已经退出');
        }
        ManageLog::log($this->getManage(), "退出了一个其他设备(ID:{$sessionId})");
        return $this->json(200, '该设备已退出');
    }

    /**
     * 撤销当前设备以外的全部会话。
     * @throws JSONException
     */
    public function revokeOtherDeviceSessions(): array
    {
        $manageId = (int)($this->getManage()?->id ?? 0);
        $currentId = ManageSessionManager::currentSessionId();
        if ($currentId <= 0) {
            throw new JSONException('当前设备会话无效，请重新登录');
        }
        $count = ManageSessionManager::revokeAll($manageId, $currentId);
        ManageLog::log($this->getManage(), "退出了全部其他设备({$count}个)");
        return $this->json(200, $count > 0 ? '其他设备已全部退出' : '没有其他在线设备', ['count' => $count]);
    }

    /**
     * 撤销当前管理员的全部设备会话，包括当前设备。
     */
    public function revokeAllDeviceSessions(): array
    {
        $manage = $this->getManage();
        $count = ManageSessionManager::revokeAll((int)$manage->id);
        ManageSessionManager::clearCookie();
        ManageLog::log($manage, "退出了全部设备({$count}个)");
        return $this->json(200, '全部设备已退出', ['count' => $count, 'reauthenticate' => true]);
    }


    /**
     * 谷歌验证器：当前账号是否已绑定
     * @return array
     */
    public function googleStatus(): array
    {
        return $this->json(data: ["bound" => !empty($this->getManage()->google_secret)]);
    }

    /**
     * 谷歌验证器：生成待绑定密钥（不落库，前端据 uri 生成二维码/手动录入）
     * @return array
     */
    public function googleSecret(): array
    {
        $manage = $this->getManage();
        $secret = \App\Util\Totp::generateSecret();
        $issuer = (string)(\App\Model\Config::get("shop_name") ?: "ACGFAKA");
        $uri = \App\Util\Totp::keyUri($secret, (string)$manage->email, $issuer);
        return $this->json(data: ["secret" => $secret, "uri" => $uri]);
    }

    /**
     * 谷歌验证器：绑定（校验一次动态码后保存密钥）
     * @return array
     * @throws JSONException
     */
    public function googleBind(): array
    {
        $manage = $this->getManage();
        if (!empty($manage->google_secret)) {
            //已绑定不允许直接覆盖，必须先解绑（防止绕过 UI 直接调接口换绑）
            throw new JSONException("已绑定，请先解绑后再重新绑定");
        }
        $secret = strtoupper(trim((string)$this->request->post("secret")));
        $code = (string)$this->request->post("code");
        if (!preg_match('/^[A-Z2-7]{16,64}$/', $secret)) {
            throw new JSONException("密钥格式错误");
        }
        if (!\App\Util\Totp::verify($secret, $code)) {
            throw new JSONException("验证码错误，请确认手机时间已同步后重试");
        }
        DB::transaction(function () use ($manage, $secret): void {
            $manage->google_secret = $secret;
            if (!$manage->save()) {
                throw new JSONException('绑定失败，请重试');
            }
            ManageSessionManager::revokeAll((int)$manage->id);
        });
        ManageSessionManager::clearCookie();
        ManageLog::log($manage, "绑定了谷歌验证器");
        return $this->json(200, "绑定成功，请重新登录", ['reauthenticate' => true]);
    }

    /**
     * 谷歌验证器：解绑（校验当前动态码后清除）
     * @return array
     * @throws JSONException
     */
    public function googleUnbind(): array
    {
        $manage = $this->getManage();
        if (empty($manage->google_secret)) {
            throw new JSONException("尚未绑定谷歌验证器");
        }
        if (!\App\Util\Totp::verify((string)$manage->google_secret, (string)$this->request->post("code"))) {
            throw new JSONException("验证码错误");
        }
        DB::transaction(function () use ($manage): void {
            $manage->google_secret = null;
            if (!$manage->save()) {
                throw new JSONException('解绑失败，请重试');
            }
            ManageSessionManager::revokeAll((int)$manage->id);
        });
        ManageSessionManager::clearCookie();
        ManageLog::log($manage, "解绑了谷歌验证器");
        return $this->json(200, "已解绑，请重新登录", ['reauthenticate' => true]);
    }
}
