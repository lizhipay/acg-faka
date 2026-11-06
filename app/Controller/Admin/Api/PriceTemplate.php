<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Interceptor\Owner;
use App\Model\Commodity;
use App\Model\ManageLog;
use App\Model\PriceTemplate as TemplateModel;
use App\Model\UserGroup;
use App\Service\Query;
use App\Util\Date;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

/**
 * 加价模板：一键给一批商品套用统一的定价规则（issue #798）。
 * 对接商品尤其需要——上游价格变动后，逐个商品手填游客价/会员价/各等级价工作量极大。
 */
//加价模板 apply 会批量改写全站商品价格(可清零)，save 可存负值模板，收敛到站长(type==0)本人（F-12/F-25）
#[Interceptor([ManageSession::class, Owner::class], Interceptor::TYPE_API)]
class PriceTemplate extends Manage
{
    /** 单次最多处理的商品数，防止一次请求跑太久 */
    private const MAX_APPLY = 2000;

    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $get = new Get(TemplateModel::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setOrderBy('id', 'desc');
        $data = $this->query->get($get);

        $counts = self::boundCounts(array_map('intval', array_column($data['list'] ?? [], 'id')));
        foreach ($data['list'] ?? [] as $index => $row) {
            $data['list'][$index]['bound_count'] = $counts[(int)$row['id']] ?? 0;
        }

        return $this->json(data: $data);
    }

    /**
     * 各模板绑定的主站商品数（加价模式选了这个模板，同步时也按它算价）
     * @param int[] $templateIds
     * @return array<int, int>
     */
    private static function boundCounts(array $templateIds): array
    {
        if ($templateIds === []) {
            return [];
        }
        return Commodity::query()
            ->where('owner', 0)
            ->where('shared_premium_type', TemplateModel::SHARED_PREMIUM_TYPE)
            ->whereIn('shared_premium_template', $templateIds)
            ->groupBy('shared_premium_template')
            ->selectRaw('shared_premium_template as template_id, count(*) as total')
            ->pluck('total', 'template_id')
            ->map(static fn($total): int => (int)$total)
            ->all();
    }

    /**
     * 保存模板（新增/修改）
     * @param Request $request
     * @return array
     * @throws JSONException
     */
    public function save(Request $request): array
    {
        $raw = (array)$request->post(flags: Filter::NORMAL);
        $id = (int)($raw['id'] ?? 0);

        $name = trim((string)($raw['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 64) {
            throw new JSONException('请填写模板名称（不超过 64 个字符）');
        }

        $template = $id > 0 ? TemplateModel::query()->find($id) : new TemplateModel();
        if (!$template) {
            throw new JSONException('模板不存在');
        }

        $template->name = $name;
        $template->base = $this->enumValue($raw['base'] ?? 0, [TemplateModel::BASE_FACTORY, TemplateModel::BASE_PRICE], '加价基准');
        $template->guest_type = $this->enumValue($raw['guest_type'] ?? 1, [TemplateModel::TYPE_FIXED, TemplateModel::TYPE_PERCENT], '游客价加价方式');
        $template->guest_value = $this->numberValue($raw['guest_value'] ?? 0, '游客价加价值');
        $template->user_type = $this->enumValue($raw['user_type'] ?? 1, [TemplateModel::TYPE_FIXED, TemplateModel::TYPE_PERCENT], '会员价加价方式');
        $template->user_value = $this->numberValue($raw['user_value'] ?? 0, '会员价加价值');
        $template->rounding = $this->enumValue(
            $raw['rounding'] ?? 0,
            [TemplateModel::ROUNDING_NONE, TemplateModel::ROUNDING_ROUND, TemplateModel::ROUNDING_CEIL],
            '价格取整'
        );
        $template->level_config = $this->normalizeLevelConfig($raw['level_config'] ?? '');

        if ($id === 0) {
            $template->create_time = Date::current();
        }

        if (!$template->save()) {
            throw new JSONException('保存失败');
        }

        ManageLog::log($this->getManage(), ($id > 0 ? '修改' : '新增') . "加价模板({$template->name})");
        return $this->json(200, '（＾∀＾）保存成功', ['id' => (int)$template->id]);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function del(): array
    {
        $list = $_POST['list'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array)$list), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            throw new JSONException('你还没有选择模板');
        }
        //接入货源时选了模板的商品，每次远端同步都要按模板重算价格；模板没了就只能跳过价格同步，
        //售价会一直停在删除前的数字。与其事后莫名其妙，不如在这里直接拦住并说清楚。
        $referenced = Commodity::query()->whereIn('shared_premium_template', $ids)->count();
        if ($referenced > 0) {
            throw new JSONException("有 {$referenced} 个接入商品正在用这些模板计算价格，请先把它们的加价模式改掉再删除模板");
        }
        $count = TemplateModel::query()->whereIn('id', $ids)->delete();
        ManageLog::log($this->getManage(), "删除加价模板，共计：{$count}");
        return $this->json(200, '（＾∀＾）删除成功', ['count' => $count]);
    }

    /**
     * 应用前预览：命中多少商品、前几条的价格变化。只读，不写库。
     * @return array
     * @throws JSONException
     */
    public function applyImpact(): array
    {
        [$template, $query] = $this->resolveApplyTarget();
        $total = (clone $query)->count();
        $skipped = 0;
        $preview = [];

        //逐条判断能否套用：种类商品的基准价在 config 里，单看 factory_price/price 会误判
        foreach ((clone $query)->orderBy('id')->get() as $commodity) {
            $computed = $this->computePrice($template, $commodity);
            if (!$computed['applicable']) {
                $skipped++;
                continue;
            }
            if (count($preview) < 5) {
                $preview[] = [
                    'name' => (string)$commodity->name,
                    'base' => $computed['base'],
                    'old_price' => sprintf('%.2f', (float)$commodity->price),
                    'new_price' => $computed['price'],
                    'old_user_price' => sprintf('%.2f', (float)$commodity->user_price),
                    'new_user_price' => $computed['user_price'],
                    'level_count' => count($computed['levels']),
                    'category_count' => $computed['category_count'],
                    //基准来源 + 前几个种类价的前后值：让预览把「种类商品单价为何不动 / 种类价变了多少」说清楚
                    'base_source' => $computed['base_source'],
                    'category_changes' => $computed['category_changes'],
                    'category_changed' => $computed['category_changed'],
                ];
            }
        }

        return $this->json(data: [
            'template' => $template->name,
            'total' => $total,
            'affected' => $total - $skipped,
            'skipped' => $skipped,
            'base_label' => $template->base === TemplateModel::BASE_FACTORY ? '成本价' : '当前售价',
            'limit' => self::MAX_APPLY,
            'exceeded' => $total > self::MAX_APPLY,
            'preview' => $preview,
        ]);
    }

    /**
     * 执行应用：批量写回商品价格。
     * @return array
     * @throws JSONException
     */
    public function apply(): array
    {
        [$template, $query] = $this->resolveApplyTarget();
        $total = (clone $query)->count();
        if ($total > self::MAX_APPLY) {
            throw new JSONException('单次最多处理 ' . self::MAX_APPLY . ' 个商品，请缩小范围后分批应用');
        }

        $updated = 0;
        DB::transaction(function () use ($query, $template, &$updated): void {
            //逐条计算：每个商品的基准价不同，无法用一条 SQL 批量算
            foreach ((clone $query)->lockForUpdate()->get() as $commodity) {
                $computed = $this->computePrice($template, $commodity);
                //没有可用基准价的商品一律跳过：否则价格会被算成 0，等于批量制造 0 元购
                if (!$computed['applicable']) {
                    continue;
                }
                $commodity->price = $computed['price'];
                $commodity->user_price = $computed['user_price'];
                $commodity->config = $computed['config'];
                $commodity->level_price = $this->mergeLevelPrice(
                    (string)$commodity->level_price,
                    $computed['levels'],
                    $template->rounding,
                    $template->base === TemplateModel::BASE_FACTORY
                );
                if ($commodity->save()) {
                    $updated++;
                }
            }
        });

        if ($updated === 0) {
            $label = $template->base === TemplateModel::BASE_FACTORY ? '成本价' : '当前售价';
            throw new JSONException("当前范围内没有可应用的商品：这些商品既没有{$label}，配置参数里也没有种类价，请先补齐后再套用模板");
        }

        ManageLog::log($this->getManage(), "应用加价模板({$template->name})，影响商品：{$updated}");
        return $this->json(200, "（＾∀＾）已应用到 {$updated} 个商品", ['count' => $updated]);
    }

    /**
     * 解析模板与目标商品查询
     * @return array{0: TemplateModel, 1: Builder}
     * @throws JSONException
     */
    private function resolveApplyTarget(): array
    {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $template = $templateId > 0 ? TemplateModel::query()->find($templateId) : null;
        if (!$template) {
            throw new JSONException('请选择加价模板');
        }

        $scope = (string)($_POST['scope'] ?? '');
        $query = Commodity::query()->where('owner', 0);

        switch ($scope) {
            case 'bound':
                $query->where('shared_premium_type', TemplateModel::SHARED_PREMIUM_TYPE)->where('shared_premium_template', $templateId);
                break;
            case 'category':
                $categoryId = (int)($_POST['category_id'] ?? 0);
                if ($categoryId <= 0) {
                    throw new JSONException('请选择商品分类');
                }
                $query->where('category_id', $categoryId);
                break;
            case 'shared':
                //仅对接商品：上游调价后最需要批量重定价的就是这批
                $query->whereNotNull('shared_id')->where('shared_id', '>', 0);
                break;
            case 'selected':
                $ids = array_values(array_filter(array_map('intval', (array)($_POST['list'] ?? [])), static fn(int $id): bool => $id > 0));
                if ($ids === []) {
                    throw new JSONException('请选择要应用的商品');
                }
                $query->whereIn('id', $ids);
                break;
            case 'all':
                break;
            default:
                throw new JSONException('应用范围不正确');
        }

        return [$template, $query];
    }

    /**
     * 配置参数里可参与加价的种类价数量。
     * 种类商品的价格全在 [category] 里，商品级 price/factory_price 常为 0，
     * 必须据此判断商品能否套模板，否则整批种类商品会被误判为"无基准"而跳过。
     *
     * @param string $config
     * @param bool $useFactoryBase
     * @return int
     */
    private function pricedCategoryCount(string $config, bool $useFactoryBase): int
    {
        if (trim($config) === '') {
            return 0;
        }

        try {
            $parsed = \App\Util\Ini::toArray($config);
        } catch (\Throwable $e) {
            return 0;
        }

        $category = is_array($parsed['category'] ?? null) ? $parsed['category'] : [];
        $factory = is_array($parsed['category_factory'] ?? null) ? $parsed['category_factory'] : [];
        $count = 0;

        foreach ($category as $key => $amount) {
            $source = ($useFactoryBase && isset($factory[$key]) && is_numeric($factory[$key])) ? $factory[$key] : $amount;
            if (is_numeric($source) && (float)$source > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 按模板算出单个商品的新价格
     * @param TemplateModel $template
     * @param Commodity $commodity
     * @return array{base:string, price:string, user_price:string, levels:array<int,string>}
     */
    private function computePrice(TemplateModel $template, Commodity $commodity): array
    {
        $useFactoryBase = $template->base === TemplateModel::BASE_FACTORY;
        $basePrice = $useFactoryBase ? (float)$commodity->factory_price : (float)$commodity->price;

        $rawConfig = (string)$commodity->getRawOriginal('config');

        //种类商品的价格在配置参数里（商品级 factory_price/price 常为 0），
        //这类商品同样可以套模板，只是加价发生在 [category] 上
        $categoryCount = $this->pricedCategoryCount($rawConfig, $useFactoryBase);
        $applicable = $basePrice > 0 || $categoryCount > 0;

        //基准来源。category = 商品级没有基准、按各种类自己的成本/售价加价：对接来的种类商品，上游协议
        //对种类商品固定发 factory_price=0，每个种类的拿货价在 config[category_factory] 里——成本价一栏为 0
        //是正常的。下单必须选种类、按种类价收费，单价不参与计价，所以这里**不**拿售价去冒充成本价。
        $baseSource = $basePrice > 0 ? ($useFactoryBase ? 'factory' : 'price') : ($categoryCount > 0 ? 'category' : 'none');

        //种类价变化样例：预览只画商品单价，纯种类商品看起来像"没变"，把前几个种类价的前后值一并给前端
        $newConfig = TemplateModel::applyToConfig($rawConfig, $template->guest_type, (float)$template->guest_value, $template->rounding, $useFactoryBase);
        //已记过 category_factory 的种类价再套同一模板结果不变（幂等），单独数出真正变了几个，
        //前端据此标「种类价不变」，免得一排 16.00→16.00 看着又像模板没生效
        $categoryChanges = [];
        $categoryChanged = 0;
        try {
            $before = trim($rawConfig) === '' ? [] : \App\Util\Ini::toArray($rawConfig);
            $after = trim($newConfig) === '' ? [] : \App\Util\Ini::toArray($newConfig);
            foreach ((array)($before['category'] ?? []) as $key => $old) {
                $new = $after['category'][$key] ?? null;
                if (!is_numeric($old) || !is_numeric($new)) {
                    continue;
                }
                $oldAmount = sprintf('%.2f', (float)$old);
                $newAmount = sprintf('%.2f', (float)$new);
                if ($oldAmount !== $newAmount) {
                    $categoryChanged++;
                }
                if (count($categoryChanges) < 3) {
                    $categoryChanges[] = ['name' => (string)$key, 'old' => $oldAmount, 'new' => $newAmount];
                }
            }
        } catch (\Throwable $e) {
            $categoryChanges = [];
            $categoryChanged = 0;
        }

        $levels = [];
        foreach ($template->levelRules() as $groupId => $rule) {
            //纯种类商品没有商品级基准价，等级价保持不动，只走等级自己的配置参数
            if ($basePrice <= 0) {
                continue;
            }
            $levels[$groupId] = [
                'amount' => TemplateModel::priced($basePrice, $rule['type'], $rule['value'], $template->rounding),
                //每个等级可以有自己的独立配置参数（种类价/批发价/SKU），按该等级的规则一起走
                'rule' => $rule,
            ];
        }

        return [
            'applicable' => $applicable,
            'category_count' => $categoryCount,
            'category_changes' => $categoryChanges,
            'category_changed' => $categoryChanged,
            'base' => sprintf('%.2f', $basePrice),
            //factory=按成本价 / price=按当前售价 / category=按各种类的成本或售价（单价不动）/ none=无基准（会被跳过）
            'base_source' => $baseSource,
            //基准价为 0（种类商品）时保持商品单价原样，只让种类价参与加价
            'price' => $basePrice > 0
                ? TemplateModel::priced($basePrice, $template->guest_type, (float)$template->guest_value, $template->rounding)
                : sprintf('%.2f', (float)$commodity->price),
            'user_price' => $basePrice > 0
                ? TemplateModel::priced($basePrice, $template->user_type, (float)$template->user_value, $template->rounding)
                : sprintf('%.2f', (float)$commodity->user_price),
            'levels' => $levels,
            //商品的「配置参数」：种类单价、批发价、SKU 加价一并按游客价规则处理
            'config' => $newConfig,
        ];
    }

    /**
     * 把算出的等级价合并进商品原有的 level_price。
     * 实现在模型里——店铺共享「接入货源」套模板时走的是同一套逻辑，不能有两份。
     *
     * @param string $original
     * @param array<int, array{amount:string, rule:array{type:int,value:float}}> $levels
     * @param int $rounding
     * @param bool $useFactoryBase
     * @return string
     */
    private function mergeLevelPrice(string $original, array $levels, int $rounding, bool $useFactoryBase): string
    {
        return TemplateModel::mergeLevelPrice($original, $levels, $rounding, $useFactoryBase);
    }

    /**
     * 会员等级加价规则：只接受存在的等级，值必须是数字
     * @param mixed $raw
     * @return string
     * @throws JSONException
     */
    private function normalizeLevelConfig(mixed $raw): string
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            $raw = $raw === '' ? [] : json_decode($raw, true);
        }
        if (!is_array($raw)) {
            throw new JSONException('会员等级加价规则格式不正确');
        }

        $groupIds = UserGroup::query()->pluck('id')->map(static fn($id): int => (int)$id)->all();
        $config = [];

        foreach ($raw as $groupId => $rule) {
            $groupId = (int)$groupId;
            if (!in_array($groupId, $groupIds, true) || !is_array($rule)) {
                continue;
            }
            if (!isset($rule['value']) || $rule['value'] === '' || !is_numeric($rule['value'])) {
                continue; //留空表示该等级不参与模板，沿用商品原有配置
            }
            $config[$groupId] = [
                'type' => $this->enumValue($rule['type'] ?? 1, [TemplateModel::TYPE_FIXED, TemplateModel::TYPE_PERCENT], '会员等级加价方式'),
                'value' => round((float)$rule['value'], 2),
            ];
        }

        return (string)json_encode($config, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws JSONException
     */
    private function enumValue(mixed $value, array $allowed, string $label): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || !in_array($int, $allowed, true)) {
            throw new JSONException("{$label}不正确");
        }
        return $int;
    }

    /**
     * @throws JSONException
     */
    private function numberValue(mixed $value, string $label): float
    {
        if ($value === '' || $value === null) {
            return 0.0;
        }
        if (!is_numeric($value)) {
            throw new JSONException("{$label}必须是数字");
        }
        $number = round((float)$value, 2);
        if ($number < -999999 || $number > 999999) {
            throw new JSONException("{$label}超出允许范围");
        }
        return $number;
    }
}
