<?php
declare (strict_types=1);
# install symfony/var-dump to your project
# composer require symfony/var-dumper

// use namespace
use Kernel\Util\Plugin;
use Kernel\Util\View;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper as SymfonyHtmlDumper;

/**
 * Class HtmlDumper
 */
class HtmlDumper extends SymfonyHtmlDumper
{
    /**
     * Colour definitions for output.
     *
     * @var array
     */
    protected $styles = [
        'default' => 'background-color:#fff; color:#222; line-height:1.2em; font-weight:normal; font:12px Monaco, Consolas, monospace; word-wrap: break-word; white-space: pre-wrap; position:relative; z-index:100000',
        'num' => 'color:#a71d5d',
        'const' => 'color:#795da3',
        'str' => 'color:#df5000',
        'cchr' => 'color:#222',
        'note' => 'color:#a71d5d',
        'ref' => 'color:#a0a0a0',
        'public' => 'color:#795da3',
        'protected' => 'color:#795da3',
        'private' => 'color:#795da3',
        'meta' => 'color:#b729d9',
        'key' => 'color:#df5000',
        'index' => 'color:#a71d5d',
    ];
}

/**
 * Class Dumper
 */
class Dumper
{
    /**
     * Dump a value with elegance.
     *
     * @param mixed $value
     * @return void
     */
    public function dump($value)
    {
        if (class_exists(CliDumper::class)) {
            $dumper = 'cli' === PHP_SAPI ? new CliDumper : new HtmlDumper;
            $dumper->dump((new VarCloner)->cloneVar($value));
        } else {
            var_dump($value);
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump the passed variables and end the script.
     *
     * @param mixed
     * @return void
     */
    function dd(...$args)
    {
        foreach ($args as $x) {
            (new Dumper)->dump($x);
        }
        die(1);
    }
}

if (!function_exists('dda')) {
    /**
     * Dump the passed array variables and end the script.
     *
     * @param mixed
     * @return void
     */
    function dda(...$args)
    {
        foreach ($args as $x) {
            (new Dumper)->dump($x->toArray());
        }
        die(1);
    }
}


if (!function_exists("config")) {
    /**
     * @param string $name
     * @return array
     */
    function config(string $name): array
    {
        $data = \Kernel\Util\Context::get("config_" . $name);
        if ($data) {
            return $data;
        }
        $file = BASE_PATH . '/config/' . $name . ".php";
        if (!file_exists($file)) {
            return [];
        }
        $data = require($file);
        \Kernel\Util\Context::set("config_" . $name, $data);
        return $data;
    }
}
if (!function_exists("setConfig")) {
    /**
     * @param array $data
     * @param string $file
     * @param bool $reset
     * @throws \Kernel\Exception\JSONException
     */
    function setConfig(array $data, string $file, bool $reset = false): void
    {
        if (file_exists($file) && !$reset) {
            $config = require($file);
        } else {
            $config = [];
        }
        foreach ($data as $x => $b) {
            $config[$x] = $b;
        }
        //写入到文件
        $ret = "<?php
declare (strict_types=1);\n\nreturn [\n";
        foreach ($config as $k => $v) {
            if (is_array($v)) {
                $akv = "[";
                foreach ($v as $av) {
                    $akv .= "'" . str_replace("'", "\\'", $av) . "'" . ",";
                }
                $akv = trim($akv, ",");
                $akv .= "]";
                $value = $akv;
            } else {
                $value = "'" . str_replace("'", "\\'", (string)$v) . "'";
            }
            $ret .= "    '{$k}' => $value,\n";
        }
        $ret .= '];';
        if (file_put_contents($file, $ret) === false) {
            throw new \Kernel\Exception\JSONException("没有文件写入权限");
        }
    }
}

if (!function_exists("di")) {
    /**
     * @param $object
     * @throws ReflectionException
     */
    function di(&$object)
    {
        $dependencies = config("dependencies");
        $ref = new \ReflectionClass($object);
        $reflectionProperties = $ref->getProperties();
        foreach ($reflectionProperties as $property) {
            $bs = $property->getAttributes();
            $bt = 0;
            foreach ($bs as $b) {
                if ($b->getName() == \Kernel\Annotation\Inject::class) {
                    $bt++;
                }
            }
            if ($bt == 0) {
                continue;
            }
            $reflectionProperty = new \ReflectionProperty($object, $property->getName());
            #拿到对象类型
            $type = $reflectionProperty->getType()->getName();
            $reflectionPropertiesAttributes = $reflectionProperty->getAttributes();
            foreach ($reflectionPropertiesAttributes as $propertiesAttribute) {
                $ins = $propertiesAttribute->newInstance();
                if ($ins instanceof \Kernel\Annotation\Inject) {
                    $service = $dependencies[$type];
                    if ($service) {
                        $obj = new $service;
                    } else {
                        $obj = new $type;
                    }
                    Closure::bind(function () use ($obj, $object, $property) {
                        $object->{$property->getName()} = $obj;
                    }, null, $object)();
                    di($obj);
                }
            }
        }
    }
}


if (!function_exists("dat")) {
    function dat(string $type, $value): float|object|int|bool|array|string
    {
        return match ($type) {
            "bool" => (boolean)$value,
            "int" => (integer)$value,
            "float" => (double)$value,
            "string" => (string)$value,
            "array" => (array)$value,
            "object" => (object)$value,
        };
    }
}
if (!function_exists("getLocalRouter")) {
    function getLocalRouter(): string
    {
        return \Kernel\Util\Context::get(\Kernel\Consts\Base::ROUTE);
    }
}

if (!function_exists("feedback")) {
    function feedback(string $value)
    {
        if ($value != "404 Not Found") {
            debug($value);
        }

        if (!DEBUG) {
            return View::render("404.html", ["msg" => "404 Not Found"]);
        }

        return "<!DOCTYPEhtml><htmllang='zh-CN'><head><meta name='viewport' content='width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no'><metacharset='utf-8'><title>{$value}</title></head><body style='margin: 0px;'><center style='color: #ffffff;background-color: #ff6f6f;padding-top: 18px;padding-bottom: 18px;font-size: 18px;'>{$value}</center></body></html>";
    }
}


if (!function_exists("hook")) {
    function hook(int $point, mixed &...$args)
    {
        $result = Plugin::hook($point, ...$args);
        if ($result) {
            return $result;
        }
    }
}


if (!function_exists("getHookNum")) {
    function getHookNum(int $point): int
    {
        return Plugin::getHookNum($point);
    }
}


if (!function_exists("debug")) {
    function debug(string $message): void
    {
        $path = BASE_PATH . '/runtime.log';
        file_put_contents($path, "[" . date("Y-m-d H:i:s", time()) . "]:" . $message . PHP_EOL, FILE_APPEND);
    }
}
