<?php

namespace Yurun\Util\YurunHttp;

abstract class FormDataBuilder
{
    /**
     * 构建form-data body内容.
     *
     * @param mixed                                          $body
     * @param \Yurun\Util\YurunHttp\Http\Psr7\UploadedFile[] $files
     * @param string                                         $boundary
     *
     * @return string
     */
    public static function build($body, $files, &$boundary)
    {
        $result = '';
        if (!\is_array($body))
        {
            parse_str($body, $body);
        }
        $boundary = Random::letter(8, 16);
        foreach ($body as $k => $v)
        {
            $result .= sprintf("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n", $boundary, $k, $v);
        }
        foreach ($files as $name => $file)
        {
            $result .= sprintf("--%s\r\nContent-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n", $boundary, $name, basename($file->getClientFilename()), $file->getClientMediaType()) . $file->getStream()->getContents() . "\r\n";
        }
        $result .= sprintf("--%s--\r\n", $boundary);

        return $result;
    }
}
