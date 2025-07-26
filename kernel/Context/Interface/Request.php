<?php
declare (strict_types=1);

namespace Kernel\Context\Interface;

use Kernel\Waf\Filter;

interface Request
{
    /**
     * 请求方法
     * @return string
     */
    public function method(): string;

    /**
     * @param int $flags
     * @return mixed
     */
    public function all(int $flags = Filter::STRING_UNSIGNED): mixed;


    /**
     * 获取POST参数
     * @param string|null $key
     * @param int $flags
     * @return array|string
     */
    public function post(?string $key = null, int $flags = Filter::STRING_UNSIGNED): mixed;

    /**
     * @param string|null $key
     * @return mixed
     */
    public function unsafePost(?string $key = null): mixed;


    /**
     * @param string|null $key
     * @param int $flags
     * @return mixed
     */
    public function xml(?string $key = null, int $flags = Filter::STRING_UNSIGNED): mixed;


    /**
     * 获取GET参数
     * @param string|null $key
     * @param int $flags
     * @return array|string
     */
    public function get(?string $key = null, int $flags = Filter::STRING_UNSIGNED): mixed;

    /**
     * @param string|null $key
     * @return mixed
     */
    public function unsafeGet(?string $key = null): mixed;


    /**
     * 获取header
     * @param string|null $key
     * @return mixed
     */
    public function header(?string $key = null): mixed;

    /**
     * @param string|null $key
     * @return mixed
     */
    public function cookie(?string $key = null): mixed;

    /**
     * 获取json数据
     * @param string|null $key
     * @param int $flags
     * @return mixed
     */
    public function json(?string $key = null, int $flags = Filter::STRING_UNSIGNED): mixed;

    /**
     * @param string|null $key
     * @return mixed
     */
    public function unsafeJson(?string $key = null): mixed;

    /**
     * @param string|null $key
     * @return mixed
     */
    public function file(?string $key = null): mixed;

    /**
     * 获取uri
     * @return string
     */
    public function uri(): string;

    /**
     * 获取uri后缀
     * @return string
     */
    public function uriSuffix(): string;


    /**
     * 设置属性
     * @param string $property
     * @param mixed $value
     * @return void
     */
    public function setProperty(string $property, mixed $value): void;

    /**
     * 当前URL地址
     * @return string
     */
    public function url(): string;


    /**
     * 当前访问的域名
     * @return string
     */
    public function domain(): string;

    /**
     * 未处理的body数据
     * @return string
     */
    public function raw(): string;
}