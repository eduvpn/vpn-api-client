<?php
/**
 *  Copyright (C) 2017 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SURFnet\VPN\ApiClient;

use fkooman\OAuth\Client\Http\HttpClientInterface;
use fkooman\OAuth\Client\Http\Request as HttpRequest;
use fkooman\OAuth\Client\OAuthClient;
use fkooman\OAuth\Client\Provider;
use fkooman\OAuth\Client\SessionTokenStorage;
use ParagonIE\ConstantTime\Base64;
use RuntimeException;
use SURFnet\VPN\ApiClient\Http\Exception\TokenException;
use SURFnet\VPN\ApiClient\Http\Request;
use SURFnet\VPN\ApiClient\Http\Response;

class Service
{
    /** @var TplInterface */
    private $tpl;

    /** @var \fkooman\OAuth\Client\Http\HttpClientInterface */
    private $httpClient;

    /** @var array */
    private $publicKeys;

    /** @var \fkooman\OAuth\Client\OAuthClient|null */
    private $oauthClient = null;

    public function __construct(TplInterface $tpl, HttpClientInterface $httpClient, array $publicKeys)
    {
        $this->tpl = $tpl;
        $this->httpClient = $httpClient;
        $this->publicKeys = $publicKeys;
    }

    public function run(Request $request)
    {
        $requestScope = 'config';
        $callbackUri = sprintf('%scallback.php', $request->getRootUri());
        $instanceId = null;

        try {
            switch ($request->getMethod()) {
                case 'GET':
                    if (null === $instanceId = $request->getQueryParameter('instance_id')) {
                        // no instance specified
                        return $this->getInstanceList();
                    }

                    if (null === $profileId = $request->getQueryParameter('profile_id')) {
                        // no profile specified
                        return $this->getProfileList($requestScope, $instanceId);
                    }

                    // instance & profile specified, show all API call outputs
                    return $this->getConfig($requestScope, $instanceId, $profileId);
                case 'POST':
                    if (null === $instanceId = $request->getQueryParameter('instance_id')) {
                        // no instance specified, we cannot generate a keypair
                        return new Response(
                            400,
                            [],
                            'no instance_id specified'
                        );
                    }

                    if (null === $displayName = $request->getPostParameter('display_name')) {
                        return new Response(
                            400,
                            [],
                            'no display_name specified'
                        );
                    }

                    return $this->getKeypair($requestScope, $instanceId, $displayName);
                default:
                    return new Response(405, ['Allow' => 'GET,POST']);
            }
        } catch (TokenException $e) {
            // no valid OAuth token available...
            $authorizeUri = $this->oauthClient->getAuthorizeUri(
                $requestScope,
                $callbackUri
            );

            return new Response(302, ['Location' => $authorizeUri]);
        }
    }

    private function getInstanceList()
    {
        $instancesUrl = 'https://static.eduvpn.nl/instances.json';
        $response = $this->httpClient->send(HttpRequest::get($instancesUrl));
        if (!$response->isOkay()) {
            throw new RuntimeException(sprintf('unable to fetch "%s"', $instancesUrl));
        }

        $instancesSignatureUrl = 'https://static.eduvpn.nl/instances.json.sig';
        $signatureResponse = $this->httpClient->send(HttpRequest::get($instancesSignatureUrl));
        if (!$signatureResponse->isOkay()) {
            throw new RuntimeException(sprintf('unable to fetch "%s"', $instancesSignatureUrl));
        }

        $this->verifySignature($response->getBody(), $signatureResponse->getBody());

        return new Response(
            200,
            [],
            $this->tpl->render('instances', $response->json())
        );
    }

    /**
     * @return Response|array
     */
    private function apiDisco($instanceId)
    {
        $infoUrl = sprintf('%s/info.json', $instanceId);
        $response = $this->httpClient->send(HttpRequest::get($infoUrl));
        if (!$response->isOkay()) {
            throw new RuntimeException(sprintf('unable to fetch "%s"', $infoUrl));
        }

        $apiInfo = $response->json()['api']['http://eduvpn.org/api#2'];

        // every endpoint has their own OAuth server, so we need to connect
        // to that one!
        $this->oauthClient = new OAuthClient(
            new SessionTokenStorage(),
            $this->httpClient
        );
        $this->oauthClient->addProvider(
            $instanceId,
            new Provider(
                'eduvpn-for-web',
                '03Y4q834psuY5ZmE',
                $apiInfo['authorization_endpoint'],
                $apiInfo['token_endpoint']
            )
        );

        $this->oauthClient->setUserId('session_user');

        return $apiInfo;
    }

    private function getKeypair($requestScope, $instanceId, $displayName)
    {
        $apiInfo = $this->apiDisco($instanceId);
        $apiBaseUri = $apiInfo['api_base_uri'];

        $createKeypair = $this->post(
            $requestScope,
            sprintf('%s/create_keypair', $apiInfo['api_base_uri']),
            [
                'display_name' => $displayName,
            ]
        )->json();

        return new Response(
            200,
            [],
            $this->tpl->render(
                'keypair',
                array_merge(
                    ['instance_id' => $instanceId],
                    ['create_keypair' => $createKeypair['create_keypair']['data']]
                )
            )
        );
    }

    private function getProfileList($requestScope, $instanceId)
    {
        $apiInfo = $this->apiDisco($instanceId);
        $apiBaseUri = $apiInfo['api_base_uri'];

        // instance specified, get user_info
        $userInfo = $this->get($requestScope, sprintf('%s/user_info', $apiBaseUri))->json();
        $profileList = $this->get($requestScope, sprintf('%s/profile_list', $apiBaseUri))->json();
        $systemMessages = $this->get($requestScope, sprintf('%s/system_messages', $apiBaseUri))->json();
        $userMessages = $this->get($requestScope, sprintf('%s/user_messages', $apiBaseUri))->json();

        return new Response(
            200,
            [],
            $this->tpl->render(
                'profiles',
                array_merge(
                    ['instance_id' => $instanceId],
                    ['user_info' => $userInfo['user_info']['data']],
                    ['profile_list' => $profileList['profile_list']['data']],
                    ['system_messages' => $systemMessages['system_messages']['data']],
                    ['user_messages' => $userMessages['user_messages']['data']]
                )
            )
        );
    }

    private function getConfig($requestScope, $instanceId, $profileId)
    {
        $apiInfo = $this->apiDisco($instanceId);
        $apiBaseUri = $apiInfo['api_base_uri'];

        $profileConfig = $this->get(
            $requestScope,
            sprintf('%s/profile_config?profile_id=%s', $apiBaseUri, $profileId)
        )->getBody();

        return new Response(
            200,
            [],
            $this->tpl->render(
                'config',
                array_merge(
                    ['instance_id' => $instanceId],
                    ['profile_id' => $profileId],
                    ['profile_config' => $profileConfig]
                )
            )
        );
    }

    private function get($requestScope, $requestUri)
    {
        if (false === $response = $this->oauthClient->get($requestScope, $requestUri)) {
            throw new TokenException('no token available');
        }

        return $response;
    }

    private function post($requestScope, $requestUri, array $postBody)
    {
        if (false === $response = $this->oauthClient->post($requestScope, $requestUri, $postBody)) {
            throw new TokenException('no token available');
        }

        return $response;
    }

    private function verifySignature($jsonText, $instanceSignature)
    {
        $rawSignature = Base64::decode($instanceSignature);
        foreach ($this->publicKeys as $encodedPublicKey) {
            $publicKey = Base64::decode($encodedPublicKey);
            if (\Sodium\crypto_sign_verify_detached($rawSignature, $jsonText, $publicKey)) {
                return;
            }
        }

        throw new RuntimeException('unable to verify discovery file signature');
    }
}
