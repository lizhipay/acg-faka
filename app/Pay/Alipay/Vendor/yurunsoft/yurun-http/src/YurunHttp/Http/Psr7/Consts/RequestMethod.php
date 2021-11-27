<?php

namespace Yurun\Util\YurunHttp\Http\Psr7\Consts;

/**
 * 常见的http请求方法.
 */
abstract class RequestMethod
{
    const GET = 'GET';

    const POST = 'POST';

    const HEAD = 'HEAD';

    const PUT = 'PUT';

    const PATCH = 'PATCH';

    const DELETE = 'DELETE';

    const OPTIONS = 'OPTIONS';

    const TRACE = 'TRACE';
}
