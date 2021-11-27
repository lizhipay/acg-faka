<?php
/*
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements.  See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership.  The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License.  You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 */
namespace Mrgoon\AliyunSmsSdk\Http;

class HttpHelper
{
	public static $connectTimeout = 30;//30 second
	public static $readTimeout = 80;//80 second
	
	public static function curl($url, $httpMethod = "GET", $postFields = null,$headers = null)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod); 
		if(ENABLE_HTTP_PROXY) {
			curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC); 
			curl_setopt($ch, CURLOPT_PROXY, HTTP_PROXY_IP); 
			curl_setopt($ch, CURLOPT_PROXYPORT, HTTP_PROXY_PORT);
			curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP); 
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FAILONERROR, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postFields) ? self::getPostHttpBody($postFields) : $postFields);
		
		if (self::$readTimeout) {
			curl_setopt($ch, CURLOPT_TIMEOUT, self::$readTimeout);
		}
		if (self::$connectTimeout) {
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$connectTimeout);
		}
		//https request
		if(strlen($url) > 5 && strtolower(substr($url,0,5)) == "https" ) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		}
		if (is_array($headers) && 0 < count($headers))
		{
			$httpHeaders =self::getHttpHearders($headers);
			curl_setopt($ch,CURLOPT_HTTPHEADER,$httpHeaders);
		}
		$httpResponse = new HttpResponse();
		$httpResponse->setBody(curl_exec($ch));
		$httpResponse->setStatus(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		if (curl_errno($ch))
		{
			throw new ClientException("Server unreachable: Errno: " . curl_errno($ch) . " " . curl_error($ch), "SDK.ServerUnreachable");
		}
		curl_close($ch);
		return $httpResponse;
	}
	static function getPostHttpBody($postFildes){		
		$content = "";
		foreach ($postFildes as $apiParamKey => $apiParamValue)
		{			
			$content .= "$apiParamKey=" . urlencode($apiParamValue) . "&";
		}
		return substr($content, 0, -1);
	}
	static function getHttpHearders($headers)
	{
		$httpHeader = array();
		foreach ($headers as $key => $value)
		{
			array_push($httpHeader, $key.":".$value);	
		}
		return $httpHeader;
	}
}
