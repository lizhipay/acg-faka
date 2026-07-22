<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\Bill;
use App\Model\Business;
use App\Model\ManageLog;
use App\Model\UserGroup;
use App\Service\Query;
use App\Util\Date;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class User extends Manage
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $this->request->post();
        $rawGroupId = $map["equal-group_id"] ?? '';
        if ($rawGroupId === '' || $rawGroupId === null) {
            $groupId = 0;
        } else {
            $groupId = filter_var($rawGroupId, FILTER_VALIDATE_INT);
            if ($groupId === false || $groupId < 1) {
                throw new JSONException('会员等级筛选不正确');
            }
        }
        unset($map["equal-group_id"]);

        $get = new Get(\App\Model\User::class);
        $get->setWhere($map);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setOrderBy(...$this->query->getOrderBy($map, "id", "desc"));
        $data = $this->query->get($get, function (Builder $builder) use ($groupId) {
            if ($groupId > 0) {
                $rechargeScope = UserGroup::getRechargeScope((int)$groupId);
                if (!$rechargeScope) {
                    throw new JSONException('会员等级不存在');
                }
                $builder = $builder->where("recharge", ">=", $rechargeScope['min']);
                if ($rechargeScope['max'] > 0) {
                    $builder = $builder->where("recharge", "<", $rechargeScope['max']);
                }
            }

            return $builder->with([
                'parent' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar"]);
                }, 'businessLevel', 'business'
            ]);
        });

        return $this->json(data: $data);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('会员资料修改只接受 POST 请求');
        }
        $map = $_POST;
        $rawGroupId = $map['group_id'] ?? '';
        if ($rawGroupId === '' || $rawGroupId === null) {
            $groupId = 0;
        } else {
            $groupId = filter_var($rawGroupId, FILTER_VALIDATE_INT);
            if ($groupId === false || $groupId < 1) {
                throw new JSONException('该会员等级不存在');
            }
        }

        unset($map['balance']);
        unset($map['coin']);
        unset($map['group_id']);

        $userId = filter_var($map['id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            throw new JSONException("该用户不存在");
        }
        $map['id'] = (int)$userId;

        foreach (['avatar', 'username', 'email', 'phone', 'qq'] as $field) {
            if (array_key_exists($field, $map) && !is_scalar($map[$field]) && $map[$field] !== null) {
                throw new JSONException('会员资料格式不正确');
            }
        }

        if (array_key_exists('password', $map)) {
            if (!is_scalar($map['password']) && $map['password'] !== null) {
                throw new JSONException('密码格式不正确');
            }
            $map['password'] = (string)($map['password'] ?? '');
        }
        if (array_key_exists('status', $map)) {
            $status = filter_var($map['status'], FILTER_VALIDATE_INT);
            if ($status === false || !in_array($status, [0, 1], true)) {
                throw new JSONException('会员状态不正确');
            }
            $map['status'] = (int)$status;
        }
        if (array_key_exists('pid', $map)) {
            $rawPid = $map['pid'];
            $pid = ($rawPid === '' || $rawPid === null) ? 0 : filter_var($rawPid, FILTER_VALIDATE_INT);
            if ($pid === false || $pid < 0 || $pid === (int)$userId) {
                throw new JSONException('上级会员 ID 不正确');
            }
            $map['pid'] = (int)$pid;
        }

        $businessLevelProvided = array_key_exists('business_level', $map);
        $rawBusinessLevelId = $map['business_level'] ?? null;
        $businessLevelId = 0;
        if ($rawBusinessLevelId !== null && $rawBusinessLevelId !== '') {
            if (is_int($rawBusinessLevelId)) {
                $businessLevelId = $rawBusinessLevelId;
            } elseif (is_string($rawBusinessLevelId) && ctype_digit(trim($rawBusinessLevelId))) {
                $businessLevelId = (int)trim($rawBusinessLevelId);
            } else {
                throw new JSONException("该商户等级不存在");
            }
            $map['business_level'] = $businessLevelId;
        } elseif ($businessLevelProvided) {
            $map['business_level'] = null;
        }
        if ($businessLevelId < 0) {
            throw new JSONException("该商户等级不存在");
        }

        if (!empty($map['password']) && strlen((string)$map['password']) < 6) {
            throw new JSONException("密码必须6位以上");
        }

        $user = DB::transaction(function () use (
            $map,
            $groupId,
            $userId,
            $businessLevelId,
            $businessLevelProvided
        ) {
            $saveMap = $map;

            $observedBusinessLevelId = 0;
            if ($businessLevelProvided) {
                $observedUser = \App\Model\User::query()
                    ->select(['id', 'business_level'])
                    ->find($userId);
                if (!$observedUser) {
                    throw new JSONException("该用户不存在");
                }
                $observedBusinessLevelId = (int)$observedUser->business_level;

                // Changing the indexed business_level value removes the old
                // secondary-index entry as well as adding the new one. Lock
                // both level rows in ascending order before the user so this
                // matches BusinessLevel::del() for either side of the change.
                $levelIds = array_values(array_unique(array_filter(
                    [$observedBusinessLevelId, $businessLevelId],
                    static fn(int $id): bool => $id > 0
                )));
                sort($levelIds, SORT_NUMERIC);
                $lockedLevels = \App\Model\BusinessLevel::query()
                    ->whereIn('id', $levelIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id'])
                    ->keyBy('id');
                if ($businessLevelId > 0 && !$lockedLevels->has($businessLevelId)) {
                    throw new JSONException("该商户等级不存在");
                }
            }

            $user = \App\Model\User::query()->lockForUpdate()->find($userId);
            if (!$user) {
                throw new JSONException("该用户不存在");
            }
            if ($businessLevelProvided && (int)$user->business_level !== $observedBusinessLevelId) {
                throw new JSONException("会员的商户等级已发生变化，请刷新后重试");
            }

            if (!empty($saveMap['password'])) {
                $saveMap['password'] = Str::generatePassword((string)$saveMap['password'], $user->salt);
            }

            if ($businessLevelId > 0 && !Business::query()->where("user_id", $user->id)->first()) {
                $business = new Business();
                $business->user_id = $user->id;
                $business->create_time = Date::current();
                $business->master_display = 1;
                $business->save();
            }

            if ($groupId > 0 && $groupId != (int)($user->group?->id ?? 0)) {
                $group = UserGroup::query()->find($groupId);
                if (!$group) {
                    throw new JSONException('该会员等级不存在');
                }
                $saveMap['recharge'] = $group->recharge;
            }

            $save = new Save(\App\Model\User::class);
            $save->setMap($saveMap);
            $save->disableAddable();
            $save->modifiableWhitelist = [
                'avatar', 'username', 'email', 'phone', 'qq', 'password',
                'pid', 'status', 'business_level', 'recharge',
            ];
            $savedUser = $this->query->save($save);
            if (!$savedUser) {
                throw new JSONException("保存失败，请检查信息填写是否完整");
            }

            return $savedUser;
        });

        ManageLog::log($this->getManage(), "修改了会员($user->username)的信息。");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    /**
     * @throws JSONException
     */
    public function recharge(): array
    {
        $user = $this->changeAccountBalance(0);
        ManageLog::log($this->getManage(), "为会员($user->username)进行了余额变动操作，详情查看账变明细");
        return $this->json(200, "操作成功");
    }

    /**
     * @throws JSONException
     */
    public function coin(): array
    {
        $user = $this->changeAccountBalance(1);
        ManageLog::log($this->getManage(), "为会员($user->username)进行了硬币变动操作，详情查看账变明细");
        return $this->json(200, "操作成功");
    }

    /**
     * @throws JSONException
     */
    private function changeAccountBalance(int $currency): \App\Model\User
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('会员资产变动只接受 POST 请求');
        }

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $action = filter_var($_POST['action'] ?? null, FILTER_VALIDATE_INT);
        $rawAmountValue = $_POST['amount'] ?? '';
        $rawLog = $_POST['log'] ?? '';
        $rawTotal = $_POST['total'] ?? 0;
        if ((!is_scalar($rawAmountValue) && $rawAmountValue !== null)
            || (!is_scalar($rawLog) && $rawLog !== null)
            || (!is_scalar($rawTotal) && $rawTotal !== null)) {
            throw new JSONException('会员资产变动参数不正确');
        }
        $rawAmount = trim((string)$rawAmountValue);
        $log = trim((string)$rawLog);
        $totalValue = filter_var($rawTotal, FILTER_VALIDATE_INT);
        if ($totalValue === false || !in_array($totalValue, [0, 1], true)) {
            throw new JSONException('累计统计选项不正确');
        }
        $total = $totalValue === 1;

        if ($id === false || $id < 1) {
            throw new JSONException('用户不存在');
        }
        if ($action === false || !in_array($action, [Bill::TYPE_SUB, Bill::TYPE_ADD], true)) {
            throw new JSONException('请选择增加或扣减');
        }
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $rawAmount)) {
            throw new JSONException('请输入大于 0 且最多两位小数的操作数量');
        }
        $amount = (float)$rawAmount;
        if (!is_finite($amount) || $amount <= 0 || $amount > 99999999.99) {
            throw new JSONException('操作数量必须大于 0 且不超过 99999999.99');
        }
        $logLength = mb_strlen($log);
        if ($logLength < 2 || $logLength > 64) {
            throw new JSONException('操作原因须为 2–64 个字');
        }

        return DB::transaction(function () use ($id, $amount, $action, $log, $currency, $total) {
            $user = \App\Model\User::query()->where('id', (int)$id)->lockForUpdate()->first();
            if (!$user) {
                throw new JSONException('用户不存在');
            }
            Bill::create($user, $amount, (int)$action, $log, $currency, $total);
            return $user;
        });
    }

    /**
     * @return array
     */
    public function statistics(): array
    {
        $userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            throw new JSONException('用户不存在');
        }
        $order = \App\Model\Order::query()->where("user_id", $userId)->where("status", 1);
        $data = [];
        //今日交易
        $data['today_order_amount'] = sprintf("%.2f", (clone $order)->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->sum("amount"));
        //昨日交易
        $data['yesterday_order_amount'] = sprintf("%.2f", (clone $order)->whereBetween('create_time', [Date::calcDay(-1), Date::calcDay()])->sum("amount"));
        //本周交易
        $data['week_order_amount'] = sprintf("%.2f", (clone $order)->whereBetween('create_time', [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)])->sum("amount"));
        //本月交易
        $data['month_order_amount'] = sprintf("%.2f", (clone $order)->whereBetween('create_time', [date("Y-m-01 00:00:00"), Date::calcDay()])->sum("amount"));
        //全部交易
        $data['total_order_amount'] = sprintf("%.2f", (clone $order)->sum("amount"));

        return $this->json(200, "success", $data);
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function del(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('会员删除只接受 POST 请求');
        }
        $rawList = $_POST['list'] ?? null;
        if (!is_array($rawList)) {
            throw new JSONException('请选择要删除的会员');
        }
        $list = [];
        foreach ($rawList as $rawId) {
            $value = is_scalar($rawId) ? trim((string)$rawId) : '';
            if (!ctype_digit($value) || (int)$value < 1) {
                throw new JSONException('会员删除列表包含无效编号');
            }
            $list[] = (int)$value;
        }
        $list = array_values(array_unique($list));
        sort($list, SORT_NUMERIC);
        if (!$list || count($list) > 1000) {
            throw new JSONException('单次只能删除 1–1000 名有效会员');
        }

        $count = DB::transaction(function () use ($list): int {
            $observed = \App\Model\User::query()
                ->whereIn('id', $list)
                ->orderBy('id')
                ->get(['id', 'business_level']);
            if ($observed->count() !== count($list)) {
                throw new JSONException('会员数据已变化，请刷新列表后重试');
            }

            $levelIds = $observed->pluck('business_level')
                ->map(static fn($id): int => (int)$id)
                ->filter(static fn(int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();
            if ($levelIds) {
                \App\Model\BusinessLevel::query()
                    ->whereIn('id', $levelIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
            }
            $locked = \App\Model\User::query()
                ->whereIn('id', $list)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'business_level']);
            if ($locked->count() !== count($list)) {
                throw new JSONException('会员数据已变化，请刷新列表后重试');
            }
            $observedLevels = $observed->mapWithKeys(static fn($user): array => [(int)$user->id => (int)$user->business_level]);
            foreach ($locked as $user) {
                if (($observedLevels[(int)$user->id] ?? null) !== (int)$user->business_level) {
                    throw new JSONException('会员的商户等级已发生变化，请刷新后重试');
                }
            }

            $deleted = $this->query->delete(new Delete(\App\Model\User::class, $list));
            if ($deleted !== count($list)) {
                throw new JSONException('会员删除数量不一致，已取消本次操作');
            }

            Business::query()->whereIn('user_id', $list)->delete();
            return $deleted;
        });

        ManageLog::log($this->getManage(), "删除了会员，共计删除：{$count}");
        return $this->json(200, '（＾∀＾）移除成功', ['count' => $count]);
    }

    /**
     * @throws JSONException
     */
    public function shopClosed(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('关闭店铺只接受 POST 请求');
        }
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new JSONException('商家不存在');
        }

        $user = DB::transaction(function () use ($id) {
            $observedUser = \App\Model\User::query()
                ->select(['id', 'business_level'])
                ->find($id);
            if (!$observedUser) {
                throw new JSONException("商家不存在");
            }
            $observedBusinessLevelId = (int)$observedUser->business_level;
            if ($observedBusinessLevelId > 0) {
                \App\Model\BusinessLevel::query()
                    ->where('id', $observedBusinessLevelId)
                    ->lockForUpdate()
                    ->first();
            }

            $user = \App\Model\User::query()->lockForUpdate()->find($id);
            if (!$user) {
                throw new JSONException("商家不存在");
            }
            if ((int)$user->business_level !== $observedBusinessLevelId) {
                throw new JSONException("会员的商户等级已发生变化，请刷新后重试");
            }

            $user->business_level = null;
            $user->save();
            Business::query()->where("user_id", $id)->delete();

            return $user;
        });

        ManageLog::log($this->getManage(), "关闭了会员($user->username)的店铺");
        return $this->json(200, '（＾∀＾）关闭成功');
    }

    /**
     * @throws JSONException
     */
    public function fastUpdateUserGroup(): array
    {
        if (strtoupper($this->request->method()) !== 'POST') {
            throw new JSONException('批量修改会员等级只接受 POST 请求');
        }
        $rawList = $_POST['list'] ?? '';
        if (!is_scalar($rawList)) {
            throw new JSONException('请选择要修改的会员');
        }
        $list = [];
        foreach (explode(',', (string)$rawList) as $rawId) {
            $value = trim($rawId);
            if (!ctype_digit($value) || (int)$value < 1) {
                throw new JSONException('会员列表包含无效编号');
            }
            $list[] = (int)$value;
        }
        $list = array_values(array_unique($list));
        sort($list, SORT_NUMERIC);
        if (!$list || count($list) > 1000) {
            throw new JSONException('单次只能修改 1–1000 名有效会员');
        }

        $groupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT);
        if ($groupId === false || $groupId < 1) {
            throw new JSONException("请选择会员等级再进行操作！");
        }

        $update = DB::transaction(function () use ($list, $groupId): int {
            $group = UserGroup::query()->where('id', (int)$groupId)->lockForUpdate()->first();
            if (!$group) {
                throw new JSONException("请选择会员等级再进行操作！");
            }
            $users = \App\Model\User::query()
                ->whereIn('id', $list)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            if ($users->count() !== count($list)) {
                throw new JSONException('会员数据已变化，请刷新列表后重试');
            }

            \App\Model\User::query()->whereIn('id', $list)->update([
                "recharge" => $group->recharge
            ]);
            return count($list);
        });

        ManageLog::log($this->getManage(), "批量操作了会员的等级，共计：{$update}");
        return $this->json(200, '更新成功', ['count' => $update]);
    }
}
