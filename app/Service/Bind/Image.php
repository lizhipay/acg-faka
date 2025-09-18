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
        $baseImagePathInfo = pathinfo($imagePath);
        $thumbPath = $baseImagePathInfo['dirname'] . '/thumb/' . $baseImagePathInfo['basename'];

        if (is_file($basePath . $thumbPath)) {
            return $thumbPath;
        }

        $imageDiskPath = $basePath . $imagePath;

        list($width, $height) = getimagesize($imageDiskPath);

        if ($newHeight >= $height) {
            return $imagePath;
        }

        $imageType = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        $source = null;
        switch ($imageType) {
            case 'jpg':
            case 'jpeg':
                $source = @imagecreatefromjpeg($imageDiskPath);
                break;
            case 'gif':
                $source = @imagecreatefromgif($imageDiskPath);
                break;
            case 'png':
                $source = @imagecreatefrompng($imageDiskPath);
                break;
            case 'webp':
                $source = @imagecreatefromwebp($imageDiskPath);
                break;
            default:
                return false;
        }

        if (!$source) {
            return false;
        }

        $newWidth = (int)($width / $height * $newHeight);

        $thumb = imagecreatetruecolor($newWidth, $newHeight);

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $pathInfo = pathinfo($imageDiskPath);
        $thumbnailDirectory = $pathInfo['dirname'] . '/thumb/';

        if (!file_exists($thumbnailDirectory)) {
            if (!mkdir($thumbnailDirectory, 0755, true)) {
                return false;
            }
        }

        $thumbnailPath = $thumbnailDirectory . $pathInfo['basename'];
        switch ($imageType) {
            case 'jpg':
            case 'jpeg':
                if (!imagejpeg($thumb, $thumbnailPath)) {
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return false;
                }
                break;
            case 'gif':
                if (!imagegif($thumb, $thumbnailPath)) {
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return false;
                }
                break;
            case 'png':
                if (!imagepng($thumb, $thumbnailPath)) {
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return false;
                }
                break;
            case 'webp':
                if (!imagewebp($thumb, $thumbnailPath)) {
                    imagedestroy($thumb);
                    imagedestroy($source);
                    return false;
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
        $imageInfo = getimagesize($filePath);
        if ($imageInfo !== false) {
            return true;
        } else {
            return false;
        }
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
        $response = Http::make()->head($url);
        $mimeType = $response->getHeaderLine('Content-Type');
        $validImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
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

        if (!in_array($extension, ['jpg', 'jpeg', 'gif', 'png', 'webp'])) {
            throw new JSONException("检测到[$url]不是一张有效的图片");
        }

        if (!$this->isRealImageFromURL($url)) {
            throw new JSONException("检测到[{$url}]不是一张图片，风险较高，请慎重接入！");
        }

        $imagePath = "/assets/cache/" . ($userId > 0 ? $userId : "general") . "/image/";
        $unique = $imagePath . date("Y-m-d/") . Str::generateRandStr() . ".{$extension}";

        $dir = dirname(BASE_PATH . $unique);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        Http::make()->get($url, [
            "sink" => BASE_PATH . $unique
        ]);
        if (!is_file(BASE_PATH . $unique)) {
            throw new JSONException("图片下载失败：$url");
        }

        if (!$this->isRealImage(BASE_PATH . $unique)) {
            File::remove(BASE_PATH . $unique);
            throw new JSONException("检测到[{$url}]伪造成一张图片诱导本程序进行远程下载，风险极高，此文件已删除并粉碎！");
        }

        $hash = md5_file(BASE_PATH . $unique);
        $cache = $this->upload->get($hash);

        if ($cache) {
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