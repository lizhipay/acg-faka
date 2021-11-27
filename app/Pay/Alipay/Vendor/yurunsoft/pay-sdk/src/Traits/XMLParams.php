<?php

namespace Yurun\PaySDK\Traits;

use Yurun\PaySDK\Lib\XML;

trait XMLParams
{
    public function __toString()
    {
        return $this->toString();
    }

    public function toString()
    {
        return XML::toString($this);
    }
}
