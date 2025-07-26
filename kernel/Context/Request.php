<?php
declare (strict_types=1);

namespace Kernel\Context;

class Request extends Abstract\Request
{
    public function __construct()
    {
        $this->post = $_POST;
        $this->method = strtoupper($_SERVER['REQUEST_METHOD']);
        $this->get = $_GET;
        $this->header = $this->parseHeader();
        $this->cookie = $_COOKIE;
        $uri = "/" . trim($_GET['_route'] ?? "/", "/");
        $uris = explode(".", $uri);
        $this->uri = (string)$uris[0];
        $this->uriSuffix = $uris[1] ?? "";
        $this->raw = (string)file_get_contents("php://input");
        $this->files = $_FILES;
        unset($this->get['_route']);

        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $this->clientIp = (string)$arr[0];
        } else {
            $this->clientIp = (string)$_SERVER['REMOTE_ADDR'];
        }

        if (str_contains((string)$this->header("ContentType"), "application/json")) {
            $this->json = (array)json_decode($this->raw);
        }

        if (isset($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) == "on") {
            $this->header['Scheme'] = "https";
        } elseif (!isset($_SERVER['REQUEST_SCHEME'])) {
            $this->header['Scheme'] = "http";
        } else {
            $this->header['Scheme'] = $_SERVER['REQUEST_SCHEME'];
        }

        $this->url = $this->header['Origin'] ?? $this->header['Scheme'] . '://' . $this->header['Host'];
        $this->domain = (string)explode(":", (string)$_SERVER['HTTP_HOST'])[0];

        parent::__construct();
    }


    /**
     * @return array
     */
    private function parseHeader(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $key = substr($key, 5);
                $key = str_replace('_', ' ', $key);
                $key = ucwords(strtolower($key));
                $key = str_replace(" ", "", $key);
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}