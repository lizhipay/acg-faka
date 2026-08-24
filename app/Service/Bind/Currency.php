<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Model\Config;
use App\Util\Currency as CurrencyUtil;
use App\Util\Ini;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Exception\JSONException;
use Kernel\Util\Decimal;

/**
 * 站点货币 Service 实现。
 *
 * 校验规则集中在三个公共静态方法里，后台「其他设置」的保存接口复用同一套规则，
 * 保证 Service API 与后台表单是单一事实源。
 */
class Currency implements \App\Service\Currency
{

    /**
     * 汇率校验：正数、整数部分至多 6 位、小数至多 6 位。
     * 不复用 configMoney（它限 2 位小数，汇率精度不够）。
     * @param string $rate
     * @return string 规范化后的汇率字符串
     * @throws JSONException
     */
    public static function assertRate(string $rate): string
    {
        $rate = trim($rate);
        if (!preg_match('/^(?:0|[1-9]\d{0,5})(?:\.\d{1,6})?$/', $rate) || (float)$rate <= 0) {
            throw new JSONException("汇率必须是大于 0 的数字，最多 6 位小数");
        }
        return $rate;
    }

    /**
     * 货币代码校验：大写字母/数字 1-8 位（ISO 代码或自定义）。
     * @param string $code
     * @return string 规范化（大写）后的代码
     * @throws JSONException
     */
    public static function assertCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{1,8}$/', $code)) {
            throw new JSONException("货币代码请用 1-8 位的字母或数字");
        }
        return $code;
    }

    /**
     * 货币符号校验：1-8 个字符，禁止 HTML 特殊字符、引号与反斜杠——
     * 符号会被裸输出到模板、CSS 变量和动态正则里，这条校验是那三处的安全前提。
     * @param string $symbol
     * @return string
     * @throws JSONException
     */
    public static function assertSymbol(string $symbol): string
    {
        $symbol = trim($symbol);
        if ($symbol === '' || mb_strlen($symbol) > 8) {
            throw new JSONException("货币符号请填 1-8 个字符");
        }
        if (preg_match('/[<>"\'&`\\\\]/', $symbol)) {
            throw new JSONException("货币符号不能包含 <>\"'&`\\ 这类特殊字符");
        }
        return $symbol;
    }

    /**
     * @inheritDoc
     */
    public function getCurrency(): array
    {
        return CurrencyUtil::vars();
    }

    /**
     * @inheritDoc
     */
    public function getRate(): string
    {
        return CurrencyUtil::rate();
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function setRate(string|float $rate): void
    {
        Config::putMany([
            'currency_rate' => self::assertRate((string)$rate),
        ]);
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function setCurrency(string $code, string $symbol, string|float $rate, ?int $decimals = null): void
    {
        $values = [
            'currency_code' => self::assertCode($code),
            'currency_symbol' => self::assertSymbol($symbol),
            'currency_rate' => self::assertRate((string)$rate),
        ];
        if ($decimals !== null) {
            if ($decimals !== 0 && $decimals !== 2) {
                throw new JSONException("显示小数位只支持 0 或 2");
            }
            $values['currency_decimals'] = (string)$decimals;
        }
        Config::putMany($values);
    }

    /**
     * @inheritDoc
     */
    public function toCny(float|string $amount): float
    {
        return CurrencyUtil::toCny($amount);
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function convertAll(string $code, string $symbol, string|float $rate, ?int $decimals = null): array
    {
        $toCode = self::assertCode($code);
        $toSymbol = self::assertSymbol($symbol);
        $toRate = self::assertRate((string)$rate);
        if ($decimals !== null && $decimals !== 0 && $decimals !== 2) {
            throw new JSONException("显示小数位只支持 0 或 2");
        }

        $fromCode = CurrencyUtil::code();
        $fromRate = CurrencyUtil::rate();
        if ($toCode === $fromCode) {
            throw new JSONException("目标币种与当前币种相同（{$fromCode}），无需换算；只调汇率不改数据请直接保存设置");
        }

        //换算因子 = 旧汇率 ÷ 新汇率（都以「1 单位 = X 人民币」计）。scale 12 足够任何正常汇率组合
        $factor = bcdiv($fromRate, $toRate, 12);
        if (bccomp($factor, '0', 12) <= 0) {
            throw new JSONException("换算因子无效，请检查汇率");
        }
        //因子为 1 的换算什么都不会改（典型场景：选了新币种预设但汇率框还留着旧值），
        //直接拦下，免得跑出一个「成功改写 0 行」的迷惑结果
        if (bccomp($factor, '1', 6) === 0) {
            throw new JSONException("目标汇率与当前汇率相同，换算不会改变任何数字；请先把汇率改成新币种的真实汇率");
        }

        //命名锁防双击/并发重放：全站换算跑两遍等于金额平方级错乱
        $lock = DB::selectOne("SELECT GET_LOCK('acg_currency_convert', 0) AS locked");
        if (!$lock || (int)$lock->locked !== 1) {
            throw new JSONException("另一个换算任务正在进行，请稍后再试");
        }

        try {
            $summary = [];

            //预检：任何一列换算后会超出 DECIMAL 上限，立刻点名报出来并中止。
            //不做这步的话，严格模式下事务在半路抛 Out of range 回滚，
            //管理员只能看到一句毫无线索的「换算失败」（实测踩过：测试账号灌了一亿余额，
            //×6.73 后超出 bill.amount 的 DECIMAL(10,2) 上限）。
            $this->assertConvertNoOverflow($factor);

            //店铺共享的货币列在事务里要参与换算，老库先自愈补齐
            \App\Util\Schema::ensureSharedCurrency();

            //数据表全部走一个事务（均为 InnoDB）；config 表是 MyISAM，放到事务外最后写
            try {
                DB::transaction(function () use ($factor, &$summary) {
                //一、金额列：SQL 级 ROUND(col × factor, 2)，半数进位，与 PHP 侧一致。
                //售价列（clamp）额外钳底：原值 > 0 的换算后最低 0.01——0.01 元的商品
                //除以大汇率四舍五入成 0.00 就变成免费商品了，历史记录类金额则如实取整。
                $columns = self::convertMoneyColumns();
                foreach ($columns as $table => $cols) {
                    $set = [];
                    foreach ($cols as $col => $mode) {
                        $set[$col] = $mode === 'clamp'
                            ? DB::raw("IF(`{$col}` > 0, GREATEST(ROUND(`{$col}` * {$factor}, 2), 0.01), 0)")
                            : DB::raw("ROUND(`{$col}` * {$factor}, 2)");
                    }
                    $summary[$table] = DB::table($table)->update($set);
                }

                //二、模式相关列：只换「固定金额」态，百分比态不动
                $summary['coupon'] = DB::table('coupon')->where('mode', '!=', 1)
                    ->update(['money' => DB::raw("ROUND(`money` * {$factor}, 2)")]);
                $summary['pay(固定手续费)'] = DB::table('pay')->where('cost_type', 0)
                    ->update(['cost' => DB::raw("ROUND(`cost` * {$factor}, 2)")]);
                $summary['commodity(固定对接加价)'] = DB::table('commodity')->where('shared_premium_type', 0)
                    ->update(['shared_premium' => DB::raw("ROUND(`shared_premium` * {$factor}, 2)")]);
                $summary['price_template(游客固定值)'] = DB::table('price_template')->where('guest_type', 0)
                    ->update(['guest_value' => DB::raw("ROUND(`guest_value` * {$factor}, 2)")]);
                $summary['price_template(会员固定值)'] = DB::table('price_template')->where('user_type', 0)
                    ->update(['user_value' => DB::raw("ROUND(`user_value` * {$factor}, 2)")]);

                //店铺共享的手动结算汇率是「1 上游货币 = ? 本站货币」，以本站货币计价，
                //站点换币种时必须同因子换算，否则跨币种货源的接入价整体错一个数量级。
                //0（自动按站点汇率）不用动——新站点汇率会让它自洽。汇率精度 6 位。
                //列由 ensureSharedCurrency 在事务开始前自愈补齐（见 convertAll 开头）。
                $summary['shared(结算汇率)'] = DB::table('shared')->where('currency_rate', '>', 0)
                    ->update(['currency_rate' => DB::raw("ROUND(`currency_rate` * {$factor}, 6)")]);

                //三、序列化配置：逐行取出、只换已知金额键、按原格式写回
                $summary['commodity(序列化配置)'] = $this->convertCommodityConfigs($factor);
                $summary['price_template(等级配置)'] = $this->convertTemplateLevelConfigs($factor);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                //预检之外仍有列溢出（结构漂移等）：至少把可读的原因带出去，别只剩一句「失败」
                $info = $e->errorInfo ?? [];
                if (($info[0] ?? '') === '22003' || in_array((int)($info[1] ?? 0), [1264, 1690], true)) {
                    $detail = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($info[2] ?? $e->getMessage())));
                    throw new JSONException('换算中有金额超出数据库字段上限，已整体回滚（数据未改动）：' . mb_substr($detail, 0, 160) . '。请检查是否存在异常大的测试金额');
                }
                throw $e;
            }

            //四、config 表的金额阈值 + 新货币，一批写入（putMany 自带锁与缓存重建）
            $values = [
                'currency_code' => $toCode,
                'currency_symbol' => $toSymbol,
                'currency_rate' => $toRate,
            ];
            if ($decimals !== null) {
                $values['currency_decimals'] = (string)$decimals;
            }
            foreach (['recharge_min', 'recharge_max', 'cash_min', 'cash_cost'] as $key) {
                $raw = trim(Config::get($key));
                if ($raw !== '' && is_numeric($raw) && (float)$raw > 0) {
                    $values[$key] = self::mulRound($raw, $factor);
                }
            }
            $welfare = trim(Config::get('recharge_welfare_config'));
            if ($welfare !== '') {
                $values['recharge_welfare_config'] = $this->convertWelfareRules($welfare, $factor);
            }
            //审计：换算历史留在 config 里，最多 20 条
            $history = json_decode(Config::get('currency_convert_log'), true);
            $history = is_array($history) ? $history : [];
            $history[] = [
                'time' => date('Y-m-d H:i:s'),
                'from' => ['code' => $fromCode, 'rate' => $fromRate],
                'to' => ['code' => $toCode, 'rate' => $toRate],
                'factor' => $factor,
                'tables' => $summary,
            ];
            $values['currency_convert_log'] = json_encode(array_slice($history, -20), JSON_UNESCAPED_UNICODE);

            Config::putMany($values);

            return $summary;
        } finally {
            DB::selectOne("SELECT RELEASE_LOCK('acg_currency_convert') AS released");
        }
    }

    /**
     * 金额 × 因子，半数进位到两位小数（与 SQL ROUND 对正数的行为一致）。
     * $clampPositive：售价语义——原值 > 0 时换算结果最低 0.01，不许四舍五入成免费。
     */
    private static function mulRound(string $amount, string $factor, bool $clampPositive = false): string
    {
        $value = (new Decimal($amount, 8))->mul($factor)->add('0.005')->getAmount(2);
        if (bccomp($value, '0', 2) < 0) {
            $value = '0.00';
        }
        if ($clampPositive && (float)$amount > 0 && bccomp($value, '0.01', 2) < 0) {
            $value = '0.01';
        }
        return $value;
    }

    /**
     * 参与整表换算的金额列（表 => [列 => plain|clamp]）。
     * 预检与事务内的 UPDATE 共用同一份清单，改这里两边同时生效。
     */
    private static function convertMoneyColumns(): array
    {
        return [
            'user' => ['balance' => 'plain', 'coin' => 'plain', 'recharge' => 'plain', 'total_coin' => 'plain'],
            'bill' => ['amount' => 'plain', 'balance' => 'plain'],
            'order' => ['amount' => 'plain', 'cost' => 'plain', 'premium' => 'plain', 'rent' => 'plain', 'rebate' => 'plain', 'pay_cost' => 'plain', 'divide_amount' => 'plain'], //gateway_amount 是 CNY 快照，永不换算
            'user_recharge' => ['amount' => 'plain', 'pay_cost' => 'plain'],
            'cash' => ['amount' => 'plain', 'cost' => 'plain'],
            'commodity' => ['price' => 'clamp', 'user_price' => 'clamp', 'factory_price' => 'plain', 'draft_premium' => 'plain'],
            'card' => ['draft_premium' => 'plain', 'cost' => 'plain'],
            'business_level' => ['price' => 'clamp'], //cost/accrual 是抽成百分比，不动
            'user_group' => ['recharge' => 'plain'],  //cost 是抽成百分比，不动
            'user_commodity' => ['premium' => 'plain'], //分站固定金额加价
        ];
    }

    /**
     * 换算预检：逐列取最大值，模拟乘以因子后与该列 DECIMAL 上限比较，
     * 任何会溢出的列都点名报出来（表.列、当前最大值、换算后值、上限），一次中止。
     *
     * 没有它，严格模式下事务在半路抛 Out of range 回滚，管理员只能看到
     * 一句没有任何线索的失败提示。溢出几乎总是测试期灌入的异常大金额。
     *
     * @throws JSONException
     */
    private function assertConvertNoOverflow(string $factor): void
    {
        $targets = [];
        foreach (self::convertMoneyColumns() as $table => $cols) {
            foreach ($cols as $col => $mode) {
                $targets[] = [$table, $col, null];
            }
        }
        //模式相关列带各自的 WHERE 条件
        $targets[] = ['coupon', 'money', 'mode != 1'];
        $targets[] = ['pay', 'cost', 'cost_type = 0'];
        $targets[] = ['commodity', 'shared_premium', 'shared_premium_type = 0'];
        $targets[] = ['price_template', 'guest_value', 'guest_type = 0'];
        $targets[] = ['price_template', 'user_value', 'user_type = 0'];

        $prefix = DB::connection()->getTablePrefix();
        $offenders = [];
        foreach ($targets as [$table, $col, $where]) {
            try {
                $sql = "SELECT MAX(`{$col}`) AS m FROM `{$prefix}{$table}`" . ($where !== null ? " WHERE {$where}" : '');
                $row = DB::selectOne($sql);
                $max = $row->m ?? null;
                if ($max === null || (float)$max <= 0) {
                    continue;
                }
                $meta = DB::selectOne(
                    'SELECT NUMERIC_PRECISION AS p, NUMERIC_SCALE AS s FROM information_schema.COLUMNS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$prefix . $table, $col]
                );
                if (!$meta || $meta->p === null || $meta->s === null) {
                    continue;
                }
                $precision = (int)$meta->p;
                $scale = (int)$meta->s;
                //DECIMAL(p,s) 的最大值 = 10^(p-s) - 10^(-s)
                $columnMax = bcsub(bcpow('10', (string)($precision - $scale)), bcpow('10', (string)(-$scale), $scale), $scale);
                $converted = self::mulRound((string)$max, $factor);
                if (bccomp($converted, $columnMax, $scale) > 0) {
                    $offenders[] = "{$table}.{$col}（当前最大 {$max} → 换算后 {$converted}，上限 {$columnMax}）";
                }
            } catch (\Throwable $e) {
                //个别表/列探测失败不拦换算主流程，真正的问题会由事务内的更新自己暴露
            }
        }

        if ($offenders !== []) {
            $list = implode('；', array_slice($offenders, 0, 6));
            $more = count($offenders) > 6 ? '，另有 ' . (count($offenders) - 6) . ' 处' : '';
            throw new JSONException(
                "以下金额换算后将超出数据库字段上限，已中止（数据未改动）：{$list}{$more}。"
                . '这类超大金额多为测试期灌入的数据，请先把它们修正到正常范围再执行换算'
            );
        }
    }

    /**
     * 商品序列化配置换算：config INI（种类价/种类成本/批发价/种类批发/SKU加价/SKU成本）
     * 与 level_price JSON（各会员等级的定制价 amount + 内嵌 config INI）。
     * @return int 实际改写的商品行数
     */
    private function convertCommodityConfigs(string $factor): int
    {
        $changed = 0;
        DB::table('commodity')->select(['id', 'config', 'level_price'])
            ->orderBy('id')->chunk(200, function ($rows) use ($factor, &$changed) {
                foreach ($rows as $row) {
                    $update = [];

                    $config = trim((string)$row->config);
                    if ($config !== '') {
                        $converted = $this->convertIniMoney($config, $factor);
                        if ($converted !== null && $converted !== $config) {
                            $update['config'] = $converted;
                        }
                    }

                    $levelPrice = trim((string)$row->level_price);
                    if ($levelPrice !== '') {
                        $list = json_decode($levelPrice, true);
                        if (is_array($list)) {
                            $dirty = false;
                            foreach ($list as $groupId => $item) {
                                if (!is_array($item)) {
                                    continue;
                                }
                                if (isset($item['amount']) && is_numeric((string)$item['amount']) && (float)$item['amount'] > 0) {
                                    //等级定制价是售价，钳底 0.01
                                    $list[$groupId]['amount'] = (float)self::mulRound((string)$item['amount'], $factor, true);
                                    $dirty = true;
                                }
                                $inner = trim((string)($item['config'] ?? ''));
                                if ($inner !== '') {
                                    $convertedInner = $this->convertIniMoney($inner, $factor);
                                    if ($convertedInner !== null && $convertedInner !== $inner) {
                                        $list[$groupId]['config'] = $convertedInner;
                                        $dirty = true;
                                    }
                                }
                            }
                            if ($dirty) {
                                $update['level_price'] = json_encode($list, JSON_UNESCAPED_UNICODE);
                            }
                        }
                    }

                    if ($update !== []) {
                        DB::table('commodity')->where('id', $row->id)->update($update);
                        $changed++;
                    }
                }
            });
        return $changed;
    }

    /**
     * INI 配置里的金额键换算。只碰这些键的叶子数字，其余键原样保留：
     * category / category_cost（种类=>单价）、wholesale（数量=>单价）、
     * category_wholesale（种类=>{数量=>单价}）、sku / sku_cost（控件=>{选项=>加价}）。
     * @return string|null 解析失败返回 null（原文不动）
     */
    private function convertIniMoney(string $ini, string $factor): ?string
    {
        try {
            $config = Ini::toArray($ini);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($config) || $config === []) {
            return $ini;
        }

        //售价键（category/wholesale/category_wholesale）钳底 0.01，成本与 SKU 加价如实取整
        $flat = ['category' => true, 'category_cost' => false, 'wholesale' => true];
        foreach ($flat as $key => $clamp) {
            if (isset($config[$key]) && is_array($config[$key])) {
                foreach ($config[$key] as $k => $v) {
                    if (is_numeric((string)$v)) {
                        $config[$key][$k] = (float)self::mulRound((string)$v, $factor, $clamp);
                    }
                }
            }
        }
        foreach (['category_wholesale' => true, 'sku' => false, 'sku_cost' => false] as $key => $clamp) {
            if (isset($config[$key]) && is_array($config[$key])) {
                foreach ($config[$key] as $k => $sub) {
                    if (!is_array($sub)) {
                        continue;
                    }
                    foreach ($sub as $sk => $sv) {
                        if (is_numeric((string)$sv)) {
                            $config[$key][$k][$sk] = (float)self::mulRound((string)$sv, $factor, $clamp);
                        }
                    }
                }
            }
        }
        return Ini::toConfig($config);
    }

    /**
     * 加价模板 level_config（{等级id:{type,value}}）：type=0 固定金额才换算。
     * @return int 改写的模板行数
     */
    private function convertTemplateLevelConfigs(string $factor): int
    {
        $changed = 0;
        foreach (DB::table('price_template')->select(['id', 'level_config'])->get() as $row) {
            $raw = trim((string)$row->level_config);
            if ($raw === '') {
                continue;
            }
            $list = json_decode($raw, true);
            if (!is_array($list)) {
                continue;
            }
            $dirty = false;
            foreach ($list as $groupId => $rule) {
                if (is_array($rule) && (int)($rule['type'] ?? 1) === 0 && is_numeric((string)($rule['value'] ?? ''))) {
                    $list[$groupId]['value'] = (float)self::mulRound((string)$rule['value'], $factor);
                    $dirty = true;
                }
            }
            if ($dirty) {
                DB::table('price_template')->where('id', $row->id)
                    ->update(['level_config' => json_encode($list, JSON_UNESCAPED_UNICODE)]);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * 充值赠送规则（每行「充值额-赠送额」）两侧都按因子换算，保持行格式。
     */
    private function convertWelfareRules(string $rules, string $factor): string
    {
        $lines = preg_split('/\R/u', $rules) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('-', $line);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $out[] = self::mulRound($parts[0], $factor) . '-' . self::mulRound($parts[1], $factor);
            } else {
                $out[] = $line;
            }
        }
        return implode("\n", $out);
    }
}
