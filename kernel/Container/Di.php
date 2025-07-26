<?php
declare (strict_types=1);

namespace Kernel\Container;

use Kernel\Annotation\Bind;
use Kernel\Annotation\Collector;
use Kernel\Annotation\Inject;
use Kernel\Component\Singleton;
use Kernel\Util\Context;

class Di
{
    use Singleton;

    /**
     * 容器列表
     * @var array
     */
    private array $container = [];


    /**
     * @param string $id
     * @param mixed $object
     * @param mixed ...$arg
     * @return void
     */
    public function set(string $id, mixed $object, ...$arg): void
    {
        $this->container[$id] = [$object, $arg];
    }

    /**
     * @param string $id
     * @return $this
     */
    public function del(string $id): static
    {
        unset($this->container[$id]);
        return $this;
    }

    /**
     * @return $this
     */
    public function clear(): static
    {
        $this->container = [];
        return $this;
    }

    /**
     * @param string $id
     * @return object
     */
    public function get(string $id): mixed
    {
        if (!isset($this->container[$id])) {
            return null;
        }

        list($object, $params) = $this->container[$id];

        if (is_object($object) || is_callable($object)) {
            return $object;
        }

        if (is_string($object) && class_exists($object)) {
            if ($params) {
                $this->container[$id][0] = new $object(...$params);
            } else {
                $this->container[$id][0] = new $object();
            }
            return $this->container[$id][0];
        }

        return $object;
    }

    /**
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->container[$id]);
    }


    /**
     * @param string $class
     * @param ...$arg
     * @return mixed
     * @throws \ReflectionException
     */
    public function make(string $class, ...$arg): mixed
    {
        if ($this->has($class)) {
            return $this->get($class);
        }
        if (interface_exists($class)) {
            Collector::instance()->classParse($class, function (\ReflectionAttribute $attr) use ($class) {
                if ($attr->getName() == Bind::class) {
                    $arguments = $attr->getArguments();
                    if (isset($arguments['class'])) {
                        $obj = new $arguments['class'];
                        $this->inject($obj);
                        $this->set($class, $obj);
                    }
                }
            });
            return $this->get($class);
        } else {
            $obj = new $class(...$arg);
            $this->inject($obj);
            $this->set($class, $obj);
        }
        return $obj;
    }


    /**
     * @param $object
     * @return void
     * @throws \ReflectionException
     */
    public function inject(&$object): void
    {
        Collector::instance()->propertiesParse($object, function (\ReflectionAttribute $attribute, \ReflectionProperty $property) use ($object) {
            //依赖注入
            if ($attribute->getName() == Inject::class) {
                $class = $property->getType()->getName();
                if (Context::has($class)) {
                    $obj = Context::get($class);
                } else {
                    if (!Di::instance()->has($class)) {
                        $service = false;
                        $obj = null;

                        Collector::instance()->classParse($class, function (\ReflectionAttribute $attr) use (&$service, &$obj) {
                            if ($attr->getName() == Bind::class) {
                                $arguments = $attr->getArguments();
                                if (isset($arguments['class'])) {
                                    $obj = new $arguments['class'];
                                    $service = true;
                                }
                            }
                        });


                        if (!$service) {
                            $obj = new $class;
                        }

                        Di::instance()->set($class, $obj);
                    } else {
                        $obj = Di::instance()->get($class);
                    }
                }
                \Closure::bind(function () use ($obj, $object, $property) {
                    $object->{$property->getName()} = $obj;
                }, null, $object)();

                $this->inject($obj);
            }
        });
    }
}