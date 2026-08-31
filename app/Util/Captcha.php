<?php
declare(strict_types=1);

namespace App\Util;


use Kernel\Util\Session;

class Captcha
{
    /**
     * 输出尺寸必须与旧版一致：部分主题的 <img> 没有约束宽高，
     * 直接按原始尺寸显示，一改就会把表单撑变形。
     */
    private const W = 50;
    private const H = 24;

    /**
     * 超采样倍数。50x24 太小，直接画锯齿严重；
     * 放大 4 倍绘制再降采样，边缘平滑度接近矢量，输出尺寸却不变。
     */
    private const SS = 4;

    /** 位数。check() 以 int 比较，所以只能是纯数字 */
    private const LEN = 4;

    /** 正文字体：干净的几何无衬线，数字辨识度高 */
    private const FONT = '/assets/common/fonts/font.ttf';

    /**
     * 生成验证码
     * @param string $sessionName
     */
    public static function generate(string $sessionName): void
    {
        $code = '';
        for ($i = 0; $i < self::LEN; $i++) {
            $code .= random_int(0, 9);
        }
        Session::set($sessionName, $code);

        //验证码是一次性凭据，任何一层缓存住都会让用户看到过期的图
        if (!headers_sent()) {
            header('Content-Type: image/png');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        //先在放大画布上绘制
        $bw = self::W * self::SS;
        $bh = self::H * self::SS;
        $big = imagecreatetruecolor($bw, $bh);
        imagesavealpha($big, true);
        //先关混合，把画布刷成全透明；再开混合，后续绘制才能正常叠色
        imagealphablending($big, false);
        imagefilledrectangle($big, 0, 0, $bw - 1, $bh - 1, imagecolorallocatealpha($big, 0, 0, 0, 127));
        imagealphablending($big, true);
        imageantialias($big, true);

        self::drawGuides($big, $bw, $bh);
        self::drawDigits($big, $bw, $bh, $code);

        //降采样到实际输出尺寸：这一步把锯齿抹平，等效于抗锯齿。
        //目标画布同样要关混合、开存 alpha，否则透明通道会在复制时被丢掉
        $im = imagecreatetruecolor(self::W, self::H);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefilledrectangle($im, 0, 0, self::W - 1, self::H - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagecopyresampled($im, $big, 0, 0, 0, 0, self::W, self::H, $bw, $bh);
        imagedestroy($big);

        imagepng($im, null, 9);
        imagedestroy($im);
    }


    /**
     * 干扰线：两条平缓的正弦曲线，低透明度的钢蓝。
     * 用曲线而不是直线，是因为直线容易被形态学处理一次性抹掉；
     * 低透明度则保证它干扰机器但不影响人眼读数。
     *
     * @param \GdImage $im
     */
    private static function drawGuides($im, int $w, int $h): void
    {
        for ($n = 0; $n < 2; $n++) {
            $color = imagecolorallocatealpha(
                $im,
                random_int(96, 122),
                random_int(110, 138),
                random_int(152, 182),
                random_int(96, 108)         //0=不透明 127=全透明
            );
            //线宽按超采样倍数走，降采样后才是 1px 上下的细线
            imagesetthickness($im, self::SS);

            $amp = (int)round($h * random_int(7, 12) / 100);   //振幅取画布高度的比例
            $phase = random_int(0, 628) / 100;                 //相位 0~2π
            $freq = random_int(100, 170) / 100;                //周期数
            $mid = random_int((int)($h * .32), (int)($h * .68));

            $step = max(2, (int)($w / 40));
            $prevX = 0;
            $prevY = (int)round($mid + sin($phase) * $amp);
            for ($x = $step; $x <= $w; $x += $step) {
                $y = (int)round($mid + sin($phase + $x / $w * $freq * M_PI * 2) * $amp);
                imageline($im, $prevX, $prevY, $x, $y, $color);
                $prevX = $x;
                $prevY = $y;
            }
        }
        imagesetthickness($im, 1);
    }

    /**
     * 数字：TTF 抗锯齿渲染，逐字轻微旋转与错位。
     * 缺字体或 GD 未编译 FreeType 时回落到内置位图字体，保证任何环境都出得来图。
     *
     * @param \GdImage $im
     * @param string $code
     */
    private static function drawDigits($im, int $w, int $h, string $code): void
    {
        $font = BASE_PATH . self::FONT;
        $ttf = function_exists('imagettftext') && is_file($font);

        if (!$ttf) {
            self::drawDigitsFallback($im, $w, $h, $code);
            return;
        }

        //按位数均分宽度，两侧留出边距
        $padding = (int)round($w * .06);
        $cell = ($w - $padding * 2) / self::LEN;
        $size = (int)round($h * .52);

        for ($i = 0; $i < self::LEN; $i++) {
            //只在明度上浮动、三通道同增同减：保持同一个色相，避免出现偏绿/偏紫的杂色
            $d = random_int(-9, 9);
            $color = imagecolorallocate($im, 31 + $d, 37 + $d, 51 + $d);

            //最终只有 50x24，旋转和位移都要克制，否则降采样后字会糊在一起
            $angle = random_int(-6, 6);
            $x = (int)round($padding + $cell * $i + $cell * .12 + random_int(-1, 1) * self::SS);
            $y = (int)round($h * .76 + random_int(-1, 1) * self::SS);

            imagettftext($im, $size, $angle, $x, $y, $color, $font, $code[$i]);
        }
    }

    /**
     * 无 FreeType 时的兜底：内置位图字体，样式朴素但可用
     *
     * @param \GdImage $im
     * @param string $code
     */
    private static function drawDigitsFallback($im, int $w, int $h, string $code): void
    {
        $color = imagecolorallocate($im, 32, 36, 50);
        $cell = (int)($w / self::LEN);
        //内置位图字体不随画布缩放，按 SS 倍画布尺寸换算位置
        for ($i = 0; $i < self::LEN; $i++) {
            imagestring($im, 5, (int)($cell * $i + $cell / 2 - 4 * self::SS), (int)($h / 2 - 8 * self::SS), $code[$i], $color);
        }
    }



    /**
     * 验证验证码是否正确
     * @param int $code
     * @param string $sessionName
     * @return bool
     */
    public static function check(int $code, string $sessionName): bool
    {
        if ($code == 0) {
            return false;
        }
        if (Session::get($sessionName) != $code) {
            return false;
        }
        return true;
    }

    /**
     * @param string $sessionName
     */
    public static function destroy(string $sessionName): void
    {
        Session::remove($sessionName);
    }
}
