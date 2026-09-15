<?php
declare(strict_types=1);

namespace App\Controller\User\Api;


use App\Controller\Base\API\User;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\Business;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Util\LangRecycle;
use App\Service\Query;
use App\Util\Client;
use App\Util\Date;
use App\Util\Ini;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserSession::class, Business::class], Interceptor::TYPE_API)]
class Commodity extends User
{
    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $map['equal-owner'] = $this->getUser()->id;
        $get = new Get(\App\Model\Commodity::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setOrderBy(...$this->query->getOrderBy($map, "sort", "asc"));
        $get->setWhere($map);

        $data = $this->query->get($get, function (Builder $builder) {
            return $builder
                ->where("owner", $this->getUser()->id)
                ->with(['category'])
                ->withCount([
                    'card as card_count' => function (Builder $builder) {
                        $builder->where("status", 0);
                    },
                    'card as card_success_count' => function (Builder $builder) {
                        $builder->where("status", 1);
                    },
                    //商品总盈利
                    'order as order_all_amount' => function (Builder $relation) {
                        $relation->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_all_amount"));
                    },
                    //过去7天内盈利
                    'order as order_week_amount' => function (Builder $relation) {
                        $relation->whereBetween('create_time', [Date::weekDay(1, Date::TYPE_START), Date::weekDay(7, Date::TYPE_END)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_week_amount"));
                    },
                    //昨日盈利
                    'order as order_yesterday_amount' => function (Builder $relation) {
                        $relation->whereBetween('create_time', [Date::calcDay(-1), Date::calcDay(-1, Date::TYPE_END)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_yesterday_amount"));
                    },
                    //今日盈利
                    'order as order_today_amount' => function (Builder $relation) {
                        $relation->whereBetween('create_time', [Date::calcDay(), Date::calcDay(0, Date::TYPE_END)])->where("status", 1)->select(\App\Model\Order::query()->raw("COALESCE(sum(amount),0) as order_today_amount"));
                    }
                ]);
        });

        foreach ($data['list'] as &$item) {
            $item['share_url'] = Client::getUrl() . "/item/{$item['id']}";
        }

        return $this->json(data: $data);
    }


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function save(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);
        $user = $this->getUser();

        //cover 会被前台各主题与后台商品列表直接拼进 <img src="…"> / style="url(…)" 等 HTML 属性，
        //而输入侧净化（HTMLPurifier）不编码引号，商户可存 `x" onerror=…` 突破属性 → 存储型 XSS
        //（商户→管理员可盗后台会话）。cover 语义就是图片地址，这里按 URL 收窄：剔除会突破属性的
        //字符（引号/尖括号/反引号/反斜杠/空白/控制符），合法图片地址不含这些。
        if (isset($map['cover']) && is_string($map['cover'])) {
            //既会进 src="…"（需拦引号），也会进 style="…url(cover)…"（需拦括号/分号）。合法图片地址
            //不含这些原始字符（真要用会做 %xx 编码）。一并剔除空白/控制符/尖括号/反引号/反斜杠。
            $map['cover'] = preg_replace('/[\x00-\x20"\'<>`();\\\\\x7F]+/', '', $map['cover']);
        }

        $id = isset($map['id']) ? (int)$map['id'] : 0;
        $isCreate = $id <= 0;
        $commodity = null;

        if ($id > 0) {
            $commodity = \App\Model\Commodity::query()
                ->where("owner", $user->id)
                ->find($id);
            if (!$commodity) {
                throw new JSONException("该商品不存在");
            }
        }

        if ($isCreate && !isset($map['category_id'])) {
            throw new JSONException("请选择商品分类");
        }

        if (($isCreate && !isset($map['name'])) || (isset($map['name']) && trim((string)$map['name']) === '')) {
            throw new JSONException("商品名称不能为空哦(｡￫‿￩｡)");
        }

        //name 是 varchar(255)：超长直接入库会触发 MySQL 1406→500，提前给干净的业务错误。
        if (isset($map['name']) && mb_strlen(trim((string)$map['name'])) > 255) {
            throw new JSONException("商品名称过长，请控制在255字以内");
        }

        //price/user_price/draft_premium 都是 decimal(10,2) UNSIGNED，取值范围 [0, 99999999.99]。
        //缺范围校验时，负值(UNSIGNED)或超大值(超精度)都会让 INSERT/UPDATE 报错→未捕获→500。
        foreach (['price' => '商品单价', 'user_price' => '会员单价', 'draft_premium' => '预选加价'] as $field => $label) {
            if (isset($map[$field]) && $map[$field] !== '' && ((float)$map[$field] < 0 || (float)$map[$field] > 99999999.99)) {
                throw new JSONException("{$label}需在 0 ~ 99999999.99 之间哦(｡￫‿￩｡)");
            }
        }

        //stock 是有符号 int(负数能入库但语义错=自损);minimum/maximum 是 UNSIGNED(负数→500)。
        if (isset($map['stock']) && $map['stock'] !== '' && (int)$map['stock'] < 0) {
            throw new JSONException("库存不能为负数");
        }
        $minimum = isset($map['minimum']) && $map['minimum'] !== '' ? (int)$map['minimum'] : null;
        $maximum = isset($map['maximum']) && $map['maximum'] !== '' ? (int)$map['maximum'] : null;
        if (($minimum !== null && $minimum < 0) || ($maximum !== null && $maximum < 0)) {
            throw new JSONException("购买数量限制不能为负数");
        }
        if ($minimum && $maximum && $minimum > $maximum) {
            throw new JSONException("最低购买数量不能大于最大购买数量");
        }

        // widget 来自表单的 widget 组件，提交前恒做 encodeURIComponent（防输入清洗层伤 JSON）。
        // 旧版靠清洗层的隐式二次 urldecode 还原，清洗层修正（#833）后在消费点显式解码，
        // 与后台商品保存、插件/主题配置保存的惯例一致。
        if (isset($map['widget']) && is_string($map['widget'])) {
            $map['widget'] = urldecode($map['widget']);
        }

        //create new
        if ($isCreate) {
            unset($map['id']);
            $map['code'] = strtoupper(Str::generateRandStr(16));
        }

        $touchesSeckill = array_key_exists('seckill_status', $map)
            || array_key_exists('seckill_start_time', $map)
            || array_key_exists('seckill_end_time', $map);
        $seckillStatus = isset($map['seckill_status'])
            ? (int)$map['seckill_status']
            : (int)($commodity?->seckill_status ?? 0);
        if ($touchesSeckill && $seckillStatus === 1) {
            $seckillStart = (string)($map['seckill_start_time'] ?? $commodity?->seckill_start_time ?? '');
            $seckillEnd = (string)($map['seckill_end_time'] ?? $commodity?->seckill_end_time ?? '');
            if ($seckillStart === '' || $seckillEnd === '') {
                throw new JSONException("您开启了秒杀功能，所以请指定秒杀的开始时间和结束时间哦(｡￫‿￩｡)");
            }
            if (strtotime($seckillEnd) < strtotime($seckillStart)) {
                throw new JSONException("秒杀结束时间不能低于秒杀开始时间哦，请认真指定秒杀结束时间(｡￫‿￩｡)");
            }
        }

        $touchesDraft = array_key_exists('draft_status', $map) || array_key_exists('draft_premium', $map);
        $draftStatus = isset($map['draft_status'])
            ? (int)$map['draft_status']
            : (int)($commodity?->draft_status ?? 0);
        if ($touchesDraft && $draftStatus === 1) {
            $draftPremium = $map['draft_premium'] ?? $commodity?->draft_premium ?? '';
            if ($draftPremium === '') {
                throw new JSONException("您开启了预选卡密功能，请填写预选时的溢价(｡￫‿￩｡)");
            }
        }

        if (isset($map['sort'])) {
            if ($map['sort'] < 1000) {
                throw new JSONException("排序最低设置1000");
            }

            if ($map['sort'] > 60000) {
                throw new JSONException("排序最高设置60000");
            }
        }

        //解析配置文件
        if (array_key_exists('config', $map) && $map['config'] !== '') {
            //顶层 price/user_price 上面已校验非负，但真正决定成交价的是 config 里的 category/wholesale 单价。
            //只做 Ini::toArray 语法解析、不校验取值，商户就能写负价 → valuation 算出负数金额 → trade() 命中
            //amount<=0 免支付直发 → 买家 0 元拿卡；若是平台货源商品(shared_id>0)平台还要向上游代付=平台亏损。
            //故与顶层同口径：category/wholesale/category_wholesale/sku 各价格档一律不得为负。
            $parsedConfig = Ini::toArray((string)$map['config']);
            foreach (['category', 'wholesale', 'category_wholesale', 'sku'] as $priceSection) {
                if (!empty($parsedConfig[$priceSection]) && is_array($parsedConfig[$priceSection])) {
                    array_walk_recursive($parsedConfig[$priceSection], static function ($value): void {
                        //空值下游按 0(免费)处理，放行。其余必须是「不小于 0 的数字」：
                        //只拦负数会漏掉 "abc-100"/"−100"(U+2212)/"- 100"/"--100"/"(+100)" 等畸形串，
                        //它们能存进去、却在 valuation 里 (float)/Decimal 解析时抛异常→估价 500。
                        if ($value === '' || $value === null) {
                            return;
                        }
                        if (!is_numeric($value) || (float)$value < 0) {
                            throw new JSONException("商品价格配置必须是不小于0的数字哦(｡￫‿￩｡)");
                        }
                    });
                }
            }
        }

        //校验会员等级独立配置，脏数据入库会导致登录用户的商品列表整体报错
        if (array_key_exists('level_price', $map) && $map['level_price'] !== '') {
            \App\Model\Commodity::validateLevelPrice((string)$map['level_price']);
        }

        // 商户可保存的字段白名单：只放前台商品编辑弹窗真正提交的列。
        // 关键在于**排除**所有平台专属列——shared_*（平台货源对接标识与凭据引用）、
        // factory_price（成本价）、api_status/recommend/hide/inventory_sync、pay_intercept、
        // dock_*、asyn_request_*。这些只应由后台（站长）设置。
        // 缺了这层白名单，商户就能把公开详情里读到的 shared_id/shared_code 写到自己 0 元商品上，
        // 下单时系统仍用平台货源账户向上游代付进货，造成平台经济损失。见 issue #912。
        // 与后台 Admin\Api\Commodity::save() 的 $allowed 同一套做法，只是这里刻意收窄。
        $allowed = [
            'category_id', 'cover', 'name', 'description', 'price', 'user_price', 'sort', 'status',
            'delivery_way', 'delivery_auto_mode', 'delivery_message', 'stock', 'contact_type',
            'password_status', 'coupon', 'seckill_status', 'seckill_start_time', 'seckill_end_time',
            'draft_status', 'draft_premium', 'inventory_hidden', 'leave_message', 'send_email',
            'only_user', 'purchase_count', 'widget', 'level_price', 'level_disable', 'minimum',
            'maximum', 'config',
        ];

        $save = new Save(\App\Model\Commodity::class);
        $save->setMap($map, $allowed);
        $save->addForceMap("owner", $user->id);
        // code 是系统生成的商品编码，不在白名单里，新建时强制写入（同后台做法）。
        // 新建路径靠它回查自增 id，见下方。
        if ($isCreate) {
            $save->addForceMap("code", (string)$map['code']);
        }
        // 表格中的上下架开关只提交 id/status。只有请求明确携带 config 时才覆盖，
        // 避免一次快捷操作把商品 SKU 等高级配置清空。
        if (array_key_exists('config', $map)) {
            $save->addForceMap("config", (string)$map['config']);
        }
        $save->enableCreateTime();
        $saved = DB::transaction(function () use ($save, $map, $id, $user) {
            // Keep the lock order identical to category deletion: target
            // Category first, then the existing Commodity row when editing.
            if (array_key_exists('category_id', $map)) {
                $category = \App\Model\Category::query()
                    ->where('owner', (int)$user->id)
                    ->where('id', (int)$map['category_id'])
                    ->lockForUpdate()
                    ->first();
                if (!$category) {
                    throw new JSONException('分类不存在');
                }
            }

            if ($id > 0) {
                $lockedCommodity = \App\Model\Commodity::query()
                    ->where('owner', (int)$user->id)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();
                if (!$lockedCommodity) {
                    throw new JSONException('该商品不存在');
                }
            }

            return $this->query->save($save);
        });
        if (!$saved) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }
        //新建路径 Query::save() 只返回 bool，靠上面生成的 code 回查自增 id
        $changedId = $isCreate
            ? (int)\App\Model\Commodity::query()->where('code', (string)$map['code'])->value('id')
            : $id;
        if ($changedId > 0) {
            $ebIds = [$changedId];
            $ebAction = $isCreate ? 'create' : 'update';
            hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $commodity);

            //换下来的旧文案连同它的翻译一起回收，别让废词条堆着（GitHub #888）
            if ($commodity !== null) {
                $before = LangRecycle::commodityTexts($commodity);
                $after = LangRecycle::commodityTexts(\App\Model\Commodity::query()->find($changedId));
                LangRecycle::release(array_diff($before, $after));
            }
        }
        return $this->json(200, '（＾∀＾）保存成功');
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {

        $id = (int)$_POST['id'];

        if ($id == 0) {
            throw new JSONException("请选择删除的商品");
        }

        $commodity = \App\Model\Commodity::query()->where("owner", $this->getUser()->id)->find($id);

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        //删完就查不到了，先把文案抓在手上
        $doomedTexts = LangRecycle::commodityTexts($commodity);

        $commodity->delete();
        $ebIds = [$id];
        $ebAction = 'delete';
        $ebBefore = null;
        hook(\App\Consts\Hook::COMMODITY_CHANGE_AFTER, $ebIds, $ebAction, $ebBefore);
        LangRecycle::release($doomedTexts);

        return $this->json(200, '（＾∀＾）移除成功');
    }
}
