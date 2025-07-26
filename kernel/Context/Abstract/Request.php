<?php
declare (strict_types=1);

namespace Kernel\Context\Abstract;


use Kernel\Util\Arr;
use Kernel\Waf\Filter;
use Kernel\Waf\Firewall;

abstract class Request implements \Kernel\Context\Interface\Request
{
    protected string $method;
    protected array $post = [];
    protected array $get = [];
    protected array $json = [];
    protected array $header = [];
    protected array $cookie = [];
    protected array $files = [];

    protected string $clientIp;
    protected string $url;
    protected string $domain;
    protected string $raw;

    protected string $uri;
    protected string $uriSuffix;


    public function __construct()
    {

        $_POST = $this->post = Firewall::instance()->xssKiller($this->post);
        $_GET = $this->get = Firewall::instance()->xssKiller($this->get);
        $_REQUEST = Firewall::instance()->xssKiller($_REQUEST);
        $this->json = Firewall::instance()->xssKiller($this->json);


        $_POST = Firewall::inst()->filterContent($_POST, Filter::STRING_UNSIGNED);
        $_GET = Firewall::inst()->filterContent($_GET, Filter::STRING_UNSIGNED);
        $_REQUEST = Firewall::inst()->filterContent($_REQUEST, Filter::STRING_UNSIGNED);

    }

    /**
     * @return string
     */
    public function method(): string
    {
        return $this->method;
    }


    /**
     * @param int $flags
     * @return mixed
     */
    public function all(int $flags = Filter::STRING_UNSIGNED): mixed
    {
        $all = array_merge($this->post, $this->get, $this->json);
        return Firewall::instance()->filterContent($all, $flags);
    }

    /**
     * @param string|null $key
     * @param int $flags
     * @return mixed
     */
    public function post(?string $key = null, int $flags = Filter::STRING_UNSIGNED): mixed
    {
        if ($key) {
            return Firewall::instance()->filterContent(Arr::get($this->post, $key), $flags);
        }
        return Firewall::instance()->filterContent($this->post, $flags);
    }

    /**
     * @param string|null $key
     * @param int $flags
     * @return mixed
     */
    public function xml(?string $key = null, int $flags = Filter::STRING_UNSIGNED): mixed
    {
        $data = Arr::xmlToArray($this->raw());
        if ($key) {
            return Firewall::instance()->filterContent(Arr::get($data, $key), $flags);
        }
        return Firewall::instance()->filterContent($data, $flags);
    }

    /**
     * @param string|null $key
     * @param int $flags
     * @return mixed
     */
    public function get(?string $key = null, int $flags = Filter::STRING_UNSIGNED): mixed
    {
        if ($key) {
            return Firewall::instance()->filterContent(Arr::get($this->get, $key), $flags);
        }
        return Firewall::instance()->filterContent($this->get, $flags);
    }


    /**
     * @param string|null $key
     * @return mixed
     */
    public function header(?string $key = null): mixed
    {
        if ($key) {
            return $this->header[$key] ?? null;
        }
        return $this->header;
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function cookie(?string $key = null): mixed
    {
        if ($key) {
            return $this->cookie[$key] ?? null;
        }
        return $this->cookie;
    }

    /**
     * @param string|null $key
     * @param int $flags
     * @return mixed
     */
    public function json(?string $key = null, int $flags = Filter::STRING_UNSIGNED): mixed
    {
        if ($key) {
            return Firewall::instance()->filterContent(Arr::get($this->json, $key), $flags);
        }
        return Firewall::instance()->filterContent($this->json, $flags);
    }


    /**
     * @param string|null $key
     * @return mixed
     */
    public function file(?string $key = null): mixed
    {
        if ($key) {
            return $this->files[$key] ?? null;
        }
        return $this->files;
    }

    /**
     * @return string
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * @return string
     */
    public function uriSuffix(): string
    {
        return $this->uriSuffix;
    }

    /**
     * @param string $property
     * @param mixed $value
     * @return void
     */
    public function setProperty(string $property, mixed $value): void
    {
        $this->{$property} = $value;
    }

    /**
     * @return string
     */
    public function domain(): string
    {
        return $this->domain;
    }


    /**
     * @return string
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return string
     */
    public function raw(): string
    {
        return $this->raw;
    }
}