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
namespace Mrgoon\AliyunSmsSdk\Auth;

class Credential
{
	private  $dateTimeFormat = 'Y-m-d\TH:i:s\Z'; 
	private	 $refreshDate;
	private  $expiredDate;
	private  $accessKeyId;
	private  $accessSecret;
	private  $securityToken;
	
	function  __construct($accessKeyId, $accessSecret)
	{
	    $this->accessKeyId = $accessKeyId;
	    $this->accessSecret = $accessSecret;
	    $this->refreshDate = date($this->dateTimeFormat);
	}
	
	public function isExpired()
	{
		if($this->expiredDate == null)
		{
			return false;
		}
		if(strtotime($this->expiredDate)>date($this->dateTimeFormat))
		{
			return false;
		}
		return true;
	}
	
	public function getRefreshDate()
	{
		return $this->refreshDate;
	}
	
	public function getExpiredDate()
	{
		return $this->expiredDate;
	}
	
	public function setExpiredDate($expiredHours)
	{
		if($expiredHours>0)
		{
			return $this->expiredDate = date($this->dateTimeFormat, strtotime("+".$expiredHours." hour"));
		}
	}
	
	public function getAccessKeyId()
	{
		return $this->accessKeyId;
	}
	
	public function setAccessKeyId($accessKeyId)
	{
		$this->accessKeyId = $accessKeyId;
	}
	
	public function getAccessSecret()
	{
		return $this->accessSecret;
	}
	
	public function setAccessSecret($accessSecret)
	{
		$this->accessSecret = $accessSecret;
	}

}