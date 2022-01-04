<?php
declare(strict_types=1);

namespace App\Util;


class Captcha
{
    /**
     * 生成验证码
     * @param int $num
     * @param int $w
     * @param int $h
     */
    public static function generate(string $sessionName)
    {
        $w = 50;
        $h = 24;
        $num = 4;
        $code = "";
        for ($i = 0; $i < $num; $i++) {
            $code .= rand(0, 9);
        }
        //4位验证码也可以用rand(1000,9999)直接生成
        //将生成的验证码写入session，备验证页面使用
        $_SESSION[$sessionName] = $code;
        //创建图片，定义颜色值
        Header("Content-type: image/PNG");
        $im = imagecreate($w, $h);
        $black = imagecolorallocate($im, 250, 133, 203);
        $gray = imagecolorallocate($im, 245, 248, 243);
        imagefill($im, 0, 0, $gray);

        //画边框
        imagerectangle($im, 0, 0, $w - 1, $h - 1, $black);

        //随机绘制两条虚线，起干扰作用
        $style = array(
            $black,
            $black,
            $black,
            $black,
            $black,
            $gray,
            $gray,
            $gray,
            $gray,
            $gray
        );
        //imagesetstyle($im, $style);
        $y1 = rand(0, $h);
        $y2 = rand(0, $h);
        $y3 = rand(0, $h);
        $y4 = rand(0, $h);
        imageline($im, 0, $y1, $w, $y3, IMG_COLOR_STYLED);
        imageline($im, 0, $y2, $w, $y4, IMG_COLOR_STYLED);

        //在画布上随机生成大量黑点，起干扰作用;
        //  for ($i = 0; $i < 80; $i++) {
        // imagesetpixel($im, rand(0, $w), rand(0, $h), $black);
        // }
        //将数字随机显示在画布上,字符的水平间距和位置都按一定波动范围随机生成
        $strx = rand(3, 8);
        //  imagealphablending($black, false);
        for ($i = 0; $i < $num; $i++) {
            $strpos = rand(1, 6);
            imagestring($im, 5, $strx, $strpos, substr($code, $i, 1), $black);
            $strx += rand(8, 12);
        }


        imagepng($im);
        imagedestroy($im);
    }


    /**
     * 验证验证码是否正确
     * @param int $code
     * @param string $sessionName
     * @return bool
     */
    public static function check(int $code, string $sessionName): bool
    {
        $_code = $_SESSION[$sessionName];
        if ($code == 0) {
            return false;
        }
        if ($_code != $code) {
            return false;
        }
        return true;
    }

    /**
     * @param string $sessionName
     */
    public static function destroy(string $sessionName): void
    {
        unset($_SESSION[$sessionName]);
    }
}