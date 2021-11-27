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

abstract class AcsRequest
{
	protected  $version;
	protected  $product;
	protected  $actionName;
	protected  $regionId;
	protected  $acceptFormat;
	protected  $method;
	protected  $protocolType = "http";
	protected  $content;
	
	protected $queryParameters = array();
	protected $headers = array();
	
	function  __construct($product, $version, $actionName)
	{
	    $this->headers["x-sdk-client"] = "php/2.0.0";
	    $this->product = $product;
	    $this->version = $version;
	    $this->actionName = $actionName;
	}
	
	public abstract function composeUrl($iSigner, $credential, $domain);
	
	public function getVersion()
	{
		return $this->version;
	}
	
	public function setVersion($version)
	{
		$this->version = $version;
	}
	
	public function getProduct()
	{
		return $this->product;
	}
	
	public function setProduct($product)
	{
		$this->product = $product;
	}
	
	public function getActionName()
	{
		return $this->actionName;
	}
	
	public function setActionName($actionName)
	{
		$this->actionName = $actionName;
	}
	
	public function getAcceptFormat()
	{
		return	$this->acceptFormat;
	}
	
	public function setAcceptFormat($acceptFormat)
	{
		$this->acceptFormat = $acceptFormat;
	}
	
	public function getQueryParameters()
	{
		return $this->queryParameters;
	}
	
	public function getHeaders()
	{
		return $this->headers;
	}
	
	public function getMethod()
	{
		return $this->method;
	}
	
	public function setMethod($method)
	{
		$this->method = $method;
	}
	
	public function getProtocol()
	{
		return $this->protocolType;
	}
	
	public function setProtocol($protocol)
	{
		$this->protocolType = $protocol;
	}
	
	public function getRegionId()
	{
		return $this->regionId;
	}
	public function setRegionId($region)
	{
		$this->regionId = $region;
	}
	
	public function getContent()
    {
        return $this->content;
    }

    public function setContent($content)
    {
        $this->content = $content;
    } 
        
        
    public function addHeader($headerKey, $headerValue)
    {
        $this->headers[$headerKey] = $headerValue;
    } 
	
		
}