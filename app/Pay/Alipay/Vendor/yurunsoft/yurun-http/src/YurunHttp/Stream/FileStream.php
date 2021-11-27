<?php

namespace Yurun\Util\YurunHttp\Stream;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Yurun\Util\YurunHttp\Http\Psr7\Uri;

class FileStream implements StreamInterface
{
    /**
     * 文件Uri.
     *
     * @var UriInterface
     */
    protected $uri;

    /**
     * 流对象
     *
     * @var resource|null
     */
    protected $stream;

    /**
     * 流访问类型.
     *
     * @var string
     */
    protected $mode;

    /**
     * @param string|UriInterface $uri
     * @param string              $mode
     */
    public function __construct($uri, $mode = StreamMode::READ_WRITE)
    {
        if (\is_string($uri))
        {
            $this->uri = $uri = new Uri($uri);
        }
        elseif ($uri instanceof UriInterface)
        {
            $this->uri = $uri;
        }
        else
        {
            $uri = $this->uri;
        }
        $this->mode = $mode;
        $stream = fopen($uri, $mode);
        if (false === $stream)
        {
            throw new \RuntimeException(sprintf('Open stream %s error', (string) $uri));
        }
        $this->stream = $stream;
    }

    public function __destruct()
    {
        if ($this->stream)
        {
            $this->close();
        }
    }

    /**
     * Reads all data from the stream into a string, from the beginning to end.
     *
     * This method MUST attempt to seek to the beginning of the stream before
     * reading data and read the stream until the end is reached.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * This method MUST NOT raise an exception in order to conform with PHP's
     * string casting operations.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     *
     * @return string
     */
    public function __toString()
    {
        try
        {
            $this->rewind();

            return stream_get_contents($this->stream);
        }
        catch (\Throwable $ex)
        {
            return '';
        }
    }

    /**
     * Closes the stream and any underlying resources.
     *
     * @return void
     */
    public function close()
    {
        fclose($this->stream);
        $this->stream = null;
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach()
    {
        $stream = $this->stream;
        $this->stream = null;

        return $stream;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null returns the size in bytes if known, or null if unknown
     */
    public function getSize()
    {
        $stat = fstat($this->stream);
        if (false === $stat)
        {
            throw new \RuntimeException('get stream size error');
        }

        return $stat['size'];
    }

    /**
     * Returns the current position of the file read/write pointer.
     *
     * @return int Position of the file pointer
     *
     * @throws \RuntimeException on error
     */
    public function tell()
    {
        $result = ftell($this->stream);
        if (false === $result)
        {
            throw new \RuntimeException('stream tell error');
        }

        return $result;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof()
    {
        return feof($this->stream);
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable()
    {
        return (bool) $this->getMetadata('seekable');
    }

    /**
     * Seek to a position in the stream.
     *
     * @see http://www.php.net/manual/en/function.fseek.php
     *
     * @param int $offset Stream offset
     * @param int $whence Specifies how the cursor position will be calculated
     *                    based on the seek offset. Valid values are identical to the built-in
     *                    PHP $whence values for `fseek()`.  SEEK_SET: Set position equal to
     *                    offset bytes SEEK_CUR: Set position to current location plus offset
     *                    SEEK_END: Set position to end-of-stream plus offset.
     *
     * @return void
     *
     * @throws \RuntimeException on failure
     */
    public function seek($offset, $whence = \SEEK_SET)
    {
        if (-1 === fseek($this->stream, $offset, $whence))
        {
            throw new \RuntimeException('seek stream error');
        }
    }

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @see seek()
     * @see http://www.php.net/manual/en/function.fseek.php
     *
     * @return void
     *
     * @throws \RuntimeException on failure
     */
    public function rewind()
    {
        if (!rewind($this->stream))
        {
            throw new \RuntimeException('rewind stream failed');
        }
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable()
    {
        return \in_array($this->mode, [
            StreamMode::WRITE_CLEAN,
            StreamMode::WRITE_END,
            StreamMode::CREATE_READ_WRITE,
            StreamMode::CREATE_WRITE,
            StreamMode::READ_WRITE,
            StreamMode::READ_WRITE_CLEAN,
            StreamMode::READ_WRITE_END,
        ]);
    }

    /**
     * Write data to the stream.
     *
     * @param string $string the string that is to be written
     *
     * @return int returns the number of bytes written to the stream
     *
     * @throws \RuntimeException on failure
     */
    public function write($string)
    {
        $result = fwrite($this->stream, $string);
        if (false === $result)
        {
            throw new \RuntimeException('write stream failed');
        }

        return $result;
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable()
    {
        return \in_array($this->mode, [
            StreamMode::READ_WRITE,
            StreamMode::READ_WRITE_CLEAN,
            StreamMode::READ_WRITE_END,
            StreamMode::READONLY,
            StreamMode::CREATE_READ_WRITE,
        ]);
    }

    /**
     * Read data from the stream.
     *
     * @param int $length Read up to $length bytes from the object and return
     *                    them. Fewer than $length bytes may be returned if underlying stream
     *                    call returns fewer bytes.
     *
     * @return string returns the data read from the stream, or an empty string
     *                if no bytes are available
     *
     * @throws \RuntimeException if an error occurs
     */
    public function read($length)
    {
        $result = fread($this->stream, $length);
        if (false === $result)
        {
            throw new \RuntimeException('read stream error');
        }

        return $result;
    }

    /**
     * Returns the remaining contents in a string.
     *
     * @return string
     *
     * @throws \RuntimeException if unable to read or an error occurs while
     *                           reading
     */
    public function getContents()
    {
        $result = stream_get_contents($this->stream);
        if (false === $result)
        {
            throw new \RuntimeException('stream getContents error');
        }

        return $result;
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @see http://php.net/manual/en/function.stream-get-meta-data.php
     *
     * @param string $key specific metadata to retrieve
     *
     * @return array|mixed|null Returns an associative array if no key is
     *                          provided. Returns a specific key value if a key is provided and the
     *                          value is found, or null if the key is not found.
     */
    public function getMetadata($key = null)
    {
        $result = stream_get_meta_data($this->stream);
        /* @phpstan-ignore-next-line */
        if (!$result)
        {
            throw new \RuntimeException('stream getMetadata error');
        }
        if (null === $key)
        {
            return $result;
        }
        elseif (isset($result[$key]))
        {
            return $result[$key];
        }
        else
        {
            return null;
        }
    }

    /**
     * Get Uri.
     *
     * @return UriInterface
     */
    public function getUri()
    {
        return $this->uri;
    }
}
