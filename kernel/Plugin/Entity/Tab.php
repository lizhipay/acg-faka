<?php
declare (strict_types=1);

namespace Kernel\Plugin\Entity;

use Kernel\Component\ToArray;

class Tab
{
    use ToArray;

    public string $submit;
    public string $code;


    /**
     * @param string $submit
     * @param string $code
     */
    public function __construct(string $submit, string $code)
    {
        $this->code = $code;
        $this->submit = $submit;
    }
}