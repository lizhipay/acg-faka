<?php

namespace Yurun\PaySDK\Traits;

trait JSONParams
{
    public function __toString()
    {
        $result = $this->toString();
        if (\is_string($result))
        {
            return $result;
        }
        else
        {
            return '';
        }
    }

    public function toString()
    {
        return json_encode($this);
    }
}
