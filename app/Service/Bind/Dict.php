<?php
declare(strict_types=1);

namespace App\Service\Bind;


use Illuminate\Database\Capsule\Manager as DB;

/**
 * 远程表字典查询（后台下拉/树选择的数据源）。
 *
 * dict 文法：`表[->条件],ID列,名称列[,父级列]`，例如
 *   `category->owner=0,id,name,pid`  或  `user->business_level>0,id,username`。
 *
 * 该 dict 字符串来自前端（后台 /admin/api/dict 直接转发客户端入参），完全不可信。两道防线：
 *  1. **表与列白名单**（{@see SCHEMA}）——只允许读作字典用途的少数几张表及其中的非敏感列，
 *     从根上杜绝 `dict=manage,password,salt` 这类「合法标识符、却在读凭据表」的越权读取；
 *  2. **条件重编译**——`->` 后的条件与外部 $where 按「列 运算符 整数 / 列 IS [NOT] NULL」重新生成，
 *     列名须在白名单内且反引号包裹，值只接受整数，关键字/运算符取自固定集合；关键字搜索走参数绑定。
 * 引号、逗号、注释、子查询、未授权的表或列一律无法进入 SQL。
 *
 * 新增字典表/列时，请在 {@see SCHEMA} 显式登记——这是一道安全边界，宁可少放。
 */
class Dict implements \App\Service\Dict
{
    /**
     * 允许作字典查询的「表 => 可见列」白名单。仅登记确有下拉/树选择需求、且不含凭据或密钥的列。
     */
    private const SCHEMA = [
        'category' => ['id', 'name', 'pid', 'owner'],
        'commodity' => ['id', 'name', 'owner', 'delivery_way', 'shared_id'],
        'user' => ['id', 'username', 'business_level'],
        'user_group' => ['id', 'name'],
        'business_level' => ['id', 'name'],
        'pay' => ['id', 'name'],
        'price_template' => ['id', 'name'],
        'shared' => ['id', 'name'],
    ];

    /** 条件里放行的比较运算符 */
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>='];

    /** 条件里放行的裸词关键字；其余词一律视为列名并按白名单校验 */
    private const KEYWORDS = ['and', 'or', 'is', 'not', 'null'];

    /**
     * @param string $dictName
     * @param string $keywords
     * @param string $where
     * @return array
     */
    public function get(string $dictName, string $keywords = '', string $where = ''): array
    {
        $dict = explode(",", $dictName);
        if (count($dict) < 3) {
            //本地枚举字典（_xxx 之类）由前端解析，这里只处理远程表字典
            return [];
        }

        try {
            [$table, $tableCondition] = array_pad(explode('->', trim($dict[0]), 2), 2, '');
            $table = trim($table);
            if (!isset(self::SCHEMA[$table])) {
                throw new \InvalidArgumentException("不在白名单内的字典表: {$table}");
            }
            $allowedColumns = self::SCHEMA[$table];

            $nameColumn = $this->column($dict[2], $allowedColumns);
            $columns = [$this->column($dict[1], $allowedColumns) . ' as id', $nameColumn . ' as name'];
            if (array_key_exists(3, $dict)) {
                $columns[] = $this->column($dict[3], $allowedColumns) . ' as pid';
            }

            //表名交给查询构造器补库前缀并反引号包裹，不手工拼字符串
            $query = DB::table($table);

            foreach ([$tableCondition, $where] as $fragment) {
                $condition = $this->compileConditions($fragment, $allowedColumns);
                if ($condition !== '') {
                    $query->whereRaw($condition);
                }
            }

            if ($keywords !== '') {
                $query->where($nameColumn, 'like', '%' . $keywords . '%');
            }

            return $query->orderBy('id', 'desc')->get($columns)
                ->map(static fn($row): array => (array)$row)->all();

        } catch (\InvalidArgumentException $e) {
            //dict 触碰了白名单之外的表/列或含非法字符——绝大多数是被构造用于越权读取。
            //只记日志，对外与「空字典」无异，不回显任何细节
            \Kernel\Util\Log::inst()->error("字典参数非法[{$dictName}]: " . $e->getMessage());
            return [];
        } catch (\Throwable $e) {
            //缺列（如老站升级后 category 少 pid）等 SQL 错误此前被静默吞成空列表，
            //前端只会表现为「下拉拉不出数据」，极难排查——落一条错误日志（issue #794）
            \Kernel\Util\Log::inst()->error("字典查询失败[{$dictName}]: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 校验一个列名在白名单内并返回，否则拒绝。
     * @throws \InvalidArgumentException
     */
    private function column(string $value, array $allowedColumns): string
    {
        $value = trim($value);
        if (!in_array($value, $allowedColumns, true)) {
            throw new \InvalidArgumentException("不在白名单内的字典列: {$value}");
        }
        return $value;
    }

    /**
     * 把条件片段编译成安全的 SQL 布尔表达式。
     *
     * 逐 token 校验后按白名单重新回显：列名须在 $allowedColumns 内并反引号包裹、整数原样、
     * 运算符与 and/or/is/not/null 取自固定集合、括号原样。任何其它字符（引号、逗号、分号、注释符……）
     * 或不在白名单内的列一律判非法。因「词」一律当列名处理，`union`/`select` 之类即便混入也只是列名而非关键字。
     *
     * @throws \InvalidArgumentException
     */
    private function compileConditions(string $fragment, array $allowedColumns): string
    {
        $fragment = trim($fragment);
        if ($fragment === '') {
            return '';
        }

        preg_match_all('/\s+|\(|\)|<=|>=|<>|!=|[=<>]|[0-9]+|[A-Za-z_][A-Za-z0-9_]*|./', $fragment, $matches);

        $sql = [];
        foreach ($matches[0] as $token) {
            if (trim($token) === '') {
                continue;
            }
            if ($token === '(' || $token === ')' || in_array($token, self::OPERATORS, true)) {
                $sql[] = $token;
            } elseif (ctype_digit($token)) {
                $sql[] = $token;
            } elseif (in_array(strtolower($token), self::KEYWORDS, true)) {
                $sql[] = strtolower($token);
            } elseif (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $token) === 1) {
                $sql[] = '`' . $this->column($token, $allowedColumns) . '`';
            } else {
                throw new \InvalidArgumentException("条件含非法字符: {$token}");
            }
        }

        return implode(' ', $sql);
    }
}
