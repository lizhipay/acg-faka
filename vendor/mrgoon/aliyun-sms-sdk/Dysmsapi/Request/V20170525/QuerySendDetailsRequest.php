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

class QuerySendDetailsRequest extends RpcAcsRequest
{
	function  __construct()
	{
		parent::__construct("Dysmsapi", "2017-05-25", "QuerySendDetails");
	}

	private  $sendDate;

	private  $pageSize;

	private  $ownerId;

	private  $resourceOwnerId;

	private  $phoneNumber;

	private  $bizId;

	private  $currentPage;

	private  $resourceOwnerAccount;

	public function getSendDate() {
		return $this->sendDate;
	}

	public function setSendDate($sendDate) {
		$this->sendDate = $sendDate;
		$this->queryParameters["SendDate"]=$sendDate;
	}

	public function getPageSize() {
		return $this->pageSize;
	}

	public function setPageSize($pageSize) {
		$this->pageSize = $pageSize;
		$this->queryParameters["PageSize"]=$pageSize;
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

	public function getPhoneNumber() {
		return $this->phoneNumber;
	}

	public function setPhoneNumber($phoneNumber) {
		$this->phoneNumber = $phoneNumber;
		$this->queryParameters["PhoneNumber"]=$phoneNumber;
	}

	public function getBizId() {
		return $this->bizId;
	}

	public function setBizId($bizId) {
		$this->bizId = $bizId;
		$this->queryParameters["BizId"]=$bizId;
	}

	public function getCurrentPage() {
		return $this->currentPage;
	}

	public function setCurrentPage($currentPage) {
		$this->currentPage = $currentPage;
		$this->queryParameters["CurrentPage"]=$currentPage;
	}

	public function getResourceOwnerAccount() {
		return $this->resourceOwnerAccount;
	}

	public function setResourceOwnerAccount($resourceOwnerAccount) {
		$this->resourceOwnerAccount = $resourceOwnerAccount;
		$this->queryParameters["ResourceOwnerAccount"]=$resourceOwnerAccount;
	}
	
}