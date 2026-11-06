<?php
declare(strict_types=1);

namespace App\Util;

/**
 * 上传文件内容校验（#1136）。
 *
 * 只看文件头和真实解码结果，不信浏览器给的 type、也不信后缀：把任意内容改名成 .zip/.png
 * 传上来、再借站点域名公开下载，就是 #1136 说的「拿你的域名挂恶意文件」。
 * 环境约束（见运维记录）：没有 fileinfo，判图片靠 getimagesize() + GD；nginx 会执行 Web 根下任何 .php。
 */
final class UploadGuard
{
    /** 会员能上传的图片（前台只用到图片：头像、商品图、编辑器插图） */
    public const MEMBER_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** 不论调用方允许什么，这些后缀一律不落盘：会被执行、或会被浏览器当页面渲染 */
    private const DENY_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'inc',
        'htaccess', 'user.ini', 'ini', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'js', 'mjs',
        'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll', 'hta', 'vbs',
    ];

    private const IMAGE_MIME = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'], 'ico' => ['image/vnd.microsoft.icon', 'image/x-icon'],
    ];

    /** 解码上限：再大就是解压炸弹，GD 解码要吃掉几百 MB 内存 */
    private const MAX_PIXELS = 40000000;

    public static function denied(string $extension): bool
    {
        return in_array(strtolower($extension), self::DENY_EXTENSIONS, true);
    }

    /**
     * 文件内容是否与后缀相符
     * @return string|null 不相符时的提示；通过或该后缀没有已知格式（由调用方的白名单负责）时返回 null
     */
    public static function contentError(string $path, string $extension): ?string
    {
        $extension = strtolower($extension);
        if (self::denied($extension)) {
            return "不允许上传 .{$extension} 文件";
        }
        $size = is_file($path) ? (int)filesize($path) : 0;
        if ($size <= 0) {
            return "文件是空的";
        }

        if (isset(self::IMAGE_MIME[$extension])) {
            $info = @getimagesize($path);
            $mime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
            if (!$info || !in_array($mime, self::IMAGE_MIME[$extension], true) || (int)$info[0] <= 0 || (int)$info[1] <= 0) {
                return "文件内容不是有效的 " . strtoupper($extension) . " 图片";
            }
            if ((int)$info[0] * (int)$info[1] > self::MAX_PIXELS) {
                return "图片尺寸过大（{$info[0]}×{$info[1]}），请缩小后再上传";
            }
            return null;
        }

        $head = (string)file_get_contents($path, false, null, 0, 512);
        $ok = match ($extension) {
            'zip', 'docx', 'xlsx', 'pptx', 'apk' => str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06"),
            'rar' => str_starts_with($head, "Rar!\x1A\x07"),
            '7z' => str_starts_with($head, "7z\xBC\xAF\x27\x1C"),
            'gz' => str_starts_with($head, "\x1F\x8B"),
            'tar' => substr($head, 257, 5) === 'ustar',
            'pdf' => str_starts_with($head, '%PDF-'),
            'doc', 'xls', 'ppt' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'mp4', 'm4a', 'mov' => in_array(substr($head, 4, 4), ['ftyp', 'moov', 'wide', 'free', 'mdat', 'skip'], true),
            'webm', 'mkv' => str_starts_with($head, "\x1A\x45\xDF\xA3"),
            'mp3' => str_starts_with($head, 'ID3') || (strlen($head) > 1 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0),
            'woff' => str_starts_with($head, 'wOFF'),
            'woff2' => str_starts_with($head, 'wOF2'),
            'ttf' => str_starts_with($head, "\x00\x01\x00\x00") || str_starts_with($head, 'true') || str_starts_with($head, 'ttcf') || str_starts_with($head, 'OTTO'),
            'otf' => str_starts_with($head, 'OTTO') || str_starts_with($head, "\x00\x01\x00\x00"),
            //Excel 的「Unicode 文本」导出是 UTF-16（带 BOM、到处是 0 字节），其余文本不该出现 0 字节
            'txt', 'csv', 'md' => str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")
                || !str_contains((string)file_get_contents($path, false, null, 0, 65536), "\x00"),
            default => null,
        };
        if ($ok === false) {
            return "文件内容与 ." . $extension . " 格式不符";
        }
        return null;
    }

    /**
     * 会员上传的图片：校验后用 GD 重新编码（JPG/PNG/WebP），丢掉 EXIF 与附在图片后面的任何数据；
     * GIF 重新编码会丢动画，只校验并拒绝夹带了压缩包/脚本特征的文件。原地改写。
     * @return string|null 失败时的提示
     */
    public static function sanitizeImage(string $path, string $extension): ?string
    {
        $extension = strtolower($extension);
        if (!in_array($extension, self::MEMBER_IMAGE_EXTENSIONS, true)) {
            return "仅支持 JPG、PNG、WebP、GIF 图片";
        }
        if ($error = self::contentError($path, $extension)) {
            return $error;
        }

        if ($extension === 'gif') {
            $raw = (string)file_get_contents($path);
            foreach (["PK\x03\x04", "PK\x05\x06", "Rar!\x1A", "7z\xBC\xAF", '<?php', '<?=', '<script', '<html', '<svg'] as $needle) {
                if (stripos($raw, $needle) !== false) {
                    return "图片里夹带了不属于图片的内容，请换一张";
                }
            }
            return null;
        }

        if (!function_exists('imagecreatetruecolor')) {
            return null; //没有 GD 时只做格式校验
        }
        $info = @getimagesize($path);
        $width = (int)$info[0];
        $height = (int)$info[1];
        if (!self::ensureMemory($width * $height)) {
            return "图片太大，请压缩后再上传";
        }

        $image = match ($extension) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        };
        if (!$image) {
            return $extension === 'webp' ? "暂不支持动态 WebP，请改用 GIF 或静态图片" : "图片无法解析，请换一张";
        }

        if ($extension === 'jpg' || $extension === 'jpeg') {
            //EXIF 会被丢掉，手机照片的方向要先套用进像素里，否则头像会横躺
            $image = self::applyOrientation($image, self::jpegOrientation($path));
        }

        $temp = $path . '.reenc';
        $written = match ($extension) {
            'jpg', 'jpeg' => imagejpeg($image, $temp, 90),
            'png' => (static function ($image, $temp): bool {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                return imagepng($image, $temp, 6);
            })($image, $temp),
            'webp' => (static function ($image, $temp): bool {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                return imagewebp($image, $temp, 90);
            })($image, $temp),
        };
        imagedestroy($image);
        if (!$written || !@rename($temp, $path)) {
            @unlink($temp);
            return "图片处理失败，请稍后再试";
        }
        clearstatcache(true, $path);
        return null;
    }

    /** GD 解码约需 宽×高×5 字节；不够时尝试临时调高 memory_limit */
    private static function ensureMemory(int $pixels): bool
    {
        $need = $pixels * 5 + 32 * 1024 * 1024;
        $limit = self::bytes((string)ini_get('memory_limit'));
        if ($limit < 0 || memory_get_usage() + $need <= $limit) {
            return true;
        }
        $target = (int)ceil((memory_get_usage() + $need) / 1048576) + 16;
        return @ini_set('memory_limit', $target . 'M') !== false && self::bytes((string)ini_get('memory_limit')) >= memory_get_usage() + $need;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int)$value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** 读 JPEG 的 EXIF Orientation（1–8），没有就当 1。没装 exif 扩展，手动解析 APP1 里的 TIFF 头 */
    private static function jpegOrientation(string $path): int
    {
        $data = (string)file_get_contents($path, false, null, 0, 131072);
        $offset = 2;
        $length = strlen($data);
        while ($offset + 4 <= $length && $data[$offset] === "\xFF") {
            $marker = ord($data[$offset + 1]);
            $size = unpack('n', substr($data, $offset + 2, 2))[1];
            if ($marker === 0xE1 && substr($data, $offset + 4, 6) === "Exif\x00\x00") {
                $tiff = substr($data, $offset + 10, $size - 8);
                $little = substr($tiff, 0, 2) === 'II';
                $u16 = static fn(string $s): int => unpack($little ? 'v' : 'n', $s)[1];
                $u32 = static fn(string $s): int => unpack($little ? 'V' : 'N', $s)[1];
                if (strlen($tiff) < 8) {
                    return 1;
                }
                $ifd = $u32(substr($tiff, 4, 4));
                if ($ifd + 2 > strlen($tiff)) {
                    return 1;
                }
                $entries = $u16(substr($tiff, $ifd, 2));
                for ($i = 0; $i < $entries; $i++) {
                    $entry = $ifd + 2 + $i * 12;
                    if ($entry + 12 > strlen($tiff)) {
                        break;
                    }
                    if ($u16(substr($tiff, $entry, 2)) === 0x0112) {
                        $value = $u16(substr($tiff, $entry + 8, 2));
                        return $value >= 1 && $value <= 8 ? $value : 1;
                    }
                }
                return 1;
            }
            if ($marker === 0xDA) {
                break; //图像数据开始，后面不会再有 EXIF
            }
            $offset += 2 + $size;
        }
        return 1;
    }

    /** @param \GdImage|resource $image */
    private static function applyOrientation($image, int $orientation)
    {
        if ($orientation <= 1) {
            return $image;
        }
        //先转再镜像（imagerotate 的角度是逆时针）：6=顺转 90°、8=逆转 90°、2/4/5/7 带水平镜像
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0)) !== false) {
            imagedestroy($image);
            $image = $rotated;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        return $image;
    }
}
