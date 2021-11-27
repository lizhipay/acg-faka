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
namespace Mrgoon\Dysmsapi\Request\V20170525;

use Mrgoon\AliyunSmsSdk\RpcAcsRequest;

class SendSmsRequest extends RpcAcsRequest
{
	function  __construct()
	{
		parent::__construct("Dysmsapi", "2017-05-25", "SendSms");
	}

	private  $outId;

	private  $signName;

	private  $ownerId;

	private  $resourceOwnerId;

	private  $templateCode;

	private  $phoneNumbers;

	private  $resourceOwnerAccount;

	private  $templateParam;

	public function getOutId() {
		return $this->outId;
	}

	public function setOutId($outId) {
		$this->outId = $outId;
		$this->queryParameters["OutId"]=$outId;
	}

	public function getSignName() {
		return $this->signName;
	}

	public function setSignName($signName) {
		$this->signName = $signName;
		$this->queryParameters["SignName"]=$signName;
	}

	public function getOwnerId() {
		return $this->ownerId;
	}

	public function setOwnerId($ownerId) {
		$this->ownerId = $ownerId;
		$this->queryParameters["OwnerId"]=$ownerId;
	}

	public function getResourceOwnerId() {
		return $this->resourceOwnerId;
	}

	public function setResourceOwnerId($resourceOwnerId) {
		$this->resourceOwnerId = $resourceOwnerId;
		$this->queryParameters["ResourceOwnerId"]=$resourceOwnerId;
	}

	public function getTemplateCode() {
		return $this->templateCode;
	}

	public function setTemplateCode($templateCode) {
		$this->templateCode = $templateCode;
		$this->queryParameters["TemplateCode"]=$templateCode;
	}

	public function getPhoneNumbers() {
		return $this->phoneNumbers;
	}

	public function setPhoneNumbers($phoneNumbers) {
		$this->phoneNumbers = $phoneNumbers;
		$this->queryParameters["PhoneNumbers"]=$phoneNumbers;
	}

	public function getResourceOwnerAccount() {
		return $this->resourceOwnerAccount;
	}

	public function setResourceOwnerAccount($resourceOwnerAccount) {
		$this->resourceOwnerAccount = $resourceOwnerAccount;
		$this->queryParameters["ResourceOwnerAccount"]=$resourceOwnerAccount;
	}

	public function getTemplateParam() {
		return $this->templateParam;
	}

	public function setTemplateParam($templateParam) {
		$this->templateParam = $templateParam;
		$this->queryParameters["TemplateParam"]=$templateParam;
	}
	
}