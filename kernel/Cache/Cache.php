<?php
declare(strict_types=1);

namespace Kernel\Cache;

use Kernel\Exception\RuntimeException;
use Kernel\Util\File;

class Cache
{

    private string $cacheDir;
    private int $resolve;

    public const OPTIONS_SERIALIZE = 0;
    public const OPTIONS_JSON = 1;
    public const OPTIONS_STRING = 2;

    public function __construct(string $path, int $resolve = self::OPTIONS_SERIALIZE)
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $this->cacheDir = rtrim($path, "/") . "/";
        $this->resolve = $resolve;
    }


    /**
     * @param string $name
     * @param mixed $value
     * @return void
     * @throws RuntimeException
     */
    public function set(string $name, mixed $value): void
    {
        $hashFile = $this->cacheDir . md5($name);
        File::writeForLock($hashFile, function (string $contents) use ($value) {
            return match ($this->resolve) {
                self::OPTIONS_SERIALIZE => serialize($value),
                self::OPTIONS_JSON => json_encode($value),
                default => $value,
            };
        });
    }

    /**
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return file_exists($this->cacheDir . md5($name));
    }


    /**
     * @param string $name
     * @return void
     */
    public function del(string $name): void
    {
        if ($this->has($name)) {
            unlink($this->cacheDir . md5($name));
        }
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function get(string $name): mixed
    {
        return File::read($this->cacheDir . md5($name), function (string $contents) {
            return match ($this->resolve) {
                self::OPTIONS_SERIALIZE => unserialize($contents),
                self::OPTIONS_JSON => json_decode($contents, true),
                default => $contents,
            };
        });
    }
}