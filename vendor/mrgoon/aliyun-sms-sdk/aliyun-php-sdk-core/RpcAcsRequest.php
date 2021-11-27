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
namespace Mrgoon\AliyunSmsSdk;
abstract class RpcAcsRequest extends AcsRequest
{
	private $dateTimeFormat = 'Y-m-d\TH:i:s\Z'; 
	private $domainParameters = array();
	
	function  __construct($product, $version, $actionName)
	{
		parent::__construct($product, $version, $actionName);
		$this->initialize();
	}
	
	private function initialize()
	{
		$this->setMethod("GET");	
		$this->setAcceptFormat("JSON");
	}
	

    private function prepareValue($value)
    {
        if (is_bool($value)) {
            if ($value) {
                return "true";
            } else {
                return "false";
            }
        } else {
            return $value;
        }
    }

	public function composeUrl($iSigner, $credential, $domain)
	{
		$apiParams = parent::getQueryParameters();
        foreach ($apiParams as $key => $value) {
            $apiParams[$key] = $this->prepareValue($value);
        }
		$apiParams["RegionId"] = $this->getRegionId();
		$apiParams["AccessKeyId"] = $credential->getAccessKeyId();
		$apiParams["Format"] = $this->getAcceptFormat();
		$apiParams["SignatureMethod"] = $iSigner->getSignatureMethod();
		$apiParams["SignatureVersion"] = $iSigner->getSignatureVersion();
		$apiParams["SignatureNonce"] = uniqid();
		date_default_timezone_set("GMT");
		$apiParams["Timestamp"] = date($this->dateTimeFormat);
		$apiParams["Action"] = $this->getActionName();
		$apiParams["Version"] = $this->getVersion();
		$apiParams["Signature"] = $this->computeSignature($apiParams, $credential->getAccessSecret(), $iSigner);
		if(parent::getMethod() == "POST") {
			
			$requestUrl = $this->getProtocol()."://". $domain . "/";			
			foreach ($apiParams as $apiParamKey => $apiParamValue)
			{
				$this->putDomainParameters($apiParamKey,$apiParamValue);
			}
			return $requestUrl;			
		}
		else {	
			$requestUrl = $this->getProtocol()."://". $domain . "/?";

			foreach ($apiParams as $apiParamKey => $apiParamValue)
			{
				$requestUrl .= "$apiParamKey=" . urlencode($apiParamValue) . "&";
			}
			return substr($requestUrl, 0, -1);
		}
	}
	
	private function computeSignature($parameters, $accessKeySecret, $iSigner)
	{
	    ksort($parameters);
	    $canonicalizedQueryString = '';
	    foreach($parameters as $key => $value)
	    {
			$canonicalizedQueryString .= '&' . $this->percentEncode($key). '=' . $this->percentEncode($value);
	    }	
	    $stringToSign = parent::getMethod().'&%2F&' . $this->percentencode(substr($canonicalizedQueryString, 1));
	    $signature = $iSigner->signString($stringToSign, $accessKeySecret."&");

	    return $signature;
	}
	
	protected function percentEncode($str)
	{
	    $res = urlencode($str);
	    $res = preg_replace('/\+/', '%20', $res);
	    $res = preg_replace('/\*/', '%2A', $res);
	    $res = preg_replace('/%7E/', '~', $res);
	    return $res;
	}
	
	public function getDomainParameter()	
	{
		return $this->domainParameters;
	}
	
	public function putDomainParameters($name, $value)
	{
		$this->domainParameters[$name] = $value;
	}
	
}
