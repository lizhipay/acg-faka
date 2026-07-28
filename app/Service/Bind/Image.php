<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Service\Upload;
use App\Util\Http;
use App\Util\Str;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Annotation\Inject;
use Kernel\Exception\JSONException;
use Kernel\Util\File;


class Image implements \App\Service\Image
{

    #[Inject]
    private Upload $upload;

    /**
     * @param string $imagePath
     * @param int $newHeight
     * @param string $basePath
     * @return bool|string
     */
    public function createThumbnail(string $imagePath, int $newHeight, string $basePath = BASE_PATH): bool|string
    {
        if ($newHeight < 1) {
            return $imagePath;
        }

        $baseImagePathInfo = pathinfo($imagePath);
        $thumbPath = $baseImagePathInfo['dirname'] . '/thumb/' . $baseImagePathInfo['basename'];

        if (is_file($basePath . $thumbPath)) {
            return $thumbPath;
        }

        $imageDiskPath = $basePath . $imagePath;

        $imageInfo = @getimagesize($imageDiskPath);
        if (!is_array($imageInfo)) {
            return false;
        }
        [$width, $height] = $imageInfo;
        if ($width < 1 || $height < 1) {
            return false;
        }

        if ($newHeight >= $height) {
            return $imagePath;
        }

        $imageType = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        $source = null;
        switch ($imageType) {
            case 'jpg':
            case 'jpeg':
                if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
                    return $imagePath;
                }
                $source = @imagecreatefromjpeg($imageDiskPath);
                break;
            case 'gif':
                if (!function_exists('imagecreatefromgif') || !function_exists('imagegif')) {
                    return $imagePath;
                }
                $source = @imagecreatefromgif($imageDiskPath);
                break;
            case 'png':
                if (!function_exists('imagecreatefrompng') || !function_exists('imagepng')) {
                    return $imagePath;
                }
                $source = @imagecreatefrompng($imageDiskPath);
                break;
            case 'webp':
                if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
                    return $imagePath;
                }
                $source = @imagecreatefromwebp($imageDiskPath);
                break;
            case 'ico':
                return $imagePath;
            default:
                return false;
        }

        if (!$source) {
            return $imagePath;
        }

        $newWidth = max(1, (int)($width / $height * $newHeight));

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if (!$thumb) {
            imagedestroy($source);
            return $imagePath;
        }

        if (!imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
            imagedestroy($thumb);
            imagedestroy($source);
            return $imagePath;
        }

        $pathInfo = pathinfo($imageDiskPath);
        $thumbnailDirectory = $pathInfo['dirname'] . '/thumb/';

        if (!file_exists($thumbnailDirectory)) {
            if (!mkdir($thumbnailDirectory, 0755, true)) {
                imagedestroy($thumb);
                imagedestroy($source);
                return $imagePath;
            }
        }

        $thumbnailPath = $thumbnailDirectory . $pathInfo['basename'];
        switch ($imageType) {
            case 'jpg':
            case 'jpeg':
                if (!imagejpeg($thumb, $thumbnailPath)) {
                    File::remove($thumbnailPath);
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return $imagePath;
                }
                break;
            case 'gif':
                if (!imagegif($thumb, $thumbnailPath)) {
                    File::remove($thumbnailPath);
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return $imagePath;
                }
                break;
            case 'png':
                if (!imagepng($thumb, $thumbnailPath)) {
                    File::remove($thumbnailPath);
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return $imagePath;
                }
                break;
            case 'webp':
                if (!imagewebp($thumb, $thumbnailPath)) {
                    File::remove($thumbnailPath);
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return $imagePath;
                }
                break;
        }

        imagedestroy($thumb);
        imagedestroy($source);

        return $thumbPath;
    }


    /**
     * @param string $filePath
     * @return bool
     */
    public function isRealImage(string $filePath): bool
    {
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo !== false) {
            return true;
        } else {
            return false;
        }
    }

    private function realImageExtension(string $filePath): ?string
    {
        $imageInfo = @getimagesize($filePath);
        if (!is_array($imageInfo)) {
            return null;
        }

        return match ((int)($imageInfo[2] ?? 0)) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_ICO => 'ico',
            default => null,
        };
    }

    private function normalizeImageExtension(
        string $imagePath,
        string $actualExtension,
        string $basePath = BASE_PATH
    ): bool|string
    {
        $currentExtension = strtolower((string)pathinfo($imagePath, PATHINFO_EXTENSION));
        $expectedExtension = $currentExtension === 'jpeg' ? 'jpg' : $currentExtension;
        if ($actualExtension === $expectedExtension) {
            return $imagePath;
        }

        $pathInfo = pathinfo($imagePath);
        $normalizedPath = rtrim($pathInfo['dirname'], '/') . '/' . $pathInfo['filename'] . '.' . $actualExtension;
        $normalizedDiskPath = $basePath . $normalizedPath;
        if (is_file($normalizedDiskPath) || !@rename($basePath . $imagePath, $normalizedDiskPath)) {
            return false;
        }
        return $normalizedPath;
    }

    /**
     * @param string $url
     * @return string
     */
    public function getImageExtensionFromURL(string $url): string
    {
        // 解析 URL 获取路径部分
        $path = parse_url($url, PHP_URL_PATH);
        return strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * @param $url
     * @return bool
     * @throws GuzzleException
     */
    public function isRealImageFromURL($url): bool
    {
        $response = Http::make()->head($url, [
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'timeout' => 10,
        ]);
        $mimeType = $response->getHeaderLine('Content-Type');
        $validImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon'];
        if (in_array($mimeType, $validImageTypes)) {
            return true;
        }
        return false;
    }

    /**
     * @param string $url
     * @param bool $isCreateThumbnail
     * @param int|null $userId
     * @return array
     * @throws GuzzleException
     * @throws JSONException
     */
    public function downloadRemoteImage(string $url, bool $isCreateThumbnail = true, ?int $userId = null): array
    {
        $extension = $this->getImageExtensionFromURL($url);

        if (!in_array($extension, ['jpg', 'jpeg', 'gif', 'png', 'webp', 'ico'])) {
            throw new JSONException("检测到[$url]不是一张有效的图片");
        }

        $imagePath = "/assets/cache/" . ($userId > 0 ? $userId : "general") . "/image/";
        $unique = $imagePath . date("Y-m-d/") . Str::generateRandStr() . ".{$extension}";

        $dir = dirname(BASE_PATH . $unique);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        try {
            Http::make()->get($url, [
                "sink" => BASE_PATH . $unique,
                'allow_redirects' => false,
                'connect_timeout' => 5,
                'timeout' => 20,
                'progress' => static function (int $downloadTotal, int $downloadedBytes): void {
                    if ($downloadTotal > 10485760 || $downloadedBytes > 10485760) {
                        throw new JSONException('远端图片超过 10MB，已停止下载');
                    }
                },
            ]);
        } catch (\Throwable $throwable) {
            if (is_file(BASE_PATH . $unique)) {
                File::remove(BASE_PATH . $unique);
            }
            if ($throwable instanceof JSONException) {
                throw $throwable;
            }
            throw new JSONException('远端图片下载失败');
        }
        if (!is_file(BASE_PATH . $unique)) {
            throw new JSONException("图片下载失败：$url");
        }

        $actualExtension = $this->realImageExtension(BASE_PATH . $unique);
        if ($actualExtension === null) {
            File::remove(BASE_PATH . $unique);
            throw new JSONException("检测到[{$url}]伪造成一张图片诱导本程序进行远程下载，风险极高，此文件已删除并粉碎！");
        }

        $normalizedUnique = $this->normalizeImageExtension($unique, $actualExtension);
        if ($normalizedUnique === false) {
            File::remove(BASE_PATH . $unique);
            throw new JSONException('远端图片真实格式识别成功，但本地文件格式修正失败');
        }
        $unique = $normalizedUnique;

        $hash = md5_file(BASE_PATH . $unique);
        $cache = $this->upload->get($hash);

        if ($cache && is_file(BASE_PATH . $cache)) {
            File::remove(BASE_PATH . $unique);
            if ($isCreateThumbnail) {
                $baseImagePathInfo = pathinfo($cache);
                $thumbPath = $baseImagePathInfo['dirname'] . '/thumb/' . $baseImagePathInfo['basename'];
                return [$cache, file_exists(BASE_PATH . $thumbPath) ? $thumbPath : $cache];
            }
            return [$cache];
        }

        if ($isCreateThumbnail) {
            $thumbUrl = $this->createThumbnail($unique, 128);
            if (!$thumbUrl) {
                if (is_file(BASE_PATH . $unique)) {
                    File::remove(BASE_PATH . $unique);
                }
                throw new JSONException("缩略图生成失败：{$url}");
            }

            $this->upload->add($unique, "image", $userId);
            return [$unique, $thumbUrl];
        }
        return [$unique];
    }
}
