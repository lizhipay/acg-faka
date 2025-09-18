<?php
declare (strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Image::class)]
interface Image
{
    /**
     * 生成缩略图
     * @param string $imagePath
     * @param int $newHeight
     * @param string $basePath
     * @return bool|string
     */
    public function createThumbnail(string $imagePath, int $newHeight, string $basePath = BASE_PATH): bool|string;


    /**
     * @param string $url
     * @param bool $isCreateThumbnail
     * @param int|null $userId
     * @return array
     */
    public function downloadRemoteImage(string $url, bool $isCreateThumbnail = true, ?int $userId = null): array;

    /**
     * @param $url
     * @return bool
     */
    public function isRealImageFromURL($url): bool;


    /**
     * @param string $url
     * @return string
     */
    public function getImageExtensionFromURL(string $url): string;


    /**
     * @param string $filePath
     * @return bool
     */
    public function isRealImage(string $filePath): bool;
}