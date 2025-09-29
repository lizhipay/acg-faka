<?php
declare (strict_types=1);

namespace Kernel\Plugin\Entity;

use Kernel\Component\ToArray;

class Column
{
    use ToArray;

    public string $route;
    public string $code;
    public string $field;
    public string $direction = "after";


    /**
     * @param string $route
     * @param string $code
     * @param string $field
     * @param string $direction
     */
    public function __construct(string $route, string $code, string $field, string $direction = "after")
    {
        $this->code = $code;
        $this->field = $field;
        $this->direction = $direction;
        $this->route = $route;
    }
}