<?php
/**
 * Created by PhpStorm.
 * User: fbruenner
 * Date: 14.06.2018
 * Time: 11:29
 */

namespace CustomerManagementFrameworkBundle\Service;

use AppBundle\Model\Customer;
use CustomerManagementFrameworkBundle\CustomerProvider\CustomerProviderInterface;
use CustomerManagementFrameworkBundle\Repository\Service\Auth\AccessTokenRepository;
use CustomerManagementFrameworkBundle\Repository\Service\Auth\AuthCodeRepository;
use CustomerManagementFrameworkBundle\Repository\Service\Auth\ClientRepository;
use CustomerManagementFrameworkBundle\Repository\Service\Auth\RefreshTokenRepository;
use CustomerManagementFrameworkBundle\Repository\Service\Auth\ScopeRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\ImplicitGrant;
use Pimcore\Bundle\AdminBundle\HttpFoundation\JsonResponse;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\User;
use Pimcore\Security\Encoder\Factory\UserAwareEncoderFactory;
use Pimcore\Security\Encoder\PasswordFieldEncoder;
use Pimcore\Tool\RestClient\Exception;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\File\Stream;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoder;


class AuthorizationServer{

    static public $GRANT_TYPE_AUTH_GRANT = "auth_grant";

    static public $GRANT_TYPE_IMPLICIT_GRANT = "implicit_grant";

    static public $GRANT_TYPE_PASSWORD_GRANT = "password";

    /**
     * @var AuthCodeRepository
     */
    private $authCodeRepository = null;

    /**
     * @var AuthCodeRepository
     */
    private $accessTokenRepository = null;

    /**
     * @var clientRepository
     */
    private $clientRepository = null;

    /**
     * @var \League\OAuth2\Server\AuthorizationServer
     */
    private $server = null;

    /**
     * @var CustomerProviderInterface $customerProvider
     */
    private $currentUser = null;


    /**
     * @param string $grantType
     * @param Request $request
     * @return RedirectResponse|JsonResponse
     * @throws \Exception
     */
    public function validateClient(string $grantType, Request $request){

        if($grantType !== self::$GRANT_TYPE_AUTH_GRANT && $grantType !== self::$GRANT_TYPE_IMPLICIT_GRANT && $grantType !== self::$GRANT_TYPE_PASSWORD_GRANT){
            throw new HttpException("AuthorizationServer ERROR: GRANT TYPE: ".$grantType." NOT SUPPORTED", 400);
        }

        switch ($grantType){
            case self::$GRANT_TYPE_AUTH_GRANT:
                return $this->getAuthTokenForAuthGrantClient($request);
            case self::$GRANT_TYPE_IMPLICIT_GRANT:
                return $this->getAccessTokenForImplicitGrantClient($request);
            case self::$GRANT_TYPE_PASSWORD_GRANT:
                return $this->getAccessTokenForPasswordGrantClient($request);
        }

    }

    /**
     * @param Request $request
     * @return RedirectResponse|JsonResponse
     * @throws \Exception
     */
    public function getAccessTokenForAuthGrantClient(Request $request){

        try {

            $this->initAuthGrantServer();

            $psr7Factory = new DiactorosFactory();
            $psrRequest = $psr7Factory->createRequest($request);

            $symfonyResponse = new Response();
            $psrResponse = $psr7Factory->createResponse($symfonyResponse);

            $httpFoundationFactory = new HttpFoundationFactory();
            $symfonyResponse = $httpFoundationFactory->createResponse($this->server->respondToAccessTokenRequest($psrRequest,$psrResponse));

            return $symfonyResponse;

        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {
            return $this->sendJSONResponse($exception, $exception->getMessage(), $exception->getHttpStatusCode());

        } catch (\Exception $exception) {
            return $this->sendJSONResponse($exception, $exception->getMessage(), $exception->getHttpStatusCode());
        }


    }

    /**
     * @param Request $request
     * @return JsonResponse|Response
     * @throws \Exception
     */
    public function getRefreshTokenForAuthGrantClient(Request $request){

        try {

            $this->initRefreshGrantServer();

            $psr7Factory = new DiactorosFactory();
            $psrRequest = $psr7Factory->createRequest($request);

            $symfonyResponse = new Response();
            $psrResponse = $psr7Factory->createResponse($symfonyResponse);

            $httpFoundationFactory = new HttpFoundationFactory();
            $symfonyResponse = $httpFoundationFactory->createResponse($this->server->respondToAccessTokenRequest($psrRequest,$psrResponse));

            return $symfonyResponse;

        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {

            return $this->sendJSONResponse($exception, $exception->getMessage(), $exception->getHttpStatusCode());

        } catch (\Exception $exception) {
            return $this->sendJSONResponse($exception, $exception->getMessage(), $exception->getHttpStatusCode());
        }

    }

    /**
     * @param Request $request
     * @return RedirectResponse|JsonResponse
     * @throws \Exception
     */
    public function getAccessTokenForImplicitGrantClient(Request $request){

        try {

            return $this->getAuthTokenForImplicitGrantClient($request);

        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {

            return $this->sendJSONResponse($exception, $exception->getMessage(), $exception->getHttpStatusCode());

        } catch (\Exception $exception) {
            return $this->sendJSONResponse($exception, $exception->getMessage(), $exception->getHttpStatusCode());
        }


    }

    /**
     * @param Request $request
     * @return RedirectResponse|JsonResponse
     * @throws \Exception
     */
    public function getAccessTokenForPasswordGrantClient(Request $request){

        try {

            return $this->getAuthTokenForPasswordGrantClient($request);

        } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {

            return $this->sendJSONResponse($exception, $exception->getMessage(), $exception->getHttpStatusCode());

        } catch (\Exception $exception) {

            return $this->sendJSONResponse($exception, $exception->getMessage(), "500");
        }


    }

    /**
     * @param Request $request
     * @return JsonResponse|ServerRequestInterface
     * @throws \Exception
     */
    public function validateAuthenticatedRequest(Request $request){

        /**
         * @var \CustomerManagementFrameworkBundle\Repository\Service\Auth\AccessTokenRepository $accessTokenRepository
         */
        $accessTokenRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\AccessTokenRepository");

        $oauthServerConfig = \Pimcore::getContainer()->getParameter("pimcore_customer_management_framework.oauth_server");

        if(!key_exists("publicKeyDir", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.publicKeyDir NOT DEFINED IN config.xml");
        }
        $publicKeyPath = $oauthServerConfig["publicKeyDir"];

        $server = new \League\OAuth2\Server\ResourceServer(
            $accessTokenRepository,
            $publicKeyPath
        );

        new \League\OAuth2\Server\Middleware\ResourceServerMiddleware($server);

        try{
            $psr7Factory = new DiactorosFactory();
            $psrRequest = $psr7Factory->createRequest($request);

            return $server->validateAuthenticatedRequest($psrRequest);
        }
        catch (OAuthServerException $exception){
            return $this->sendJSONResponse($exception, $exception->getMessage(), $exception->getHttpStatusCode());
        }

    }

    /**
     * @param string $username
     * @param string $password
     * @return mixed
     * @throws \Exception
     */
    public function authUser(string $username, string $password)
    {
        $customerProvider = \Pimcore::getContainer()->get(CustomerProviderInterface::class);
        $this->currentUser = $customerProvider->getActiveCustomerByEmail($username);

        if(!$this->currentUser){
            throw new HttpException(401, "AUTHORIZATION FAILED");
        }

        /**
         * @var DataObject\ClassDefinition\Data\Password $field
         */
        $passwordField = $this->currentUser->getClass()->getFieldDefinition('password');

        if(!$passwordField->verifyPassword($password, $this->currentUser)){
            throw new HttpException(401, "AUTHORIZATION FAILED");
        }
    }

    /**
     * @throws \Exception
     */
    private function initAuthGrantServer(){

        $this->clientRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\ClientRepository");
        $scopeRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\ScopeRepository");
        $accessTokenRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\AccessTokenRepository");
        $this->authCodeRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\AuthCodeRepository");
        $refreshTokenRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\RefreshTokenRepository");

        $oauthServerConfig = \Pimcore::getContainer()->getParameter("pimcore_customer_management_framework.oauth_server");

        if(!key_exists("privateKeyDir", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.privateKeyDir NOT DEFINED IN config.xml");
        }
        $privateKey = $oauthServerConfig["privateKeyDir"];

        if(!key_exists("encryptionKey", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.encryptionKey NOT DEFINED IN config.xml");
        }
        $encryptionKey = $oauthServerConfig["encryptionKey"];

        if(!key_exists("expireAuthorizationCode", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.expireAuthorizationCode NOT DEFINED IN config.xml");
        }
        $expireAuthorizationCode = $oauthServerConfig["expireAuthorizationCode"];

        if(!key_exists("expireAccessTokenCode", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.expireAccessTokenCode NOT DEFINED IN config.xml");
        }
        $expireAccessTokenCode = $oauthServerConfig["expireAccessTokenCode"];

        if(!key_exists("expireRefreshTokenCode", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.expireRefreshTokenCode NOT DEFINED IN config.xml");
        }
        $expireRefreshTokenCode = $oauthServerConfig["expireRefreshTokenCode"];

        /**
         * @var \League\OAuth2\Server\AuthorizationServer $server
         */
        $server = new \League\OAuth2\Server\AuthorizationServer(
            $this->clientRepository,
            $accessTokenRepository,
            $scopeRepository,
            $privateKey,
            $encryptionKey
        );

        $grant = new \League\OAuth2\Server\Grant\AuthCodeGrant(
            $this->authCodeRepository,
            $refreshTokenRepository,
            new \DateInterval($expireAuthorizationCode)
        );

        $grant->setRefreshTokenTTL(new \DateInterval($expireRefreshTokenCode)); // refresh tokens will expire after 1 month

        $server->enableGrantType(
            $grant,
            new \DateInterval($expireAccessTokenCode) // access tokens will expire after 1 hour
        );

        $this->server = $server;
    }

    /**
     * @throws \Exception
     */
    private function initRefreshGrantServer(){

        $this->clientRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\ClientRepository");
        $scopeRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\ScopeRepository");
        $accessTokenRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\AccessTokenRepository");
        $refreshTokenRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\RefreshTokenRepository");

        $oauthServerConfig = \Pimcore::getContainer()->getParameter("pimcore_customer_management_framework.oauth_server");

        if(!key_exists("privateKeyDir", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.privateKeyDir NOT DEFINED IN config.xml");
        }
        $privateKey = $oauthServerConfig["privateKeyDir"];

        if(!key_exists("encryptionKey", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.encryptionKey NOT DEFINED IN config.xml");
        }
        $encryptionKey = $oauthServerConfig["encryptionKey"];

        if(!key_exists("expireAccessTokenCode", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.expireAccessTokenCode NOT DEFINED IN config.xml");
        }
        $expireAccessTokenCode = $oauthServerConfig["expireAccessTokenCode"];

        if(!key_exists("expireRefreshTokenCode", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.expireRefreshTokenCode NOT DEFINED IN config.xml");
        }
        $expireRefreshTokenCode = $oauthServerConfig["expireRefreshTokenCode"];

        /**
         * @var \League\OAuth2\Server\AuthorizationServer $server
         */
        $server = new \League\OAuth2\Server\AuthorizationServer(
            $this->clientRepository,
            $accessTokenRepository,
            $scopeRepository,
            $privateKey,
            $encryptionKey
        );

        $grant = new \League\OAuth2\Server\Grant\RefreshTokenGrant($refreshTokenRepository);
        $grant->setRefreshTokenTTL(new \DateInterval($expireRefreshTokenCode));

        $server->enableGrantType(
            $grant,
            new \DateInterval($expireAccessTokenCode)
        );

        $this->server = $server;
    }

    /**
     * @throws \Exception
     */
    private function initImplicitGrantServer(){

        $this->clientRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\ClientRepository");
        $scopeRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\ScopeRepository");
        $accessTokenRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\AccessTokenRepository");

        $oauthServerConfig = \Pimcore::getContainer()->getParameter("pimcore_customer_management_framework.oauth_server");

        if(!key_exists("privateKeyDir", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.privateKeyDir NOT DEFINED IN config.xml");
        }
        $privateKey = $oauthServerConfig["privateKeyDir"];

        if(!key_exists("encryptionKey", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.encryptionKey NOT DEFINED IN config.xml");
        }
        $encryptionKey = $oauthServerConfig["encryptionKey"];

        if(!key_exists("expireAccessTokenCode", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.expireAccessTokenCode NOT DEFINED IN config.xml");
        }
        $expireAccessTokenCode = $oauthServerConfig["expireAccessTokenCode"];

        /**
         * @var \League\OAuth2\Server\AuthorizationServer $server
         */
        $server = new \League\OAuth2\Server\AuthorizationServer(
            $this->clientRepository,
            $accessTokenRepository,
            $scopeRepository,
            $privateKey,
            $encryptionKey
        );

        $server->enableGrantType(
            new ImplicitGrant(new \DateInterval($expireAccessTokenCode), "?"),
            new \DateInterval($expireAccessTokenCode)
        );

        $this->server = $server;
    }

    /**
     * @throws \Exception
     */
    private function initPasswordGrantServer(){

        $this->clientRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\ClientRepository");
        $scopeRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\ScopeRepository");
        $this->accessTokenRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\AccessTokenRepository");
        $userRepository  = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\UserRepository");
        $refreshTokenRepository = \Pimcore::getContainer()->get("CustomerManagementFrameworkBundle\Repository\Service\Auth\RefreshTokenRepository");

        $oauthServerConfig = \Pimcore::getContainer()->getParameter("pimcore_customer_management_framework.oauth_server");

        if(!key_exists("privateKeyDir", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.privateKeyDir NOT DEFINED IN config.xml");
        }
        $privateKey = $oauthServerConfig["privateKeyDir"];

        if(!key_exists("encryptionKey", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.encryptionKey NOT DEFINED IN config.xml");
        }
        $encryptionKey = $oauthServerConfig["encryptionKey"];

        if(!key_exists("expireAccessTokenCode", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.expireAccessTokenCode NOT DEFINED IN config.xml");
        }
        $expireAccessTokenCode = $oauthServerConfig["expireAccessTokenCode"];

        if(!key_exists("expireRefreshTokenCode", $oauthServerConfig)){
            throw new HttpException(400, "AuthorizationServer ERROR: pimcore_customer_management_framework.oauth_server.expireRefreshTokenCode NOT DEFINED IN config.xml");
        }
        $expireRefreshTokenCode = $oauthServerConfig["expireRefreshTokenCode"];

        /**
         * @var \League\OAuth2\Server\AuthorizationServer $server
         */
        $server = new \League\OAuth2\Server\AuthorizationServer(
            $this->clientRepository,
            $this->accessTokenRepository,
            $scopeRepository,
            $privateKey,
            $encryptionKey
        );

        $grant = new \League\OAuth2\Server\Grant\PasswordGrant(
            $userRepository,
            $refreshTokenRepository
        );

        $grant->setRefreshTokenTTL(new \DateInterval($expireRefreshTokenCode)); // refresh tokens will expire after 1 month

        $server->enableGrantType(
            $grant,
            new \DateInterval($expireAccessTokenCode)
        );

        $this->server = $server;
    }

    /**
     * @param Request $request
     * @return JsonResponse|RedirectResponse
     * @throws \Exception
     */
    private function getAuthTokenForAuthGrantClient(Request $request){

        $this->initAuthGrantServer();

        $psr7Factory = new DiactorosFactory();
        $psrRequest = $psr7Factory->createRequest($request);

        try {

            // Validate the HTTP request and return an AuthorizationRequest object.
            $authRequest = $this->server->validateAuthorizationRequest($psrRequest);

            // Once the user has logged in set the user on the AuthorizationRequest
            $authRequest->setUser($this->currentUser); // an instance of UserEntityInterface

            $this->authCodeRepository->setUserIdenifier($this->currentUser->getIdentifier());

            // At this point you should redirect the user to an authorization page.
            // This form will ask the user to approve the client and the scopes requested.

            // Once the user has approved or denied the client update the status
            // (true = approved, false = denied)
            $authRequest->setAuthorizationApproved(true);

            // Return the HTTP redirect response

            $symfonyResponse = new Response();
            $psrResponse = $psr7Factory->createResponse($symfonyResponse);

            $response = $this->server->completeAuthorizationRequest($authRequest, $psrResponse);

            $redirectResponse = new RedirectResponse($request->query->get("redirect_uri"));
            $redirectResponse->headers->add($response->getHeaders());

            return $redirectResponse;

        } catch (OAuthServerException $exception) {

            return $this->sendJSONResponse($exception,$exception->getMessage(), $exception->getHttpStatusCode());

        } catch (\Exception $exception) {
            return $this->sendJSONResponse($exception,$exception->getMessage());
        }

    }

    /**
     * @param Request $request
     * @return JsonResponse|RedirectResponse
     * @throws \Exception
     */
    private function getAuthTokenForImplicitGrantClient(Request $request){

        $this->initImplicitGrantServer();

        $psr7Factory = new DiactorosFactory();
        $psrRequest = $psr7Factory->createRequest($request);

        try {

            // Validate the HTTP request and return an AuthorizationRequest object.
            $authRequest = $this->server->validateAuthorizationRequest($psrRequest);

            // Once the user has logged in set the user on the AuthorizationRequest
            $authRequest->setUser($this->currentUser); // an instance of UserEntityInterface

            // At this point you should redirect the user to an authorization page.
            // This form will ask the user to approve the client and the scopes requested.

            // Once the user has approved or denied the client update the status
            // (true = approved, false = denied)
            $authRequest->setAuthorizationApproved(true);

            // Return the HTTP redirect response

            $symfonyResponse = new Response();
            $psrResponse = $psr7Factory->createResponse($symfonyResponse);

            $response = $this->server->completeAuthorizationRequest($authRequest, $psrResponse);

            $redirectResponse = new RedirectResponse($request->query->get("redirect_uri"));
            $redirectResponse->headers->add($response->getHeaders());

            return $redirectResponse;

        } catch (OAuthServerException $exception) {

            return $this->sendJSONResponse($exception,$exception->getMessage(), $exception->getHttpStatusCode());

        } catch (\Exception $exception) {
            return $this->sendJSONResponse($exception,$exception->getMessage(), $exception->getHttpStatusCode());
        }

    }

    /**
     * @param Request $request
     * @return JsonResponse|Response
     * @throws \Exception
     */
    private function getAuthTokenForPasswordGrantClient(Request $request){

        try {

            $this->initPasswordGrantServer();

            /**
             * @var \CustomerManagementFrameworkBundle\Repository\Service\Auth\AccessTokenRepository $accessTokenRepository
             */
            $this->accessTokenRepository->setUserIdenifier($this->currentUser->getId()); // an instance of UserEntityInterface

            $psr7Factory = new DiactorosFactory();
            $psrRequest = $psr7Factory->createRequest($request);

            $symfonyResponse = new Response();
            $psrResponse = $psr7Factory->createResponse($symfonyResponse);

            $httpFoundationFactory = new HttpFoundationFactory();
            $symfonyResponse = $httpFoundationFactory->createResponse($this->server->respondToAccessTokenRequest($psrRequest,$psrResponse));

            return $symfonyResponse;

        } catch (OAuthServerException $exception) {

            return $this->sendJSONResponse($exception,$exception->getMessage(), $exception->getHttpStatusCode());

        } catch (\Exception $exception) {
            return $this->sendJSONResponse($exception,$exception->getMessage(), $exception->getHttpStatusCode());
        }

    }

    /**
     * @param \Exception|null $exception
     * @param string $content
     * @param int $status
     * @return JSONResponse
     * @throws \Exception
     */
    private function sendJSONResponse($exception, string $message, int $status){
        // use it when debugging directly with server-controller actions
        if(false && \Pimcore::inDebugMode() && $exception) {
            throw $exception;
        }

        $symfonyResponse = new JSONResponse();
        $symfonyResponse->setStatusCode($status)->setContent($message);

        return $symfonyResponse;
    }
}