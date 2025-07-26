<?php
declare (strict_types=1);

namespace Kernel\Annotation;

use Kernel\Component\Singleton;
use Kernel\Container\Di;
use Kernel\Util\Context;

class Collector
{
    /**
     * @var array
     */
    private array $collectors = [];

    use Singleton;


    /**
     * @throws \ReflectionException
     */
    private function getReflectionClass(mixed $object): \ReflectionClass
    {
        $class = gettype($object) == "string" ? $object : get_class($object);
        if (!isset($this->collectors[$class])) {
            $this->collectors[$class] = new \ReflectionClass($object);
        }
        return $this->collectors[$class];
    }


    /**
     * @param mixed $object
     * @param callable $callable
     * @return void
     * @throws \ReflectionException
     */
    public function classParse(mixed $object, callable $callable): void
    {
        $ref = $this->getReflectionClass($object);
        $reflectionAttributes = $ref->getAttributes();
        foreach ($reflectionAttributes as $attribute) {
            call_user_func_array($callable, [$attribute]);
        }
    }


    /**
     * @param mixed $object
     * @param callable $callable
     * @return void
     * @throws \ReflectionException
     */
    public function propertiesParse(mixed $object, callable $callable): void
    {
        //属性
        $ref = $this->getReflectionClass($object);
        $reflectionProperties = $ref->getProperties();
        foreach ($reflectionProperties as $property) {
            $reflectionProperty = new \ReflectionProperty($object, $property->getName());
            $reflectionPropertiesAttributes = $reflectionProperty->getAttributes();
            foreach ($reflectionPropertiesAttributes as $reflectionAttribute) {
                call_user_func_array($callable, [$reflectionAttribute, $property]);
            }
        }
    }


    /**
     * @param mixed $object
     * @param string $method
     * @param callable $callable
     * @return void
     * @throws \ReflectionException
     */
    public function methodParse(mixed $object, string $method, callable $callable): void
    {
        $methodRef = new \ReflectionMethod($object, $method);
        $methodReflectionAttributes = $methodRef->getAttributes();
        foreach ($methodReflectionAttributes as $attribute) {
            call_user_func_array($callable, [$attribute]);
        }
    }

    /**
     * @param mixed $object
     * @param string $method
     * @param array $data
     * @return array
     * @throws \ReflectionException
     */
    public function getMethodParameters(mixed $object, string $method, array $data): array
    {
        $methodRef = new \ReflectionMethod($object, $method);
        $parameters = [];
        foreach ($methodRef->getParameters() as $param) {
            $type = $param->getType()->getName();
            $name = $param->getName();
            //$allowsNull = $param->allowsNull();
            $value = $this->dat($type, $data[$name] ?? null);
            $parameters[$name] = $value;
        }
        return $parameters;
    }

    /**
     * @param mixed $object
     * @param string $method
     * @return array
     * @throws \ReflectionException
     */
    public function getMethodParameterAndTypes(mixed $object, string $method): array
    {
        $methodRef = new \ReflectionMethod($object, $method);
        $parameters = [];
        foreach ($methodRef->getParameters() as $param) {
            $type = $param->getType()->getName();
            $name = $param->getName();
            $parameters[] = [
                "name" => $name,
                "type" => $type
            ];
        }
        return $parameters;
    }


    /**
     * @param string $type
     * @param mixed $value
     * @return mixed
     * @throws \ReflectionException
     */
    public function dat(string $type, mixed $value): mixed
    {

        if (!$value && (class_exists($type) || interface_exists($type))) {
            if (Context::has($type)) {
                return Context::get($type);
            }
            return Di::instance()->make($type);
        }

        return match ($type) {
            "bool" => (boolean)$value,
            "int" => (integer)$value,
            "float" => (double)$value,
            "string" => (string)$value,
            "array" => (array)$value
        };
    }

}