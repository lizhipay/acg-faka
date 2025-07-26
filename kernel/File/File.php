<?php
declare (strict_types=1);

namespace Kernel\File;

use Kernel\Exception\RuntimeException;

class File
{
    /**
     * @var mixed|bool
     */
    public mixed $resource = false;

    /**
     * @var string
     */
    public string $path = "";


    /**
     * @var bool
     */
    private bool $lock = false;


    /**
     * @var int
     */
    public int $size = 0;

    /**
     * @param string $path
     * @param string $mode
     * @throws RuntimeException
     */
    public function __construct(string $path, string $mode = "r")
    {
        if (!file_exists($path)) {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
                throw new RuntimeException('could not create the directory:' . $directory);
            }
            $file = fopen($path, 'w');
            if ($file === false) {
                throw new RuntimeException('could not create the file:' . $path);
            }
            fclose($file);
            chmod($path, 0777);
        }

        $resource = fopen($path, $mode);
        if ($resource === false) {
            throw new RuntimeException('could not open the file:' . $path);
        }
        $this->resource = $resource;
        $this->path = $path;
    }


    /**
     * @return File
     * @throws RuntimeException
     */
    public function shareLock(): self
    {
        if (!flock($this->resource, LOCK_SH)) {
            $this->close();
            throw new RuntimeException('could not get the lock:' . $this->path);
        }
        $this->lock = true;
        return $this;
    }

    /**
     * @return File
     * @throws RuntimeException
     */
    public function lock(): self
    {
        if (!flock($this->resource, LOCK_EX)) {
            $this->close();
            throw new RuntimeException('could not get the lock:' . $this->path);
        }
        $this->lock = true;
        return $this;
    }

    /**
     * @return File
     */
    public function unlock(): self
    {
        if (!$this->lock) {
            return $this;
        }
        $this->lock = false;
        flock($this->resource, LOCK_UN);
        return $this;
    }

    public function close(): void
    {
        $this->unlock();
        fclose($this->resource);
    }

    /**
     * @return int
     */
    public function size(): int
    {
        $this->size = filesize($this->path);
        return $this->size;
    }

    /**
     * @return string
     */
    public function contents(): string
    {
        if (!file_exists($this->path)) {
            return "";
        }
        clearstatcache();
        $size = $this->size();
        if ($size <= 0) {
            return "";
        }
        return (string)fread($this->resource, $this->size());
    }


    /**
     * @throws RuntimeException
     */
    public function write(string $contents): void
    {
        if (fwrite($this->resource, $contents) === false) {
            throw new RuntimeException('could not write to the file:' . $this->path);
        }
    }


    /**
     * @return void
     */
    public function rewind(): void
    {
        rewind($this->resource);
    }


    /**
     * @return void
     */
    public function autoTruncate(): void
    {
        ftruncate($this->resource, ftell($this->resource));
    }

}