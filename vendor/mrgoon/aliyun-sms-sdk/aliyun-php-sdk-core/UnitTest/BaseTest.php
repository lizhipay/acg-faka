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
class BaseTest extends PHPUnit_Framework_TestCase
{
	public $client = null;
	function setUp()
	{
		$path = substr(dirname(__FILE__), 0,strripos(dirname(__FILE__),DIRECTORY_SEPARATOR)).DIRECTORY_SEPARATOR;
		include_once $path.'Config.php';
		include_once 'Ecs/Rquest/DescribeRegionsRequest.php';
		include_once 'BatchCompute/ListImagesRequest.php';

		$iClientProfile = DefaultProfile::getProfile("cn-hangzhou", "AccessKey", "AccessSecret");
		$this->client = new DefaultAcsClient($iClientProfile);
	}
	
	function getProperty($propertyKey)
	{
		$accessKey = "";
		$accessSecret = "";
		$iClientProfile = DefaultProfile::getProfile("cn-hangzhou", "AccessKey", "AccessSecret");
		
	
	}
	
}