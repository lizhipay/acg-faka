<?php
declare(strict_types=1);

namespace App\Util;


use Kernel\Exception\JSONException;
use Rah\Danpu\Exception;

class Zip
{

    /**
     * @param $filePath
     * @param $path
     * @return bool
     * @throws \Kernel\Exception\JSONException
     */
    public static function unzip($filePath, $path): bool
    {
        try {
            if (empty($path) || empty($filePath)) {
                return false;
            }
            $zip = new \ZipArchive();
            if ($zip->open($filePath) === true) {
                $zip->extractTo($path);
                $zip->close();
                return true;
            } else {
                return false;
            }
        } catch (Exception $e) {
            throw new JSONException("解压缩失败，请安装php-zip扩展！");
        }
    }
}