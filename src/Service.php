<?php

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2020, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Web;

use fkooman\OAuth\Client\Http\HttpClientInterface;
use fkooman\OAuth\Client\Http\Request as HttpRequest;
use fkooman\OAuth\Client\OAuthClient;
use fkooman\OAuth\Client\Provider;
use LC\Web\Http\Exception\HttpException;
use LC\Web\Http\Request;
use LC\Web\Http\Response;
use RuntimeException;

class Service
{
    /** @var SessionInterface */
    private $session;

    /** @var Config */
    private $config;

    /** @var TplInterface */
    private $tpl;

    /** @var \fkooman\OAuth\Client\Http\HttpClientInterface */
    private $httpClient;

    /** @var \fkooman\OAuth\Client\OAuthClient */
    private $oauthClient;

    /** @var string */
    private $dataDir;

    /**
     * @param string $dataDir
     */
    public function __construct(SessionInterface $session, Config $config, TplInterface $tpl, OAuthClient $oauthClient, HttpClientInterface $httpClient, $dataDir)
    {
        $this->session = $session;
        $this->config = $config;
        $this->tpl = $tpl;
        $this->httpClient = $httpClient;
        $this->oauthClient = $oauthClient;
        $this->dataDir = $dataDir;
    }

    /**
     * @param Http\Request $request
     *
     * @return Http\Response
     */
    public function run(Request $request)
    {
        try {
            switch ($request->getMethod()) {
                case 'HEAD':
                case 'GET':
                    switch ($request->getPathInfo()) {
                        case '/':
                            return $this->showHome($request->getRootUri());
                        case '/settings':
                            return new Response(
                                200,
                                [],
                                $this->tpl->render(
                                    'settings',
                                    [
                                        'forceTcp' => $this->session->getForceTcp(),
                                    ]
                                )
                            );
                        case '/chooseServer':
                            return $this->showChooseServer();
                        case '/addOtherServer':
                            return new Response(200, [], $this->tpl->render('add_other_server', []));
                        case '/switchLocation':
                            return $this->showSwitchLocation();
                        case '/chooseOrganization':
                            return $this->showChooseOrganization();
                        case '/getProfileList':
                            $baseUri = self::validateBaseUri($request->getQueryParameter('baseUri'));

                            return $this->getProfileList($baseUri, $request->getRootUri());
                        case '/callback':
                            // handle OAuth server callback
                            return $this->handleCallback($request);
                        default:
                            throw new HttpException('Not Found', 404);
                    }
                    // no break
                case 'POST':
                    switch ($request->getPathInfo()) {
                        case '/addServer':
                            $baseUri = self::validateBaseUri($request->getPostParameter('baseUri'));

                            return new Response(
                                302,
                                [
                                    'Location' => $request->getRootUri().'getProfileList?baseUri='.$baseUri,
                                ]
                            );

                        case '/addOtherServer':
                            $baseUri = self::validateBaseUri('https://'.$request->getPostParameter('serverName').'/');

                            return new Response(
                                302,
                                [
                                    'Location' => $request->getRootUri().'getProfileList?baseUri='.$baseUri,
                                ]
                            );

                        case '/selectOrganization':
                            // validation of h getBaseUriFromOrgId as that is a whitelist...
                            if (null === $baseUri = $this->getBaseUriFromOrgId($request->getPostParameter('orgId'))) {
                                throw new HttpException('invalid "orgId"', 400);
                            }

                            return new Response(
                                302,
                                [
                                    'Location' => $request->getRootUri().'getProfileList?baseUri='.$baseUri,
                                ]
                            );

                        case '/switchLocation':
                            if (null === $baseUri = self::validateBaseUri($request->getPostParameter('baseUri'))) {
                                throw new HttpException('missing "baseUri"', 400);
                            }
                            $this->session->setSecureInternetBaseUri($baseUri);

                            return new Response(
                                302,
                                [
//                                    'Location' => $request->getRootUri().'getProfileList?baseUri='.$baseUri,
                                        'Location' => $request->getRootUri(),
                                ]
                            );

                        case '/saveSettings':
                            $this->session->setForceTcp('on' === $request->getPostParameter('forceTcp'));

                            return new Response(302, ['Location' => $request->getRootUri()]);

                        case '/downloadProfile':
                            $profileId = self::validateProfileId($request->getPostParameter('profileId'));
                            $baseUri = self::validateBaseUri($request->getPostParameter('baseUri'));

                            return $this->handleDownloadProfile($request->getRootUri(), $profileId, $baseUri);
                        case '/resetAppData':
                            $this->session->destroy();

                            return new Response(302, ['Location' => $request->getRootUri()]);
                        default:
                            throw new HttpException('Not Found', 404);
                    }
                    // no break
                default:
                    throw new HttpException('Method Not Allowed', 405, ['Allow' => 'GET,HEAD,POST']);
            }
        } catch (HttpException $e) {
            return new Response(
                $e->getCode(),
                $e->getResponseHeaders(),
                $this->tpl->render(
                    'error',
                    [
                        'errorCode' => $e->getCode(),
                        'errorMessage' => $e->getMessage(),
                    ]
                )
            );
        }
    }

    /**
     * @param string $rootUri
     *
     * @return Http\Response
     */
    private function showHome($rootUri)
    {
        $myInstituteAccessBaseUriList = $this->session->getMyInstituteAccessBaseUriList();
        $myInstituteAccessServerList = [];
        foreach ($myInstituteAccessBaseUriList as $baseUri) {
            $myInstituteAccessServerList[] = $this->getServerInfo($baseUri);
        }

        $myAlienBaseUriList = $this->session->getMyAlienBaseUriList();
        $myAlienServerList = [];
        foreach ($myAlienBaseUriList as $baseUri) {
            $myAlienServerList[] = $this->getServerInfo($baseUri);
        }

        $secureInternetBaseUri = $this->session->getSecureInternetBaseUri();
        $secureInternetServerInfo = null !== $secureInternetBaseUri ? $this->getServerInfo($secureInternetBaseUri) : null;

        if (0 === \count($myInstituteAccessServerList) && 0 === \count($myAlienBaseUriList) && null === $secureInternetServerInfo) {
            return new Response(302, ['Location' => $rootUri.'chooseServer']);
        }

        return new Response(
            200,
            [],
            $this->tpl->render(
                'home',
                [
                    'myInstituteAccessServerList' => $myInstituteAccessServerList,
                    'myAlienServerList' => $myAlienServerList,
                    'secureInternetServerInfo' => $secureInternetServerInfo,
                ]
            )
        );
    }

    /**
     * @return Http\Response
     */
    private function showChooseServer()
    {
        return new Response(
            200,
            [],
            $this->tpl->render(
                'choose_server',
                [
                    'hasSecureInternet' => null !== $this->session->getSecureInternetHomeBaseUri(),
                    'instituteList' => $this->getInstituteAccessServerList(),
                ]
            )
        );
    }

    /**
     * @return Http\Response
     */
    private function showSwitchLocation()
    {
        return new Response(
            200,
            [],
            $this->tpl->render(
                'switch_location',
                [
                    'secureInternetServerList' => $this->getSecureInternetServerList(),
                ]
            )
        );
    }

    /**
     * @return Http\Response
     */
    private function showChooseOrganization()
    {
        return new Response(
            200,
            [],
            $this->tpl->render(
                'choose_organization',
                [
                    'organizationList' => $this->getOrganizationList(),
                ]
            )
        );
    }

    /**
     * @param string $orgId
     *
     * @return string|null
     */
    private function getBaseUriFromOrgId($orgId)
    {
        $idpList = $this->getOrganizationList();
        foreach ($idpList as $idpEntry) {
            if ($orgId === $idpEntry['org_id']) {
                return $idpEntry['secure_internet_home'];
            }
        }

        return null;
    }

    /**
     * @param string|null $baseUri
     * @param string      $rootUri
     *
     * @return Http\Response
     */
    private function getProfileList($baseUri, $rootUri)
    {
        if (null === $baseUri) {
            throw new HttpException('baseUri parameter missing', 400);
        }

        $response = $this->doOAuthCall('GET', $rootUri, $baseUri, 'profile_list');
        if (\is_string($response)) {
            return new Response(302, ['Location' => $response]);
        }
        $profileList = $response->json()['profile_list']['data'];

        $response = $this->doOAuthCall('GET', $rootUri, $baseUri, 'system_messages');
        if (\is_string($response)) {
            return new Response(302, ['Location' => $response]);
        }
        $systemMessages = $response->json()['system_messages']['data'];

        return new Response(
            200,
            [],
            $this->tpl->render(
                'profile_list',
                [
                    'profileList' => $profileList,
                    'systemMessages' => $systemMessages,
                    'serverInfo' => $this->getServerInfo($baseUri),
                    'baseUri' => $baseUri,
                ]
            )
        );
    }

    /**
     * @param string $requestMethod
     * @param string $appRootUri
     * @param string $baseUri
     * @param string $apiMethod     the RELATIVE request call, e.g. "profile_list"
     *
     * @return \fkooman\OAuth\Client\Http\Response|string
     */
    private function doOAuthCall($requestMethod, $appRootUri, $baseUri, $apiMethod, array $queryPostParameters = [])
    {
        $userId = $baseUri; // use baseUri as user_id
        $providerInfo = $this->getProviderInfo($baseUri);
        $serverInfo = $this->getServerInfo($baseUri);
        // are we trying to connect to a secure internet server?
        if ('secure_internet' === $this->getServerInfo($baseUri)['type']) {
            if (null !== $secureInternetHomeBaseUri = $this->session->getSecureInternetHomeBaseUri()) {
                // we already have a home server!
                $secureInternetProviderInfo = $this->getProviderInfo($secureInternetHomeBaseUri);
                // override the OAuth stuff to point to the home server
                $providerInfo['authorization_endpoint'] = $secureInternetProviderInfo['authorization_endpoint'];
                $providerInfo['token_endpoint'] = $secureInternetProviderInfo['token_endpoint'];
                $userId = $secureInternetHomeBaseUri;
            }
        }

        $provider = new Provider(
            $this->config->get('OAuth')->get('clientId'),
            null,
            $providerInfo['authorization_endpoint'],
            $providerInfo['token_endpoint']
        );
        $apiBaseUri = $providerInfo['api_base_uri'];

        if ('GET' === $requestMethod) {
            $request = HttpRequest::get(sprintf('%s/%s', $apiBaseUri, $apiMethod), $queryPostParameters);
        } else {
            // MUST be POST for now
            $request = HttpRequest::post(sprintf('%s/%s', $apiBaseUri, $apiMethod), $queryPostParameters);
        }

        $response = $this->oauthClient->send(
            $provider,
            $userId,
            $this->config->get('OAuth')->get('requestScope'),
            $request
        );

        if (false === $response) {
            $this->session->setBaseUri($baseUri);
            // no valid OAuth token available...
            $authorizeUri = $this->oauthClient->getAuthorizeUri(
                $provider,
                $userId,
                $this->config->get('OAuth')->get('requestScope'),
                sprintf('%scallback', $appRootUri)
            );

            return $authorizeUri;
        }

        return $response;
    }

    /**
     * @param string      $rootUri
     * @param string|null $profileId
     * @param string|null $baseUri
     *
     * @return Http\Response
     */
    private function handleDownloadProfile($rootUri, $profileId, $baseUri)
    {
        if (null === $profileId) {
            throw new HttpException('missing "profileId"', 400);
        }
        if (null === $baseUri) {
            throw new HttpException('missing "baseUri"', 400);
        }

        // get keypair
        $response = $this->doOAuthCall('POST', $rootUri, $baseUri, 'create_keypair');
        if (\is_string($response)) {
            return new Response(302, ['Location' => $response]);
        }
        $keyPair = $response->json()['create_keypair']['data'];

        $response = $this->doOAuthCall('GET', $rootUri, $baseUri, 'profile_config', ['profile_id' => $profileId]);
        if (\is_string($response)) {
            return new Response(302, ['Location' => $response]);
        }

        $vpnConfig = $response->getBody();

        if ($this->session->getForceTcp()) {
            // remove all lines from the config that start with "remote" and have UDP in them
            $vpnConfigRows = explode("\r\n", $vpnConfig);
            foreach ($vpnConfigRows as $k => $vpnConfigRow) {
                if (0 === strpos($vpnConfigRow, 'remote ') && false !== strpos($vpnConfigRow, 'udp')) {
                    unset($vpnConfigRows[$k]);
                }
            }
            $vpnConfig = implode("\r\n", $vpnConfigRows);
        }

        $vpnConfig .= "\r\n<cert>\r\n".$keyPair['certificate']."\r\n</cert>\r\n";
        $vpnConfig .= "<key>\r\n".$keyPair['private_key']."\r\n</key>\r\n";

        return new Response(
            200,
            [
                'Content-Type' => 'application/x-openvpn-profile',
                'Content-Disposition' => sprintf('attachment; filename="%s.ovpn"', $profileId),
            ],
            $vpnConfig
        );
    }

    /**
     * @param string $baseUri
     *
     * @return array
     */
    private function getProviderInfo($baseUri)
    {
        $infoUrl = sprintf('%sinfo.json', $baseUri);
        $infoResponse = $this->httpClient->send(HttpRequest::get($infoUrl));
        if (!$infoResponse->isOkay()) {
            throw new RuntimeException(sprintf('unable to fetch "%s"', $infoUrl));
        }

        return $infoResponse->json()['api']['http://eduvpn.org/api#2'];
    }

    /**
     * @param string $baseUri
     *
     * @return array
     */
    private function getServerInfo($baseUri)
    {
        $fileList = [
            'institute_access' => $this->dataDir.'/institute_access.json',
            'secure_internet' => $this->dataDir.'/secure_internet.json',
        ];
        foreach ($fileList as $type => $discoveryFile) {
            $discoveryData = json_decode(file_get_contents($discoveryFile), true);
            foreach ($discoveryData['instances'] as $serverEntry) {
                if ($baseUri === $serverEntry['base_uri']) {
                    $serverEntry['type'] = $type;

                    return $serverEntry;
                }
            }
        }

        return [
            'base_uri' => $baseUri,
            'display_name' => $baseUri,
            'type' => 'alien',
        ];
    }

    /**
     * @return array
     */
    private function getInstituteAccessServerList()
    {
        $x = json_decode(file_get_contents($this->dataDir.'/institute_access.json'), true)['instances'];
        self::sortByDisplayName($x);

        return $x;
    }

    /**
     * @return array
     */
    private function getSecureInternetServerList()
    {
        $x = json_decode(file_get_contents($this->dataDir.'/secure_internet.json'), true)['instances'];
        self::sortByDisplayName($x);

        return $x;
    }

    /**
     * @return array
     */
    private function getOrganizationList()
    {
        $x = json_decode(file_get_contents($this->dataDir.'/organization_list_2.json'), true)['organization_list'];
        self::sortByDisplayName($x);

        return $x;
    }

    /**
     * @return Http\Response
     */
    private function handleCallback(Request $request)
    {
        if (null === $baseUri = $this->session->getBaseUri()) {
            throw new HttpException('missing "baseUri"', 400);
        }
        $this->session->removeBaseUri();
        $providerInfo = $this->getProviderInfo($baseUri);
        $provider = new Provider(
            $this->config->get('OAuth')->get('clientId'),
            null,
            $providerInfo['authorization_endpoint'],
            $providerInfo['token_endpoint']
        );

        $this->oauthClient->handleCallback(
            $provider,
            $baseUri, // misuse baseUri as user_id
            $request->getQueryParameters()
        );

        // add baseUri to server list
        $serverInfo = $this->getServerInfo($baseUri);
        if ('secure_internet' === $serverInfo['type']) {
            if (null === $this->session->getSecureInternetHomeBaseUri()) {
                $this->session->setSecureInternetHomeBaseUri($baseUri);
            }
            $this->session->setSecureInternetBaseUri($baseUri);
        } elseif ('institute_access' === $serverInfo['type']) {
            // never add the same baseUri twice
            if (!\in_array($baseUri, $this->session->getMyInstituteAccessBaseUriList(), true)) {
                $this->session->addInstituteAccessBaseUri($baseUri);
            }
        } else {
            // never add the same baseUri twice
            if (!\in_array($baseUri, $this->session->getMyAlienBaseUriList(), true)) {
                $this->session->addAlienBaseUri($baseUri);
            }
        }

        // redirect back
        return new Response(
            302,
            [
//                'Location' => $request->getRootUri().'getProfileList?baseUri='.$baseUri,
                'Location' => $request->getRootUri(),
            ]
        );
    }

    /**
     * @param string|null $baseUri
     *
     * @return string|null
     */
    private static function validateBaseUri($baseUri)
    {
        if (null !== $baseUri) {
            if (1 !== preg_match('/^https:\/\/[a-zA-Z0-9-.]+\/$/', $baseUri)) {
                throw new HttpException(sprintf('invalid baseUri "%s"', $baseUri), 400);
            }
        }

        return $baseUri;
    }

    /**
     * @param string|null $profileId
     *
     * @return string|null
     */
    private static function validateProfileId($profileId)
    {
        if (null !== $profileId) {
            if (1 !== preg_match('/^[a-zA-Z0-9-.]+$/', $profileId)) {
                throw new HttpException(sprintf('invalid profileId "%s"', $profileId), 400);
            }
        }

        return $profileId;
    }

    /**
     * @return void
     */
    private static function sortByDisplayName(array &$entryList)
    {
        usort(
            $entryList,
            function (array $a, array $b) {
                $dnA = $a['display_name'];
                $dnB = $b['display_name'];
                if (\is_array($dnA)) {
                    $dnA = array_values($dnA)[0];
                }
                if (\is_array($dnB)) {
                    $dnB = array_values($dnB)[0];
                }

                return strcasecmp($dnA, $dnB);
            }
        );
    }
}
