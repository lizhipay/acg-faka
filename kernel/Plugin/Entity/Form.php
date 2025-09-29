<?php
declare (strict_types=1);

namespace Kernel\Plugin\Entity;

use Kernel\Component\ToArray;

class Form
{
    use ToArray;

    public string $submit;
    public string $code;
    public string $field;
    public string $direction = "after";

    /**
     * @param string $submit
     * @param string $code
     * @param string $field
     * @param string $direction
     */
    public function __construct(string $submit, string $code, string $field, string $direction = "after")
    {
        $this->code = $code;
        $this->field = $field;
        $this->direction = $direction;
        $this->submit = $submit;
    }
}