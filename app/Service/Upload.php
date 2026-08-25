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
     * 记录一条上传。
     *
     * acg_upload 的唯一索引只锁 hash(全局)，而去重是按用户隔离的(见 get 的 $userId)，
     * 所以同一张图被别的账号传过时这里必然撞唯一键。返回值就是为这种情况准备的：
     * 返回已有记录的路径 = 本次没能落库，调用方应复用那份文件并清掉自己刚落地的副本；
     * 返回 null = 正常落库(或无从复用)，按原路径继续。
     *
     * @param string $path
     * @param string $type
     * @param int|null $userId
     * @return string|null 撞全局唯一键时返回可复用的已有文件路径
     */
    public function add(string $path, string $type, ?int $userId = null): ?string;


    /**
     * @param string $hash
     * @param int|null $userId 指定后仅在该用户自己的上传记录内去重(null=全局,保持旧行为)
     * @return string|null
     */
    public function get(string $hash, ?int $userId = null): ?string;


    /**
     * @param string $path
     * @return void
     */
    public function remove(string $path): void;
}