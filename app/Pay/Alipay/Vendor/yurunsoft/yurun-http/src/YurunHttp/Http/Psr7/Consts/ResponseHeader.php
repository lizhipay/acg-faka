<?php

namespace Yurun\Util\YurunHttp\Http\Psr7\Consts;

/**
 * 常见的http响应头.
 */
abstract class ResponseHeader
{
    const ACCESS_CONTROL_ALLOW_ORIGIN = 'Access-Control-Allow-Origin';
    const ACCEPT_PATCH = 'Accept-Patch';
    const ACCEPT_RANGES = 'Accept-Ranges';
    const AGE = 'Age';
    const ALLOW = 'Allow';
    const CACHE_CONTROL = 'Cache-Control';
    const CONNECTION = 'Connection';
    const CONTENT_DISPOSITION = 'Content-Disposition';
    const CONTENT_ENCODING = 'Content-Encoding';
    const CONTENT_LANGUAGE = 'Content-Language';
    const CONTENT_LENGTH = 'Content-Length';
    const CONTENT_LOCATION = 'Content-Location';
    const CONTENT_MD5 = 'Content-MD5';
    const CONTENT_RANGE = 'Content-Range';
    const CONTENT_TYPE = 'Content-Type';
    const DATE = 'Date';
    const ETAG = 'ETag';
    const EXPIRES = 'Expires';
    const LAST_MODIFIED = 'Last-Modified';
    const LINK = 'Link';
    const LOCATION = 'Location';
    const P3P = 'P3P';
    const PRAGMA = 'Pragma';
    const PROXY_AUTHENTICATE = 'Proxy-Authenticate';
    const PUBLIC_KEY_PINS = 'Public-Key-Pins';
    const REFRESH = 'Refresh';
    const RETRY_AFTER = 'Retry-After';
    const SERVER = 'Server';
    const SET_COOKIE = 'Set-Cookie';
    const STATUS = 'Status';
    const TRAILER = 'Trailer';
    const TRANSFER_ENCODING = 'Transfer-Encoding';
    const UPGRADE = 'Upgrade';
    const VARY = 'Vary';
    const VIA = 'Via';
    const WARNING = 'Warning';
    const WWW_AUTHENTICATE = 'WWW-Authenticate';
}
