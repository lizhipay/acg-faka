<?php
declare(strict_types=1);

namespace App\Util;
use Zxing\QrReader;

ini_set('memory_limit', '1024M');

/**
 * Class QrCode
 * @package App\Util
 */
class QrCode
{
    /**
     * @param string $path
     * @return string
     */
    public static function parse(string $path): string
    {
        $qrReader = new QrReader($path);
        return (string)$qrReader->text();
    }
}