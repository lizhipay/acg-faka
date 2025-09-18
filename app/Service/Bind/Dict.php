<?php
declare(strict_types=1);

namespace App\Service\Bind;


use Illuminate\Database\Capsule\Manager as DB;

class Dict implements \App\Service\Dict
{

    /**
     * @param string $dictName
     * @param string $keywords
     * @param string $where
     * @return array
     */
    public function get(string $dictName, string $keywords = '', string $where = ''): array
    {
        $dict = explode(",", $dictName);

        $dictLength = count($dict);

        if ($dictLength >= 3) {
            //远程表字典查询
            $prefix = config('database')['prefix'];
            $table = explode('->', $dict[0]);

            try {
                $field = "{$dict[1]} as id,{$dict[2]} as name" . (array_key_exists(3, $dict) ? ",{$dict[3]} as pid" : '');
                $whereX = '';
                if ($keywords != '') {
                    $whereX .= " {$dict[2]} like '%{$keywords}%' and ";
                }

                if (count($table) == 2) {
                    $whereX .= "{$table[1]} and ";
                }

                if ($where != '') {
                    $whereX .= "{$where}";
                }

                if ($whereX != '') {
                    $whereX = 'where ' . $whereX;
                    $whereX = trim(trim(trim($whereX), 'and'));
                }
                $select = DB::select("select {$field} from {$prefix}{$table[0]} {$whereX} order by id desc");

            } catch (\Exception $e) {
                return [];
            }
           return array_map('get_object_vars', $select);
        }

        return [];
    }
}