<?php

namespace Yurun\Util\YurunHttp\Http\Psr7;

use Psr\Http\Message\ResponseInterface;
use Yurun\Util\YurunHttp\Http\Psr7\Consts\StatusCode;

class Response extends AbstractMessage implements ResponseInterface
{
    /**
     * 状态码
     *
     * @var int
     */
    protected $statusCode;

    /**
     * 状态码原因短语.
     *
     * @var string
     */
    protected $reasonPhrase;

    /**
     * @param string|\Psr\Http\Message\StreamInterface $body
     * @param int                                      $statusCode
     * @param string|null                              $reasonPhrase
     */
    public function __construct($body = '', $statusCode = StatusCode::OK, $reasonPhrase = null)
    {
        parent::__construct($body);
        $this->statusCode = $statusCode;
        $this->reasonPhrase = null === $reasonPhrase ? StatusCode::getReasonPhrase($this->statusCode) : $reasonPhrase;
    }

    /**
     * Gets the response status code.
     *
     * The status code is a 3-digit integer result code of the server's attempt
     * to understand and satisfy the request.
     *
     * @return int status code
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * Return an instance with the specified status code and, optionally, reason phrase.
     *
     * If no reason phrase is specified, implementations MAY choose to default
     * to the RFC 7231 or IANA recommended reason phrase for the response's
     * status code.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated status and reason phrase.
     *
     * @see http://tools.ietf.org/html/rfc7231#section-6
     * @see http://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     *
     * @param int    $code         the 3-digit integer result code to set
     * @param string $reasonPhrase the reason phrase to use with the
     *                             provided status code; if none is provided, implementations MAY
     *                             use the defaults as suggested in the HTTP specification
     *
     * @return static
     *
     * @throws \InvalidArgumentException for invalid status code arguments
     */
    public function withStatus($code, $reasonPhrase = '')
    {
        $self = clone $this;
        $self->statusCode = $code;
        if ('' === $reasonPhrase)
        {
            $self->reasonPhrase = StatusCode::getReasonPhrase($code);
        }
        else
        {
            $self->reasonPhrase = $reasonPhrase;
        }

        return $self;
    }

    /**
     * Gets the response reason phrase associated with the status code.
     *
     * Because a reason phrase is not a required element in a response
     * status line, the reason phrase value MAY be null. Implementations MAY
     * choose to return the default RFC 7231 recommended reason phrase (or those
     * listed in the IANA HTTP Status Code Registry) for the response's
     * status code.
     *
     * @see http://tools.ietf.org/html/rfc7231#section-6
     * @see http://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     *
     * @return string reason phrase; must return an empty string if none present
     */
    public function getReasonPhrase()
    {
        return $this->reasonPhrase;
    }
}
