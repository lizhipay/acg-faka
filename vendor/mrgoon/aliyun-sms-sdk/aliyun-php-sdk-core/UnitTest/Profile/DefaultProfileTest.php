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
include_once '../../Config.php';
class DefaultProfileTest extends PHPUnit_Framework_TestCase
{
	public function testGetProfile()
	{
		$profile = DefaultProfile::getProfile("cn-hangzhou", "accessId", "accessSecret");
		$this->assertEquals("cn-hangzhou",$profile->getRegionId());
		$this->assertEquals("accessId",$profile->getCredential()->getAccessKeyId());
		$this->assertEquals("accessSecret",$profile->getCredential()->getAccessSecret());
	}
	
	public function testAddEndpoint()
	{
		$profile = DefaultProfile::getProfile("cn-hangzhou", "accessId", "accessSecret");
		$profile->addEndpoint("cn-hangzhou", "cn-hangzhou", "TestProduct", "testproduct.aliyuncs.com");
		$endpoints = $profile->getEndpoints();
		foreach ($endpoints as $key => $endpoint)
		{
			if("cn-hangzhou" == $endpoint->getName())
			{
				$regionIds = $endpoint->getRegionIds();
				$this->assertContains("cn-hangzhou",$regionIds);
				
				$productDomains= $endpoint->getProductDomains();
				$this->assertNotNull($productDomains);
				$productDomain = $this->getProductDomain($productDomains);
				$this->assertNotNull($productDomain);
				$this->assertEquals("TestProduct",$productDomain->getProductName());
				$this->assertEquals("testproduct.aliyuncs.com",$productDomain->getDomainName());
			}
		}
	}
	
	private function getProductDomain($productDomains)
	{
		foreach ($productDomains as $productDomain)
		{
			if($productDomain->getProductName() == "TestProduct")
			{
				return $productDomain;
			}
		}
		return null;
	}
		
}