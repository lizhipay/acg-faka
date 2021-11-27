<?php

namespace Yurun\Util\YurunHttp\Co;

use Yurun\Util\HttpRequest;
use Yurun\Util\YurunHttp;
use Yurun\Util\YurunHttp\Attributes;

abstract class Batch
{
    /**
     * 批量运行并发请求
     *
     * @param \Yurun\Util\YurunHttp\Http\Request[]|\Yurun\Util\HttpRequest[] $requests
     * @param float|null                                                     $timeout      超时时间，单位：秒。默认为 null 不限制
     * @param string|null                                                    $handlerClass
     *
     * @return \Yurun\Util\YurunHttp\Http\Response[]
     */
    public static function run($requests, $timeout = null, $handlerClass = null)
    {
        $batchRequests = [];
        $downloadAutoExt = [];
        foreach ($requests as $i => $request)
        {
            if ($request instanceof HttpRequest)
            {
                $savePath = $request->getSavePath();
                if (null !== $savePath && HttpRequest::checkDownloadIsAutoExt($savePath, $savePath))
                {
                    $request->saveFileOption['filePath'] = $savePath;
                    $downloadAutoExt[] = $i;
                }
                $batchRequests[$i] = $request->buildRequest();
            }
            elseif (!$request instanceof \Yurun\Util\YurunHttp\Http\Request)
            {
                throw new \InvalidArgumentException('Request must be instance of \Yurun\Util\YurunHttp\Http\Request or \Yurun\Util\HttpRequest');
            }
        }
        if (null === $handlerClass)
        {
            $handler = YurunHttp::getHandler();
        }
        else
        {
            $handler = new $handlerClass();
        }
        /** @var \Yurun\Util\YurunHttp\Handler\IHandler $handler */
        $result = $handler->coBatch($batchRequests, $timeout);
        foreach ($downloadAutoExt as $i)
        {
            if (isset($result[$i]))
            {
                $response = &$result[$i];
            }
            else
            {
                $response = null;
            }
            if ($response)
            {
                HttpRequest::parseDownloadAutoExt($response, $response->getRequest()->getAttribute(Attributes::SAVE_FILE_PATH));
            }
            unset($response);
        }

        return $result;
    }
}
