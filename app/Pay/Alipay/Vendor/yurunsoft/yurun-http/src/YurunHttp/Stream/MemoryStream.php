<?php

namespace Yurun\Util\YurunHttp\Stream;

use Psr\Http\Message\StreamInterface;

class MemoryStream implements StreamInterface
{
    /**
     * 内容.
     *
     * @var string
     */
    protected $content;

    /**
     * 大小.
     *
     * @var int
     */
    protected $size;

    /**
     * 当前位置.
     *
     * @var int
     */
    protected $position = 0;

    /**
     * @param string $content
     */
    public function __construct($content = '')
    {
        $this->content = $content;
        $this->size = \strlen($content);
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
        return $this->content;
    }

    /**
     * Closes the stream and any underlying resources.
     *
     * @return void
     */
    public function close()
    {
        $this->content = '';
        $this->size = -1;
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
        return null;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null returns the size in bytes if known, or null if unknown
     */
    public function getSize()
    {
        return $this->size;
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
        return $this->position;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof()
    {
        return $this->position > $this->size;
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable()
    {
        return true;
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
        switch ($whence)
        {
            case \SEEK_SET:
                if ($offset < 0)
                {
                    throw new \RuntimeException('offset failure');
                }
                $this->position = $offset;
                break;
            case \SEEK_CUR:
                $this->position += $offset;
                break;
            case \SEEK_END:
                $this->position = $this->size - 1 + $offset;
                break;
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
        $this->position = 0;
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable()
    {
        return true;
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
        $content = &$this->content;
        $position = &$this->position;
        $content = substr_replace($content, $string, $position, 0);
        $len = \strlen($string);
        $position += $len;
        $this->size += $len;

        return $len;
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable()
    {
        return true;
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
        $position = &$this->position;
        $result = substr($this->content, $position, $length);
        $position += $length;

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
        $position = &$this->position;
        if (0 === $position)
        {
            $position = $this->size;

            return $this->content;
        }
        else
        {
            return $this->read($this->size - $position);
        }
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
        return null;
    }
}
