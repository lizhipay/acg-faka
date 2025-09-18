<?php
declare(strict_types=1);

namespace App\Service;

use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Upload::class)]
interface Upload
{
    /**
     * 文件上传
     * @param $upload
     * @param $dir
     * @param $type
     * @param int $size
     * @param string $file_name
     * @return mixed
     */
    public function handle($upload, $dir, $type, int $size = 10000, string $file_name = ''): mixed;

    /**
     * @param string $path
     * @param string $type
     * @param int|null $userId
     * @return void
     */
    public function add(string $path, string $type, ?int $userId = null): void;


    /**
     * @param string $hash
     * @return string|null
     */
    public function get(string $hash): ?string;


    /**
     * @param string $path
     * @return void
     */
    public function remove(string $path): void;
}