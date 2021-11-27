<?php

namespace Yurun\Util\YurunHttp\Http\Psr7;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yurun\Util\YurunHttp\Stream\FileStream;

class UploadedFile implements UploadedFileInterface
{
    /**
     * 文件在客户端时的文件名.
     *
     * @var string
     */
    protected $fileName;

    /**
     * 文件mime类型.
     *
     * @var string
     */
    protected $mediaType;

    /**
     * 临时文件名.
     *
     * @var string
     */
    protected $tmpFileName;

    /**
     * 文件大小，单位：字节
     *
     * @var int
     */
    protected $size;

    /**
     * 错误码
     *
     * @var int
     */
    protected $error;

    /**
     * 文件流
     *
     * @var \Yurun\Util\YurunHttp\Stream\FileStream
     */
    protected $stream;

    /**
     * 文件是否被移动过.
     *
     * @var bool
     */
    protected $isMoved = false;

    /**
     * @param string $fileName
     * @param string $mediaType
     * @param string $tmpFileName
     * @param int    $size
     * @param int    $error
     */
    public function __construct($fileName, $mediaType, $tmpFileName, $size = null, $error = 0)
    {
        $this->fileName = $fileName;
        $this->mediaType = $mediaType;
        $this->tmpFileName = $tmpFileName;
        if (null === $size)
        {
            $this->size = filesize($tmpFileName);
        }
        else
        {
            $this->size = $size;
        }
        $this->error = $error;
    }

    /**
     * Retrieve a stream representing the uploaded file.
     *
     * This method MUST return a StreamInterface instance, representing the
     * uploaded file. The purpose of this method is to allow utilizing native PHP
     * stream functionality to manipulate the file upload, such as
     * stream_copy_to_stream() (though the result will need to be decorated in a
     * native PHP stream wrapper to work with such functions).
     *
     * If the moveTo() method has been called previously, this method MUST raise
     * an exception.
     *
     * @return StreamInterface stream representation of the uploaded file
     *
     * @throws \RuntimeException in cases when no stream is available or can be
     *                           created
     */
    public function getStream()
    {
        if (null === $this->stream)
        {
            $this->stream = new FileStream($this->tmpFileName);
        }

        return $this->stream;
    }

    /**
     * Move the uploaded file to a new location.
     *
     * Use this method as an alternative to move_uploaded_file(). This method is
     * guaranteed to work in both SAPI and non-SAPI environments.
     * Implementations must determine which environment they are in, and use the
     * appropriate method (move_uploaded_file(), rename(), or a stream
     * operation) to perform the operation.
     *
     * $targetPath may be an absolute path, or a relative path. If it is a
     * relative path, resolution should be the same as used by PHP's rename()
     * function.
     *
     * The original file or stream MUST be removed on completion.
     *
     * If this method is called more than once, any subsequent calls MUST raise
     * an exception.
     *
     * When used in an SAPI environment where $_FILES is populated, when writing
     * files via moveTo(), is_uploaded_file() and move_uploaded_file() SHOULD be
     * used to ensure permissions and upload status are verified correctly.
     *
     * If you wish to move to a stream, use getStream(), as SAPI operations
     * cannot guarantee writing to stream destinations.
     *
     * @see http://php.net/is_uploaded_file
     * @see http://php.net/move_uploaded_file
     *
     * @param string $targetPath path to which to move the uploaded file
     *
     * @return void
     *
     * @throws \InvalidArgumentException if the $path specified is invalid
     * @throws \RuntimeException         on any error during the move operation, or on
     *                                   the second or subsequent call to the method
     */
    public function moveTo($targetPath)
    {
        if (!\is_string($targetPath))
        {
            throw new \InvalidArgumentException('targetPath specified is invalid');
        }
        if ($this->isMoved)
        {
            throw new \RuntimeException('file can not be moved');
        }
        if (is_uploaded_file($this->tmpFileName))
        {
            $this->isMoved = move_uploaded_file($this->tmpFileName, $targetPath);
        }
        else
        {
            $this->isMoved = rename($this->tmpFileName, $targetPath);
        }
        if (!$this->isMoved)
        {
            throw new \RuntimeException(sprintf('file %s move to %s fail', $this->tmpFileName, $targetPath));
        }
    }

    /**
     * Retrieve the file size.
     *
     * Implementations SHOULD return the value stored in the "size" key of
     * the file in the $_FILES array if available, as PHP calculates this based
     * on the actual size transmitted.
     *
     * @return int|null the file size in bytes or null if unknown
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * Retrieve the error associated with the uploaded file.
     *
     * The return value MUST be one of PHP's UPLOAD_ERR_XXX constants.
     *
     * If the file was uploaded successfully, this method MUST return
     * UPLOAD_ERR_OK.
     *
     * Implementations SHOULD return the value stored in the "error" key of
     * the file in the $_FILES array.
     *
     * @see http://php.net/manual/en/features.file-upload.errors.php
     *
     * @return int one of PHP's UPLOAD_ERR_XXX constants
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Retrieve the filename sent by the client.
     *
     * Do not trust the value returned by this method. A client could send
     * a malicious filename with the intention to corrupt or hack your
     * application.
     *
     * Implementations SHOULD return the value stored in the "name" key of
     * the file in the $_FILES array.
     *
     * @return string|null the filename sent by the client or null if none
     *                     was provided
     */
    public function getClientFilename()
    {
        return $this->fileName;
    }

    /**
     * Retrieve the media type sent by the client.
     *
     * Do not trust the value returned by this method. A client could send
     * a malicious media type with the intention to corrupt or hack your
     * application.
     *
     * Implementations SHOULD return the value stored in the "type" key of
     * the file in the $_FILES array.
     *
     * @return string|null the media type sent by the client or null if none
     *                     was provided
     */
    public function getClientMediaType()
    {
        return $this->mediaType;
    }

    /**
     * 获取上传文件的临时文件路径.
     *
     * @return string
     */
    public function getTempFileName()
    {
        return $this->tmpFileName;
    }
}
