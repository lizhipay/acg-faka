<?php

namespace Yurun\PaySDK\Traits;

trait FormParams
{
    public function __toString()
    {
        return $this->toString();
    }

    public function toString()
    {
        return http_build_query($this, '', '&');
    }
}
