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
/**
 * get end point data from xml
 * get place info
 */
include_once SMS_PATH .'Regions/EndpointConfig.php';

/**
 * hard code without `env` function
 */
if (!defined('ENABLE_HTTP_PROXY')) {
    define('ENABLE_HTTP_PROXY', false);
}

if (!defined('HTTP_PROXY_IP')) {
    define('HTTP_PROXY_IP', '127.0.0.1');
}

if (!defined('HTTP_PROXY_PORT')) {
    define('HTTP_PROXY_PORT', '8888');
}

