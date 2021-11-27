<?php

namespace Yurun\Util\YurunHttp\Http\Psr7;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Yurun\Util\YurunHttp\Http\Psr7\Consts\RequestMethod;

class Request extends AbstractMessage implements RequestInterface
{
    /**
     * 请求地址
     *
     * @var UriInterface
     */
    protected $uri;

    /**
     * 请求目标.
     *
     * @var mixed
     */
    protected $requestTarget;

    /**
     * 请求方法.
     *
     * @var string
     */
    protected $method;

    /**
     * 构造方法.
     *
     * @param string|UriInterface|null $uri
     * @param array                    $headers
     * @param string                   $body
     * @param string                   $method
     * @param string                   $version
     */
    public function __construct($uri = null, array $headers = [], $body = '', $method = RequestMethod::GET, $version = '1.1')
    {
        parent::__construct($body);
        if (!$uri instanceof UriInterface)
        {
            $this->uri = new Uri($uri);
        }
        elseif (null !== $uri)
        {
            $this->uri = $uri;
        }
        $this->setHeaders($headers);
        $this->method = strtoupper($method);
        $this->protocolVersion = $version;
    }

    /**
     * Retrieves the message's request target.
     *
     * Retrieves the message's request-target either as it will appear (for
     * clients), as it appeared at request (for servers), or as it was
     * specified for the instance (see withRequestTarget()).
     *
     * In most cases, this will be the origin-form of the composed URI,
     * unless a value was provided to the concrete implementation (see
     * withRequestTarget() below).
     *
     * If no URI is available, and no request-target has been specifically
     * provided, this method MUST return the string "/".
     *
     * @return string
     */
    public function getRequestTarget()
    {
        return null === $this->requestTarget ? (string) $this->uri : $this->requestTarget;
    }

    /**
     * Return an instance with the specific request-target.
     *
     * If the request needs a non-origin-form request-target — e.g., for
     * specifying an absolute-form, authority-form, or asterisk-form —
     * this method may be used to create an instance with the specified
     * request-target, verbatim.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * changed request target.
     *
     * @see http://tools.ietf.org/html/rfc7230#section-2.7 (for the various
     *     request-target forms allowed in request messages)
     *
     * @param mixed $requestTarget
     *
     * @return static
     */
    public function withRequestTarget($requestTarget)
    {
        $self = clone $this;
        $self->requestTarget = $requestTarget;

        return $self;
    }

    /**
     * Retrieves the HTTP method of the request.
     *
     * @return string returns the request method
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * Return an instance with the provided HTTP method.
     *
     * While HTTP method names are typically all uppercase characters, HTTP
     * method names are case-sensitive and thus implementations SHOULD NOT
     * modify the given string.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * changed request method.
     *
     * @param string $method case-sensitive method
     *
     * @return static
     *
     * @throws \InvalidArgumentException for invalid HTTP methods
     */
    public function withMethod($method)
    {
        $self = clone $this;
        $self->method = $method;

        return $self;
    }

    /**
     * Retrieves the URI instance.
     *
     * This method MUST return a UriInterface instance.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-4.3
     *
     * @return UriInterface returns a UriInterface instance
     *                      representing the URI of the request
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Returns an instance with the provided URI.
     *
     * This method MUST update the Host header of the returned request by
     * default if the URI contains a host component. If the URI does not
     * contain a host component, any pre-existing Host header MUST be carried
     * over to the returned request.
     *
     * You can opt-in to preserving the original state of the Host header by
     * setting `$preserveHost` to `true`. When `$preserveHost` is set to
     * `true`, this method interacts with the Host header in the following ways:
     *
     * - If the the Host header is missing or empty, and the new URI contains
     *   a host component, this method MUST update the Host header in the returned
     *   request.
     * - If the Host header is missing or empty, and the new URI does not contain a
     *   host component, this method MUST NOT update the Host header in the returned
     *   request.
     * - If a Host header is present and non-empty, this method MUST NOT update
     *   the Host header in the returned request.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new UriInterface instance.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-4.3
     *
     * @param UriInterface $uri          new request URI to use
     * @param bool         $preserveHost preserve the original state of the Host header
     *
     * @return static
     */
    public function withUri(UriInterface $uri, $preserveHost = false)
    {
        $self = clone $this;
        $self->uri = $uri;
        if (!$preserveHost)
        {
            $self->headers = [];
            $self->headerNames = [];
        }

        return $self;
    }
}
