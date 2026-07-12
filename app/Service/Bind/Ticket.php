<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Model\Business;
use App\Model\Commodity;
use App\Model\Config;
use App\Model\Manage;
use App\Model\ManageLog;
use App\Model\Order;
use App\Model\Ticket as TicketModel;
use App\Model\TicketMessage;
use App\Model\Upload as UploadModel;
use App\Model\User;
use App\Model\UserCommodity;
use App\Model\UserGroup;
use App\Util\Client;
use App\Util\Date;
use App\Util\Throttle;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Util\File;

class Ticket implements \App\Service\Ticket
{
    private const MAX_CONTENT_BYTES = 102400;
    private const MAX_CONTENT_IMAGES = 8;
    private const MAX_IMAGE_BYTES = 10485760;

    #[Inject]
    private \App\Service\Upload $upload;

    #[Inject]
    private \App\Service\Shop $shop;

    #[Inject]
    private \App\Service\Message $messageService;

    private ?\HTMLPurifier $purifier = null;

    public function ready(): bool
    {
        try {
            $schema = DB::schema();
            return $schema->hasTable('ticket') && $schema->hasTable('ticket_message');
        } catch (\Throwable) {
            return false;
        }
    }

    private function requireReady(): void
    {
        if (!$this->ready()) {
            throw new JSONException('工单数据库尚未升级，请先完成 3.5.1 数据库升级');
        }
    }

    private function tooMany(string $key, int $limit, int $window): bool
    {
        $directory = BASE_PATH . '/runtime/ticket-throttle-lock';
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return Throttle::tooMany($key, $limit, $window);
        }
        $handle = @fopen($directory . '/' . hash('sha256', $key) . '.lock', 'c+');
        if (!$handle) {
            return Throttle::tooMany($key, $limit, $window);
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return Throttle::tooMany($key, $limit, $window);
            }
            rewind($handle);
            $record = json_decode((string)stream_get_contents($handle), true);
            $now = time();
            $count = 0;
            $reset = $now + max(1, $window);
            if (is_array($record) && (int)($record['reset'] ?? 0) > $now) {
                $count = max(0, (int)($record['count'] ?? 0));
                $reset = (int)$record['reset'];
            }

            $count++;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string)json_encode(['count' => $count, 'reset' => $reset]));
            fflush($handle);
            return $count > max(0, $limit);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function page(array $filter): int
    {
        return max(1, (int)($filter['page'] ?? 1));
    }

    private function limit(array $filter, int $default = 20, int $maximum = 100): int
    {
        $limit = (int)($filter['limit'] ?? $default);
        return max(1, min($maximum, $limit > 0 ? $limit : $default));
    }

    private function filterValue(array $filter, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $filter) && $filter[$key] !== '') {
                return $filter[$key];
            }
        }
        return null;
    }

    private function withContext(Builder $builder): Builder
    {
        return $builder->with([
            'user' => function (Relation $relation) {
                $relation->select(['id', 'username', 'avatar']);
            },
            'commodity' => function (Relation $relation) {
                $relation->select(['id', 'name', 'cover']);
            },
            'order' => function (Relation $relation) {
                $relation->select([
                    'id', 'owner', 'trade_no', 'commodity_id', 'amount', 'card_num',
                    'status', 'delivery_status', 'create_time', 'pay_time',
                ]);
            },
            'closedBy' => function (Relation $relation) {
                $relation->select(['id', 'nickname', 'avatar']);
            },
        ]);
    }

    private function applyFilters(Builder $builder, array $filter, bool $admin): Builder
    {
        $status = $this->filterValue($filter, 'status', 'equal-status');
        if ($status !== null && in_array((int)$status, [0, 1, 2, 3], true)) {
            $builder->where('status', (int)$status);
        }

        $type = $this->filterValue($filter, 'type', 'equal-type');
        if ($type !== null && in_array((int)$type, [0, 1], true)) {
            $builder->where('type', (int)$type);
        }

        $priority = $this->filterValue($filter, 'priority', 'equal-priority');
        if ($priority !== null && in_array((int)$priority, [0, 1, 2], true)) {
            $builder->where('priority', (int)$priority);
        }

        if ($admin) {
            $userId = $this->filterValue($filter, 'equal-user_id', 'user_id');
            if ($userId !== null && (int)$userId > 0) {
                $builder->where('user_id', (int)$userId);
            }
        }

        $tradeNo = trim(strip_tags((string)($filter['order_trade_no'] ?? '')));
        if ($tradeNo !== '') {
            $builder->where('order_trade_no', 'like', '%' . $tradeNo . '%');
        }

        $start = $this->filterValue($filter, 'betweenStart-create_time', 'create_time_start');
        if (is_string($start) && strtotime($start) !== false) {
            $builder->where('create_time', '>=', date('Y-m-d H:i:s', strtotime($start)));
        }

        $end = $this->filterValue($filter, 'betweenEnd-create_time', 'create_time_end');
        if (is_string($end) && strtotime($end) !== false) {
            $builder->where('create_time', '<=', date('Y-m-d H:i:s', strtotime($end)));
        }

        $keyword = trim(strip_tags((string)($filter['keyword'] ?? $filter['keywords'] ?? '')));
        if ($keyword !== '') {
            $builder->where(function (Builder $query) use ($keyword, $admin) {
                $query->where('ticket_no', 'like', '%' . $keyword . '%')
                    ->orWhere('title', 'like', '%' . $keyword . '%')
                    ->orWhere('commodity_name', 'like', '%' . $keyword . '%')
                    ->orWhere('order_trade_no', 'like', '%' . $keyword . '%');

                if ($admin) {
                    $query->orWhereHas('user', function (Builder $userQuery) use ($keyword) {
                        $userQuery->where('username', 'like', '%' . $keyword . '%');
                    });
                }
            });
        }

        return $builder;
    }

    private function stats(Builder $base): array
    {
        $rows = (clone $base)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->get();

        $counts = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
        foreach ($rows as $row) {
            $counts[(int)$row->status] = (int)$row->aggregate;
        }
        $todayQuery = clone $base;
        $today = (int)$todayQuery->whereBetween('create_time', [Date::calcDay(), Date::calcDay(1)])->count();

        return [
            'pending_admin' => $counts[TicketModel::STATUS_PENDING_ADMIN],
            'pending_user' => $counts[TicketModel::STATUS_PENDING_USER],
            'resolved' => $counts[TicketModel::STATUS_RESOLVED],
            'closed' => $counts[TicketModel::STATUS_CLOSED],
            'today' => $today,
        ];
    }

    private function typeText(int $type): string
    {
        return $type === TicketModel::TYPE_AFTER_SALE ? '售后支持' : '售前咨询';
    }

    private function priorityText(int $priority): string
    {
        return match ($priority) {
            TicketModel::PRIORITY_HIGH => '高',
            TicketModel::PRIORITY_MEDIUM => '中',
            default => '低',
        };
    }

    private function statusText(int $status): string
    {
        return match ($status) {
            TicketModel::STATUS_PENDING_USER => '待用户回复',
            TicketModel::STATUS_RESOLVED => '已解决',
            TicketModel::STATUS_CLOSED => '已关闭',
            default => '待客服回复',
        };
    }

    private function senderText(?int $sender): string
    {
        return match ($sender) {
            TicketMessage::SENDER_USER => '用户',
            TicketMessage::SENDER_MANAGE => '管理员',
            TicketMessage::SENDER_SYSTEM => '系统',
            default => '',
        };
    }

    private function orderSourceText(int $source): string
    {
        return match ($source) {
            TicketModel::ORDER_SOURCE_MEMBER => '会员订单',
            TicketModel::ORDER_SOURCE_GUEST => '游客订单（待人工核验）',
            default => '无关联订单',
        };
    }

    private function normalizeTicket(TicketModel $ticket, bool $admin = false, bool $detail = false): array
    {
        $user = $ticket->relationLoaded('user') ? $ticket->getRelation('user') : null;
        $commodity = $ticket->relationLoaded('commodity') ? $ticket->getRelation('commodity') : null;
        $order = $ticket->relationLoaded('order') ? $ticket->getRelation('order') : null;
        $closedBy = $ticket->relationLoaded('closedBy') ? $ticket->getRelation('closedBy') : null;
        $orderSource = $this->orderSourceText((int)$ticket->order_source);

        $guestRedacted = !$admin && (int)$ticket->order_source === TicketModel::ORDER_SOURCE_GUEST;
        $commodityData = !$guestRedacted && $commodity ? [
            'id' => (int)$commodity->id,
            'name' => (string)($ticket->commodity_name ?: strip_tags((string)$commodity->name)),
            'cover' => (string)$commodity->cover,
        ] : null;

        $orderData = null;
        if ($order && !$guestRedacted) {
            $orderData = [
                'id' => (int)$order->id,
                'trade_no' => (string)$order->trade_no,
                'create_time' => (string)$order->create_time,
            ];
            $orderData += [
                'amount' => (float)$order->amount,
                'card_num' => (int)$order->card_num,
                'status' => (int)$order->status,
                'delivery_status' => (int)$order->delivery_status,
                'pay_time' => $order->pay_time,
            ];
        }

        $data = [
            'id' => (int)$ticket->id,
            'ticket_no' => (string)$ticket->ticket_no,
            'user_id' => (int)$ticket->user_id,
            'type' => (int)$ticket->type,
            'type_text' => $this->typeText((int)$ticket->type),
            'priority' => (int)$ticket->priority,
            'priority_text' => $this->priorityText((int)$ticket->priority),
            'status' => (int)$ticket->status,
            'status_text' => $this->statusText((int)$ticket->status),
            'title' => (string)$ticket->title,
            'commodity_id' => $guestRedacted ? null : $ticket->commodity_id,
            'commodity_name' => $guestRedacted ? null : $ticket->commodity_name,
            'order_id' => $guestRedacted ? null : $ticket->order_id,
            'order_trade_no' => $ticket->order_trade_no,
            'order_source' => (int)$ticket->order_source,
            'order_source_text' => $orderSource,
            'order_verification_pending' => $guestRedacted,
            'last_message_id' => $ticket->last_message_id,
            'last_sender_type' => $ticket->last_sender_type,
            'last_sender_text' => $this->senderText($ticket->last_sender_type),
            'last_message_excerpt' => (string)($ticket->last_message_excerpt ?? ''),
            'last_message_time' => $ticket->last_message_time,
            'user_unread' => (int)$ticket->user_unread,
            'manage_unread' => (int)$ticket->manage_unread,
            'closed_by' => $ticket->closed_by,
            'closed_time' => $ticket->closed_time,
            'create_time' => (string)$ticket->create_time,
            'update_time' => (string)$ticket->update_time,
            'user' => $user ? [
                'id' => (int)$user->id,
                'username' => (string)$user->username,
                'avatar' => (string)$user->avatar,
            ] : null,
            'commodity' => $commodityData,
            'order' => $orderData,
            'context' => [
                'commodity' => $commodityData,
                'commodity_name' => $guestRedacted ? null : $ticket->commodity_name,
                'order' => $orderData,
                'order_trade_no' => $ticket->order_trade_no,
                'order_source' => (int)$ticket->order_source,
                'order_source_text' => $orderSource,
                'order_verification_pending' => $guestRedacted,
            ],
            'closed_by_manage' => $closedBy ? [
                'id' => (int)$closedBy->id,
                'nickname' => (string)$closedBy->nickname,
                'avatar' => (string)$closedBy->avatar,
            ] : null,
        ];

        // 凭证路径不进入列表响应，避免无关页面扩大敏感静态地址的暴露面。
        if ($detail) {
            $data['proof_upload_id'] = $ticket->proof_upload_id;
            $data['proof_path'] = $ticket->proof_path;
            $data['proof'] = $ticket->proof_path ? [
                'upload_id' => $ticket->proof_upload_id,
                'url' => (string)$ticket->proof_path,
            ] : null;
        }

        return $data;
    }

    private function normalizeMessage(TicketMessage $message): array
    {
        return [
            'id' => (int)$message->id,
            'sender_type' => (int)$message->sender_type,
            'sender_name' => (string)$message->sender_name,
            'kind' => (int)$message->kind,
            'content' => (string)($message->content ?? ''),
            'create_time' => (string)$message->create_time,
        ];
    }

    public function userData(User $user, array $filter): array
    {
        if (!$this->ready()) {
            return [
                'ready' => false,
                'list' => [],
                'total' => 0,
                'stats' => ['pending_admin' => 0, 'pending_user' => 0, 'resolved' => 0, 'closed' => 0, 'today' => 0],
            ];
        }
        $base = TicketModel::query()->where('user_id', $user->id);
        $stats = $this->stats(clone $base);
        $query = $this->applyFilters(clone $base, $filter, false);
        $this->withContext($query);
        $query->orderByRaw('CASE status WHEN 0 THEN 0 WHEN 1 THEN 1 ELSE 2 END')
            ->orderBy('priority', 'desc')
            ->orderBy('last_message_time', 'desc')
            ->orderBy('id', 'desc');

        $paginate = $query->paginate($this->limit($filter), ['*'], '', $this->page($filter));
        $list = array_map(fn(TicketModel $ticket) => $this->normalizeTicket($ticket), $paginate->items());

        return ['list' => $list, 'total' => (int)$paginate->total(), 'stats' => $stats];
    }

    public function adminData(array $filter): array
    {
        if (!$this->ready()) {
            return [
                'ready' => false,
                'list' => [],
                'total' => 0,
                'stats' => ['pending_admin' => 0, 'pending_user' => 0, 'resolved' => 0, 'closed' => 0, 'today' => 0],
            ];
        }
        $base = TicketModel::query();
        $stats = $this->stats(clone $base);
        $query = $this->applyFilters(clone $base, $filter, true);
        $this->withContext($query);
        $query->orderByRaw('CASE status WHEN 0 THEN 0 WHEN 1 THEN 1 ELSE 2 END')
            ->orderBy('priority', 'desc')
            ->orderBy('last_message_time', 'desc')
            ->orderBy('id', 'desc');

        $paginate = $query->paginate($this->limit($filter), ['*'], '', $this->page($filter));
        $list = array_map(fn(TicketModel $ticket) => $this->normalizeTicket($ticket, true), $paginate->items());

        return ['list' => $list, 'total' => (int)$paginate->total(), 'stats' => $stats];
    }

    private function initialMessages(int $ticketId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $messages = TicketMessage::query()
            ->where('ticket_id', $ticketId)
            ->orderBy('id', 'desc')
            ->limit($limit + 1)
            ->get()
            ->all();

        $hasMore = count($messages) > $limit;
        if ($hasMore) {
            array_pop($messages);
        }
        $messages = array_reverse($messages);

        return [
            'list' => array_map(fn(TicketMessage $message) => $this->normalizeMessage($message), $messages),
            'has_more' => $hasMore,
        ];
    }

    public function userDetail(User $user, int $id, int $limit = 30): array
    {
        $this->requireReady();
        [$ticket, $messages] = DB::transaction(function () use ($user, $id, $limit) {
            $query = TicketModel::query()->where('user_id', $user->id)->lockForUpdate();
            $this->withContext($query);
            $ticket = $query->find($id);
            if (!$ticket) {
                throw new JSONException('工单不存在');
            }
            $messages = $this->initialMessages((int)$ticket->id, $limit);
            $ticket->user_unread = 0;
            $ticket->save();
            return [$ticket, $messages];
        });

        return [
            'ticket' => $this->normalizeTicket($ticket, false, true),
            'messages' => $messages['list'],
            'has_more' => $messages['has_more'],
        ];
    }

    public function adminDetail(Manage $manage, int $id, int $limit = 30): array
    {
        $this->requireReady();
        [$ticket, $messages] = DB::transaction(function () use ($id, $limit) {
            $query = TicketModel::query()->lockForUpdate();
            $this->withContext($query);
            $ticket = $query->find($id);
            if (!$ticket) {
                throw new JSONException('工单不存在');
            }
            $messages = $this->initialMessages((int)$ticket->id, $limit);
            $ticket->manage_unread = 0;
            $ticket->save();
            return [$ticket, $messages];
        });

        return [
            'ticket' => $this->normalizeTicket($ticket, true, true),
            'messages' => $messages['list'],
            'has_more' => $messages['has_more'],
        ];
    }

    private function incrementalMessages(TicketModel $ticket, int $afterId, int $beforeId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $query = TicketMessage::query()->where('ticket_id', $ticket->id);
        $hasMore = false;
        if ($beforeId > 0) {
            $items = $query->where('id', '<', $beforeId)
                ->orderBy('id', 'desc')
                ->limit($limit + 1)
                ->get()
                ->all();
            $hasMore = count($items) > $limit;
            if ($hasMore) {
                array_pop($items);
            }
            $items = array_reverse($items);
        } else {
            $items = $query->where('id', '>', max(0, $afterId))
                ->orderBy('id', 'asc')
                ->limit($limit)
                ->get()
                ->all();
        }

        return [
            'list' => array_map(fn(TicketMessage $message) => $this->normalizeMessage($message), $items),
            'status' => (int)$ticket->status,
            'last_message_time' => $ticket->last_message_time,
            'has_more' => $hasMore,
        ];
    }

    public function userMessages(User $user, int $id, int $afterId = 0, int $beforeId = 0, int $limit = 50): array
    {
        $this->requireReady();
        return DB::transaction(function () use ($user, $id, $afterId, $beforeId, $limit) {
            $ticket = TicketModel::query()->where('user_id', $user->id)->lockForUpdate()->find($id);
            if (!$ticket) {
                throw new JSONException('工单不存在');
            }
            $messages = $this->incrementalMessages($ticket, $afterId, $beforeId, $limit);
            $ticket->user_unread = 0;
            $ticket->save();
            return $messages;
        });
    }

    public function adminMessages(Manage $manage, int $id, int $afterId = 0, int $beforeId = 0, int $limit = 50): array
    {
        $this->requireReady();
        return DB::transaction(function () use ($id, $afterId, $beforeId, $limit) {
            $ticket = TicketModel::query()->lockForUpdate()->find($id);
            if (!$ticket) {
                throw new JSONException('工单不存在');
            }
            $messages = $this->incrementalMessages($ticket, $afterId, $beforeId, $limit);
            $ticket->manage_unread = 0;
            $ticket->save();
            return $messages;
        });
    }

    private function visibleCommodityQuery(User $user): Builder
    {
        $query = Commodity::query()->where('status', 1);
        $business = Business::get();

        if ($business) {
            if ((int)$business->master_display === 0) {
                $query->where('owner', $business->user_id);
            } else {
                $hidden = UserCommodity::query()
                    ->where('user_id', $business->user_id)
                    ->where('status', 0)
                    ->pluck('commodity_id')
                    ->map(fn($id) => (int)$id)
                    ->all();
                if ($hidden) {
                    $query->whereNotIn('id', $hidden);
                }
                $query->whereIn('owner', [0, (int)$business->user_id]);
            }
        } elseif ((int)Config::get('substation_display') === 1) {
            $owners = [0];
            foreach ((array)json_decode((string)Config::get('substation_display_list'), true) as $owner) {
                if ((int)$owner > 0) {
                    $owners[] = (int)$owner;
                }
            }
            $query->whereIn('owner', array_values(array_unique($owners)));
        } else {
            $query->where('owner', 0);
        }

        $categoryIds = [];
        foreach ($this->shop->getCategory(UserGroup::get((float)$user->recharge)) as $category) {
            if (isset($category['id']) && is_numeric($category['id'])) {
                $categoryIds[] = (int)$category['id'];
            }
        }
        if (!$categoryIds) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereIn('category_id', array_values(array_unique($categoryIds)));

        $visibleHidden = [];
        $group = UserGroup::get((float)$user->recharge);
        foreach ((clone $query)->where('hide', 1)->get(['id', 'level_price']) as $commodity) {
            try {
                $config = Commodity::parseGroupConfig((string)$commodity->level_price, $group);
                if ($config && (int)($config['show'] ?? 0) === 1) {
                    $visibleHidden[] = (int)$commodity->id;
                }
            } catch (\Throwable) {
            }
        }

        return $query->where(function (Builder $visibility) use ($visibleHidden) {
            $visibility->where('hide', 0);
            if ($visibleHidden) {
                $visibility->orWhereIn('id', $visibleHidden);
            }
        });
    }

    private function commodityDisplayName(Commodity $commodity): string
    {
        $name = (string)$commodity->name;
        $business = Business::get();
        if ($business) {
            $custom = UserCommodity::query()
                ->where('user_id', $business->user_id)
                ->where('commodity_id', $commodity->id)
                ->where('status', 1)
                ->first(['name']);
            if ($custom && trim((string)$custom->name) !== '') {
                $name = (string)$custom->name;
            }
        }
        return trim(strip_tags(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public function commodityOptions(User $user, array $filter): array
    {
        $this->requireReady();
        $query = $this->visibleCommodityQuery($user)->with([
            'category' => function (Relation $relation) {
                $relation->select(['id', 'name']);
            },
        ]);

        $keyword = trim(strip_tags((string)($filter['keyword'] ?? $filter['keywords'] ?? '')));
        if ($keyword !== '') {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        $paginate = $query->orderBy('sort', 'asc')->orderBy('id', 'desc')
            ->paginate($this->limit($filter, 20, 50), ['id', 'name', 'cover', 'category_id'], '', $this->page($filter));

        $list = [];
        foreach ($paginate->items() as $commodity) {
            $list[] = [
                'id' => (int)$commodity->id,
                'name' => $this->commodityDisplayName($commodity),
                'cover' => (string)($commodity->cover ?: '/favicon.ico'),
                'category' => $commodity->category ? strip_tags((string)$commodity->category->name) : '',
                'category_name' => $commodity->category ? strip_tags((string)$commodity->category->name) : '',
            ];
        }

        return ['list' => $list, 'total' => (int)$paginate->total()];
    }

    public function orderOptions(User $user, array $filter): array
    {
        $this->requireReady();
        $query = Order::query()
            ->where('owner', $user->id)
            ->where('status', 1)
            ->with(['commodity' => function (Relation $relation) {
                $relation->select(['id', 'name', 'cover']);
            }]);

        $keyword = trim(strip_tags((string)($filter['keyword'] ?? $filter['keywords'] ?? '')));
        if ($keyword !== '') {
            $query->where(function (Builder $search) use ($keyword) {
                $search->where('trade_no', 'like', '%' . $keyword . '%')
                    ->orWhereHas('commodity', function (Builder $commodity) use ($keyword) {
                        $commodity->where('name', 'like', '%' . $keyword . '%');
                    });
            });
        }

        $paginate = $query->orderBy('id', 'desc')
            ->paginate($this->limit($filter, 20, 50), ['*'], '', $this->page($filter));
        $list = [];
        foreach ($paginate->items() as $order) {
            $list[] = [
                'id' => (int)$order->id,
                'trade_no' => (string)$order->trade_no,
                'commodity_name' => $order->commodity ? strip_tags((string)$order->commodity->name) : '商品已删除',
                'cover' => $order->commodity ? (string)($order->commodity->cover ?: '/favicon.ico') : '/favicon.ico',
                'amount' => (float)$order->amount,
                'create_time' => (string)$order->create_time,
                'pay_time' => $order->pay_time,
                'order_source' => TicketModel::ORDER_SOURCE_MEMBER,
                'order_source_text' => '会员订单',
            ];
        }

        return ['list' => $list, 'total' => (int)$paginate->total()];
    }

    private function cleanTitle(string $title): string
    {
        // 最多解两层实体，覆盖 &amp;lt;script&amp;gt; 一类嵌套编码，再统一去标签。
        for ($round = 0; $round < 2; $round++) {
            $decoded = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $title) {
                break;
            }
            $title = $decoded;
        }
        $title = strip_tags($title);
        $title = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $title));
        $length = mb_strlen($title);
        if ($length < 4 || $length > 100) {
            throw new JSONException('工单标题长度需要在 4 到 100 个字符之间');
        }
        return $title;
    }

    private function purifier(): \HTMLPurifier
    {
        if ($this->purifier) {
            return $this->purifier;
        }

        $cachePath = BASE_PATH . '/runtime/ticket-purifier';
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
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

    private function cleanContent(string $content, ?User $user = null, ?Manage $manage = null): string
    {
        if (($user === null) === ($manage === null)) {
            throw new JSONException('回复身份无效');
        }
        $content = trim($content);
        if ($content === '' || strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new JSONException('回复内容为空或过长');
        }

        $safe = trim($this->purifier()->purify($content));
        if ($safe === '' || strlen($safe) > self::MAX_CONTENT_BYTES) {
            throw new JSONException('回复内容为空或过长');
        }

        $imagePattern = "/<img\\b[^>]*\\bsrc=([\"'])([^\"']+)\\1[^>]*>/i";
        preg_match_all($imagePattern, $safe, $imageMatches, PREG_SET_ORDER);
        $imageCount = count($imageMatches);
        if ($imageCount > self::MAX_CONTENT_IMAGES) {
            throw new JSONException('单次内容最多插入 ' . self::MAX_CONTENT_IMAGES . ' 张图片');
        }

        $paths = [];
        foreach ($imageMatches as $match) {
            $src = html_entity_decode((string)$match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('#^/assets/cache/(?:user/[0-9]+|general)/ticket/[A-Za-z0-9._-]+$#', $src)) {
                throw new JSONException('正文图片必须通过工单图片上传功能添加');
            }
            $paths[] = $src;
        }

        preg_match_all($imagePattern, $content, $rawImageMatches, PREG_SET_ORDER);
        $rawLocalPaths = [];
        foreach ($rawImageMatches as $match) {
            $src = html_entity_decode((string)$match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^/assets/cache/(?:user/[0-9]+|general)/ticket/[A-Za-z0-9._-]+$#', $src)) {
                $rawLocalPaths[] = $src;
            }
        }
        if (array_diff(array_unique($rawLocalPaths), array_unique($paths))) {
            throw new JSONException('正文图片安全检查失败，请重新上传');
        }

        $paths = array_values(array_unique($paths));
        if ($paths) {
            $uploadQuery = UploadModel::query()->where('type', 'ticket')->whereIn('path', $paths);
            $user ? $uploadQuery->where('user_id', $user->id) : $uploadQuery->whereNull('user_id');
            $uploads = $uploadQuery->orderBy('id')->lockForUpdate()->get(['id', 'path']);
            $invalidFile = $uploads->contains(function (UploadModel $upload) {
                $file = BASE_PATH . (string)$upload->path;
                return !is_file($file) || !@getimagesize($file);
            });
            if ($uploads->count() !== count($paths) || $invalidFile) {
                throw new JSONException('正文图片无效或不属于当前账号');
            }
        }

        $plain = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $safe)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = trim((string)$plain);
        if ($plain === '' && $imageCount === 0) {
            throw new JSONException('请填写内容或插入图片');
        }

        return $safe;
    }

    private function excerpt(string $content): string
    {
        $content = preg_replace('/<img\b[^>]*>/i', ' [图片] ', $content);
        $plain = (string)$content;
        for ($round = 0; $round < 2; $round++) {
            $decoded = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $plain) {
                break;
            }
            $plain = $decoded;
        }
        $plain = strip_tags($plain);
        $plain = trim((string)preg_replace('/\s+/u', ' ', $plain));
        return mb_substr($plain ?: '[图片]', 0, 120);
    }

    private function replyNotificationTitle(TicketModel $ticket, bool $resolved): string
    {
        $prefix = '工单「';
        $middle = '」';
        $suffix = $resolved ? '已回复并解决' : '收到客服回复';
        $titleLimit = max(0, 100 - mb_strlen($prefix . $middle . $suffix));
        return $prefix . mb_substr((string)$ticket->title, 0, $titleLimit) . $middle . $suffix;
    }

    private function replyNotificationContent(string $content): string
    {
        $reply = trim((string)preg_replace('/<img\b[^>]*>/i', '[图片，请前往工单详情查看]', $content));
        if ($reply === '') {
            $reply = '[回复内容，请前往工单详情查看]';
        }

        $notification = '<p><strong>客服回复：</strong></p>' . $reply;
        if (strlen($notification) <= 96000) {
            return $notification;
        }

        $plain = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $reply);
        for ($round = 0; $round < 2; $round++) {
            $decoded = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $plain) {
                break;
            }
            $plain = $decoded;
        }
        $plain = trim((string)preg_replace('/\s+/u', ' ', strip_tags($plain)));
        if ($plain === '') {
            $plain = '[回复内容，请前往工单详情查看]';
        }
        $plain = mb_substr($plain, 0, 8000);
        return '<p><strong>客服回复：</strong></p><p>'
            . htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
            . '…（内容较长，请前往工单详情查看）</p>';
    }

    private function resolveProof(User $user, array $map): UploadModel
    {
        $uploadId = (int)($map['proof_upload_id'] ?? $map['proof_id'] ?? $map['upload_id'] ?? 0);
        $path = trim((string)($map['proof_path'] ?? $map['proof'] ?? ''));
        $query = UploadModel::query()->where('user_id', $user->id)->where('type', 'ticket')->lockForUpdate();
        $proof = $uploadId > 0 ? $query->find($uploadId) : ($path !== '' ? $query->where('path', $path)->first() : null);

        if (!$proof || !is_file(BASE_PATH . $proof->path) || !@getimagesize(BASE_PATH . $proof->path)) {
            throw new JSONException('售后支持必须上传有效的购买凭证');
        }
        return $proof;
    }

    private function resolveOrder(User $user, array $map): array
    {
        $orderId = (int)($map['order_id'] ?? 0);
        $tradeNo = trim((string)($map['order_trade_no'] ?? $map['trade_no'] ?? ''));
        $order = null;
        $source = TicketModel::ORDER_SOURCE_NONE;

        if ($orderId > 0) {
            $order = Order::query()->where('status', 1)->where('owner', $user->id)->lockForUpdate()->find($orderId);
            if ($order && $tradeNo !== '' && !hash_equals((string)$order->trade_no, $tradeNo)) {
                $order = null;
            }
            $source = TicketModel::ORDER_SOURCE_MEMBER;
        } elseif ($tradeNo !== '' && strlen($tradeNo) <= 32) {
            $order = Order::query()->where('status', 1)->where('trade_no', $tradeNo)->lockForUpdate()->first();
            if ($order) {
                if ((int)$order->owner === (int)$user->id) {
                    $source = TicketModel::ORDER_SOURCE_MEMBER;
                } elseif ((int)$order->owner === 0) {
                    $source = TicketModel::ORDER_SOURCE_GUEST;
                } else {
                    $order = null;
                }
            }
        }

        if (!$order) {
            throw new JSONException('订单不存在、未支付或不属于当前会员');
        }
        return [$order, $source];
    }

    private function generateTicketNo(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = 'TK' . date('ymdHis') . strtoupper(bin2hex(random_bytes(4)));
            if (!TicketModel::query()->where('ticket_no', $number)->exists()) {
                return $number;
            }
        }
        throw new JSONException('工单编号生成失败，请稍后重试');
    }

    private function appendMessage(TicketModel $ticket, int $senderType, ?int $senderId, string $senderName, string $content, int $kind = TicketMessage::KIND_CONTENT): TicketMessage
    {
        $message = new TicketMessage();
        $message->ticket_id = $ticket->id;
        $message->sender_type = $senderType;
        $message->sender_id = $senderId;
        $message->sender_name = mb_substr(strip_tags($senderName), 0, 32);
        $message->kind = $kind;
        $message->content = $content;
        $message->create_ip = Client::getAddress();
        $message->create_time = Date::current();
        $message->save();

        $ticket->last_message_id = $message->id;
        $ticket->last_sender_type = $senderType;
        $ticket->last_message_excerpt = $this->excerpt($content);
        $ticket->last_message_time = $message->create_time;
        $ticket->update_time = $message->create_time;
        return $message;
    }

    public function create(User $user, array $map): array
    {
        $this->requireReady();
        if ($this->tooMany('ticket:create:user:' . $user->id, 5, 600)) {
            throw new JSONException('创建工单过于频繁，请稍后再试');
        }

        $type = (int)($map['type'] ?? -1);
        $priority = (int)($map['priority'] ?? TicketModel::PRIORITY_MEDIUM);
        if (!in_array($type, [TicketModel::TYPE_PRE_SALE, TicketModel::TYPE_AFTER_SALE], true)) {
            throw new JSONException('请选择工单类型');
        }
        if (!in_array($priority, [TicketModel::PRIORITY_LOW, TicketModel::PRIORITY_MEDIUM, TicketModel::PRIORITY_HIGH], true)) {
            throw new JSONException('请选择优先级');
        }

        $title = $this->cleanTitle((string)($map['title'] ?? ''));
        $rawContent = (string)($map['content'] ?? '');
        $ticket = DB::transaction(function () use ($user, $map, $type, $priority, $title, $rawContent) {
            if (!User::query()->lockForUpdate()->find((int)$user->id)) {
                throw new JSONException('会员不存在或登录状态已失效');
            }
            $content = $this->cleanContent($rawContent, $user);
            $commodity = null;
            $order = null;
            $orderSource = TicketModel::ORDER_SOURCE_NONE;
            $proof = null;

            if ($type === TicketModel::TYPE_PRE_SALE) {
                $commodityId = (int)($map['commodity_id'] ?? 0);
                if ($commodityId > 0) {
                    $commodity = $this->visibleCommodityQuery($user)->lockForUpdate()->find($commodityId);
                    if (!$commodity) {
                        throw new JSONException('所选商品不存在或当前不可见');
                    }
                }
            } else {
                [$order, $orderSource] = $this->resolveOrder($user, $map);
                $commodity = Commodity::query()->lockForUpdate()->find((int)$order->commodity_id);
                $proof = $this->resolveProof($user, $map);
            }

            $date = Date::current();
            $ticket = new TicketModel();
            $ticket->ticket_no = $this->generateTicketNo();
            $ticket->user_id = $user->id;
            $ticket->type = $type;
            $ticket->priority = $priority;
            $ticket->status = TicketModel::STATUS_PENDING_ADMIN;
            $ticket->title = $title;
            $ticket->commodity_id = $commodity?->id;
            $ticket->commodity_name = $commodity ? $this->commodityDisplayName($commodity) : null;
            $ticket->order_id = $order?->id;
            $ticket->order_trade_no = $order?->trade_no;
            $ticket->order_source = $orderSource;
            $ticket->proof_upload_id = $proof?->id;
            $ticket->proof_path = $proof?->path;
            $ticket->user_unread = 0;
            $ticket->manage_unread = 1;
            $ticket->create_time = $date;
            $ticket->update_time = $date;
            $ticket->last_message_time = $date;
            $ticket->save();

            $this->appendMessage($ticket, TicketMessage::SENDER_USER, (int)$user->id, (string)$user->username, $content);
            $ticket->save();
            return $ticket;
        });

        return [
            'id' => (int)$ticket->id,
            'ticket_no' => (string)$ticket->ticket_no,
            'url' => '/user/ticket/detail?id=' . (int)$ticket->id,
        ];
    }

    public function userReply(User $user, int $id, string $content): array
    {
        $this->requireReady();
        if ($this->tooMany('ticket:reply:user:' . $user->id, 30, 600)) {
            throw new JSONException('回复过于频繁，请稍后再试');
        }
        [$message, $ticket] = DB::transaction(function () use ($user, $id, $content) {
            if (!User::query()->lockForUpdate()->find((int)$user->id)) {
                throw new JSONException('会员不存在或登录状态已失效');
            }
            $ticket = TicketModel::query()->where('user_id', $user->id)->lockForUpdate()->find($id);
            if (!$ticket) {
                throw new JSONException('工单不存在');
            }
            if ((int)$ticket->status >= TicketModel::STATUS_RESOLVED) {
                throw new JSONException('工单已结束，无法继续回复');
            }
            $content = $this->cleanContent($content, $user);

            $message = $this->appendMessage($ticket, TicketMessage::SENDER_USER, (int)$user->id, (string)$user->username, $content);
            $ticket->status = TicketModel::STATUS_PENDING_ADMIN;
            $ticket->user_unread = 0;
            $ticket->manage_unread = min(4294967295, (int)$ticket->manage_unread + 1);
            $ticket->save();
            return [$message, $ticket];
        });

        return [
            'message' => $this->normalizeMessage($message),
            'status' => (int)$ticket->status,
            'last_message_time' => $ticket->last_message_time,
        ];
    }

    public function adminReply(Manage $manage, int $id, string $content, string $mode): array
    {
        $this->requireReady();
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['reply', 'resolve'], true)) {
            throw new JSONException('未知的回复方式');
        }
        [$message, $ticket] = DB::transaction(function () use ($manage, $id, $content, $mode) {
            $ticketUserId = (int)TicketModel::query()->whereKey($id)->value('user_id');
            if ($ticketUserId <= 0) {
                throw new JSONException('工单不存在');
            }
            // 与用户回复保持一致的加锁顺序，避免用户和客服同时回复时互相等待。
            $recipient = User::query()->lockForUpdate()->find($ticketUserId, ['id', 'status']);
            $ticket = TicketModel::query()->lockForUpdate()->find($id);
            if (!$ticket) {
                throw new JSONException('工单不存在');
            }
            if ((int)$ticket->status >= TicketModel::STATUS_RESOLVED) {
                throw new JSONException('工单已结束，无法继续回复');
            }
            $content = $this->cleanContent($content, null, $manage);

            $name = (string)($manage->nickname ?: $manage->email ?: '管理员');
            $kind = $mode === 'resolve' ? TicketMessage::KIND_RESOLVED : TicketMessage::KIND_CONTENT;
            $message = $this->appendMessage($ticket, TicketMessage::SENDER_MANAGE, (int)$manage->id, $name, $content, $kind);
            $ticket->manage_unread = 0;
            $ticket->user_unread = min(4294967295, (int)$ticket->user_unread + 1);
            if ($mode === 'resolve') {
                $ticket->status = TicketModel::STATUS_RESOLVED;
                $ticket->closed_by = $manage->id;
                $ticket->closed_time = Date::current();
            } else {
                $ticket->status = TicketModel::STATUS_PENDING_USER;
            }
            $ticket->save();
            if ($recipient && (int)$recipient->status === 1 && (int)$recipient->id === (int)$ticket->user_id) {
                $this->messageService->sendToUser(
                    (int)$ticket->user_id,
                    $this->replyNotificationTitle($ticket, $mode === 'resolve'),
                    $this->replyNotificationContent($content),
                    '/user/ticket/detail?id=' . (int)$ticket->id,
                    ['source' => '工单管理', 'send_email' => false]
                );
            }
            ManageLog::log($manage, ($mode === 'resolve' ? '回复并解决了' : '回复了') . "工单({$ticket->ticket_no})");
            return [$message, $ticket];
        });

        return [
            'message' => $this->normalizeMessage($message),
            'status' => (int)$ticket->status,
            'last_message_time' => $ticket->last_message_time,
        ];
    }

    public function close(Manage $manage, int $id): array
    {
        $this->requireReady();
        [$message, $ticket] = DB::transaction(function () use ($manage, $id) {
            $ticket = TicketModel::query()->lockForUpdate()->find($id);
            if (!$ticket) {
                throw new JSONException('工单不存在');
            }
            if ((int)$ticket->status >= TicketModel::STATUS_RESOLVED) {
                throw new JSONException('工单已经结束');
            }

            $name = (string)($manage->nickname ?: $manage->email ?: '管理员');
            $content = '工单已由 ' . strip_tags($name) . ' 关闭。';
            $message = $this->appendMessage($ticket, TicketMessage::SENDER_MANAGE, (int)$manage->id, $name, $content, TicketMessage::KIND_CLOSED);
            $ticket->status = TicketModel::STATUS_CLOSED;
            $ticket->closed_by = $manage->id;
            $ticket->closed_time = Date::current();
            $ticket->manage_unread = 0;
            $ticket->user_unread = min(4294967295, (int)$ticket->user_unread + 1);
            $ticket->save();
            ManageLog::log($manage, "关闭了工单({$ticket->ticket_no})");
            return [$message, $ticket];
        });

        return [
            'message' => $this->normalizeMessage($message),
            'status' => (int)$ticket->status,
            'last_message_time' => $ticket->last_message_time,
        ];
    }

    public function userBadge(User $user): array
    {
        if (!$this->ready()) {
            return ['count' => 0];
        }
        return ['count' => (int)TicketModel::query()->where('user_id', $user->id)->sum('user_unread')];
    }

    public function adminBadge(): array
    {
        if (!$this->ready()) {
            return ['count' => 0];
        }
        return ['count' => (int)TicketModel::query()->where('status', TicketModel::STATUS_PENDING_ADMIN)->count()];
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

        $info = @getimagesize((string)$file['tmp_name']);
        $mime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
        $allowed = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
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

    private function reuseUploadRecord(UploadModel $existing, string $newPath): UploadModel
    {
        if ((string)$existing->path === $newPath) {
            return $existing;
        }
        if (is_file(BASE_PATH . $existing->path)) {
            File::remove(BASE_PATH . $newPath);
            return $existing;
        }

        $oldAbsolutePath = BASE_PATH . $existing->path;
        $oldDirectory = dirname($oldAbsolutePath);
        if (!is_dir($oldDirectory)) {
            mkdir($oldDirectory, 0755, true);
        }
        if (@rename(BASE_PATH . $newPath, $oldAbsolutePath)) {
            return $existing;
        }

        $existing->path = $newPath;
        $existing->create_time = Date::current();
        $existing->save();
        return $existing;
    }

    private function registerUpload(string $path, ?int $userId): UploadModel
    {
        $actualHash = (string)md5_file(BASE_PATH . $path);
        $sameOwner = UploadModel::query()->where('hash', $actualHash)->where('type', 'ticket');
        $userId === null ? $sameOwner->whereNull('user_id') : $sameOwner->where('user_id', $userId);
        $existing = $sameOwner->first();
        if ($existing) {
            return $this->reuseUploadRecord($existing, $path);
        }

        $storedHash = $actualHash;
        if (UploadModel::query()->where('hash', $actualHash)->exists()) {
            $storedHash = md5($actualHash . ':ticket:' . ($userId ?? 'manage') . ':' . bin2hex(random_bytes(8)));
        }

        $upload = new UploadModel();
        $upload->user_id = $userId;
        $upload->hash = $storedHash;
        $upload->type = 'ticket';
        $upload->path = $path;
        $upload->create_time = Date::current();
        try {
            $upload->save();
        } catch (\Throwable $exception) {
            $sameOwner = UploadModel::query()->where('hash', $actualHash)->where('type', 'ticket');
            $userId === null ? $sameOwner->whereNull('user_id') : $sameOwner->where('user_id', $userId);
            if ($existing = $sameOwner->first()) {
                return $this->reuseUploadRecord($existing, $path);
            }
            if (UploadModel::query()->where('hash', $actualHash)->exists()) {
                $upload->hash = md5($actualHash . ':ticket:' . ($userId ?? 'manage') . ':' . bin2hex(random_bytes(8)));
                $upload->save();
            } else {
                throw $exception;
            }
        }
        return $upload;
    }

    public function upload(?User $user, ?Manage $manage, array $file): array
    {
        $this->requireReady();
        if (($user === null) === ($manage === null)) {
            throw new JSONException('上传身份无效');
        }
        $actor = $user ? 'user:' . $user->id : 'manage:' . $manage->id;
        if ($this->tooMany('ticket:upload:' . $actor, 40, 600)) {
            throw new JSONException('上传过于频繁，请稍后再试');
        }
        $extension = $this->validateImage($file);

        $staticPath = $user
            ? '/assets/cache/user/' . (int)$user->id . '/ticket/'
            : '/assets/cache/general/ticket/';
        $secureName = bin2hex(random_bytes(16)) . '.' . $extension;
        $handle = $this->upload->handle(
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
        $record = $this->registerUpload($path, $user?->id);
        if ($manage) {
            ManageLog::log($manage, "上传了工单图片(#{$record->id})");
        }
        return ['url' => (string)$record->path, 'upload_id' => (int)$record->id];
    }
}
