<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2020, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use fkooman\OAuth\Client\Http\CurlHttpClient;
use LC\Web\Config;
use LC\Web\ProviderListFetcher;

try {
    $config = new Config(require $baseDir.'/config/config.php');
    $discoveryUrlList = [
        'https://static.eduvpn.nl/disco/secure_internet.json',
        'https://static.eduvpn.nl/disco/institute_access.json',
        'https://disco.eduvpn.org/organization_list_2.json',
    ];
    $providerListFetcher = new ProviderListFetcher($baseDir.'/data');
    foreach ($discoveryUrlList as $discoveryUrl) {
        $providerListFetcher->update(new CurlHttpClient(), $discoveryUrl);
    }
} catch (Exception $e) {
    echo sprintf('ERROR: %s', $e->getMessage()).PHP_EOL;
    exit(1);
}
