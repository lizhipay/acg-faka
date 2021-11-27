<?php

namespace Mrgoon\AliyunSmsSdk\Regions;

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

$endpoint_filename = dirname(__FILE__) . DIRECTORY_SEPARATOR . "endpoints.xml";
$xml = simplexml_load_string(file_get_contents($endpoint_filename));
$json = json_encode($xml);
$json_array = json_decode($json, TRUE);

$endpoints = array();

foreach ($json_array["Endpoint"] as $json_endpoint) {
    # pre-process RegionId & Product
    if (!array_key_exists("RegionId", $json_endpoint["RegionIds"])) {
        $region_ids = array();
    } else {
        $json_region_ids = $json_endpoint['RegionIds']['RegionId'];
        if (!is_array($json_region_ids)) {
            $region_ids = array($json_region_ids);
        } else {
            $region_ids = $json_region_ids;
        }
    }

    if (!array_key_exists("Product", $json_endpoint["Products"])) {
        $products = array();

    } else {
        $json_products = $json_endpoint["Products"]["Product"];

        if (array() === $json_products or !is_array($json_products)) {
            $products = array();
        } else if (array_keys($json_products) !== range(0, count($json_products) - 1)) {
            # array is not sequential
            $products = array($json_products);
        } else {
            $products = $json_products;
        }
    }

    $product_domains = array();
    foreach ($products as $product) {
        $product_domain = new ProductDomain($product['ProductName'], $product['DomainName']);
        array_push($product_domains, $product_domain);
    }

    $endpoint = new Endpoint($region_ids[0], $region_ids, $product_domains);
    array_push($endpoints, $endpoint);
}

EndpointProvider::setEndpoints($endpoints);
