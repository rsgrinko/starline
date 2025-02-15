<?php

namespace Starline;

use GuzzleHttp\{Client, Exception\GuzzleException, RequestOptions};
use Psr\Http\Message\ResponseInterface;
use Exception;

/**
 * Class Starline
 * @package Starline
 * @author kowapssupport@gmail.com
 */
class Starline {

    /** @var Config */
    private $config;
    /** @var LoggerInterface */
    private $logger;

    /** Таймаут в секундах на выполнение запроса Guzzle. */
    protected const GUZZLE_TIMEOUT_SECONDS = 15;

    /**
     * Выполнение команд управления охранно-телематическим комплексом.
     * @see https://developer.starline.ru/#api-Administration-SetParam
     * @param string $slnetToken
     * @param string $deviceId
     * @param array $params
     * @return array
     * @throws GuzzleException
     */
    public function runQuery(string $slnetToken, string $deviceId, array $params = []): array {
        $response = $this->getClient()->request(
            'POST',
            'https://developer.starline.ru/json/v1/device/' . $deviceId . '/set_param',
            [
                RequestOptions::JSON    => $params,
                RequestOptions::HEADERS => [
                    'Cookie' => 'slnet=' . $slnetToken
                ],
            ]
        );
        /** @noinspection DuplicatedCode */
        $content = $response->getBody()->getContents();

        if (!$this->checkResponse($response, $content, __METHOD__)) {
            return [];
        }
        try {
            $object = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [];
        }

        $code = isset($object['code']) ? (string)$object['code'] : '';

        if (empty($code)) {
            $this->logError(
                'Error response',
                [
                    'method'          => __METHOD__,
                    'response_object' => $object
                ]
            );
            return [];
        }
        return $object;
    }

    /**
     * UserData - Получение данных устройств пользователя
     * @see https://developer.starline.ru/#api-UserData-UserData
     * @param string $slnetToken
     * @param string $userToken
     * @param int $userId
     * @return array
     * @throws Exception|GuzzleException
     */
    public function fetchDevicesInfo(string $slnetToken, string $userToken, int $userId): array
    {
        if (empty($slnetToken) || empty($userToken || !$userId)) {
            throw new Exception('Incorrect param values.');
        }
        $response = $this->createGetRequest(
            'https://developer.starline.ru/json/v3/user/' . $userId . '/data',
            [],
            [
                'Cookie' => 'slnet=' . $slnetToken,
            ]
        );
        $content = $response->getBody()->getContents();

        /** @noinspection DuplicatedCode */
        if (!$this->checkResponse($response, $content, __METHOD__)) {
            return [];
        }
        try {
            $object = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [];
        }

        $code = isset($object['code']) ? (string)$object['code'] : '';

        if (empty($code)) {
            $this->logError(
                'Error response',
                [
                    'method'          => __METHOD__,
                    'response_object' => $object
                ]
            );
            return [];
        }
        return $object;
    }

    /**
     * Запрос на авторизацию в Starline NET.
     * @see https://developer.starline.ru/#api-Authorization-userSLNETAuth
     * @param string $userToken
     * @return array [$slnet, $user_id]
     * @throws Exception|GuzzleException
     */
    public function fetchSLNETToken(string $userToken): array
    {
        $response = $this->getClient()->request('POST', 'https://developer.starline.ru/json/v2/auth.slid', [
            RequestOptions::JSON => [
                'slid_token' => $userToken
            ],
        ]);
        $content = $response->getBody()->getContents();

        if (!$this->checkResponse($response, $content, __METHOD__)) {
            return [];
        }
        try {
            $object = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $t) {
            return [];
        }

        $code   = isset($object['code']) ? (int)$object['code'] : '';
        $userId = isset($object['user_id']) ? (string)$object['user_id'] : '';

        if (empty($code) || $code !== 200 || empty($userId)) {
            $this->logError(
                'Error response',
                [
                    'method'          => __METHOD__,
                    'response_object' => $object
                ]
            );
            return [];
        }
        $cookies = $response->getHeaders()['Set-Cookie'] ?? [];

        if (empty($cookies)) {
            $this->logError(
                'SLNET not found in response cookies',
                [
                    'method'         => __METHOD__,
                    'headers_object' => $response->getHeaders()
                ]
            );
            return [];
        }
        $cookieExploded = explode('; ', $cookies[0] ?? []);
        $firstCookie = $cookieExploded[0] ?? '';

        if (mb_strpos($firstCookie, 'slnet') === false) {
            $this->logError(
                'SLNET not found in response cookies',
                [
                    'method'         => __METHOD__,
                    'headers_object' => $response->getHeaders()
                ]
            );
            return [];
        }
        $explodedSlnet = explode('=', $firstCookie);
        $slnet = $explodedSlnet[1] ?? '';
        if (empty($slnet)) {
            $this->logError(
                'SLNET not found in response cookies',
                [
                    'method'         => __METHOD__,
                    'headers_object' => $response->getHeaders()
                ]
            );
            return [];
        }
        return [$slnet, $userId];
    }

    /**
     * SLID - Авторизация пользователя
     * @see https://id.starline.ru/apiV3/user/login
     * @param string $token
     * @param array $params,  ['user_ip' => ..., 'captchaSid' => '...', 'captchaCode' => '...']
     * @return string
     * @throws Exception|GuzzleException
     */
    public function fetchUserToken(string $token, array $params = []): string
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $config = $this->getConfig();
        $requestParams = [
            'login' => $config->getLogin(),
            'pass'  => sha1($config->getPassword()),
        ];

        if (!empty($params)) {
            $requestParams = array_merge($requestParams, $params);
        }
        $response = $this->createPostRequest(
            'https://id.starline.ru/apiV3/user/login',
            $requestParams,
            [
                'token' => $token,
            ]
        );
        $content = $response->getBody()->getContents();

        if (!$this->checkResponse($response, $content, __METHOD__)) {
            return '';
        }
        try {
            $object = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return '';
        }
        
        $state = $object['state'] ?? false;
        if ($state !== 1) {
            $this->logError(
                'fetchUserToken error response',
                [
                    'method'          => __METHOD__,
                    'response_object' => $object
                ]
            );
            return '';
        }
        return $object['desc']['user_token'] ?? '';
    }

    /**
     * SLID - Получение кода приложения
     * @see https://developer.starline.ru/#api-SLID-getAppCode
     * @return string
     * @throws Exception|GuzzleException
     */
    public function fetchCode(): string
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $config = $this->getConfig();
        $secret = md5($config->getSecret());
        $response = $this->createGetRequest(
            'https://id.starline.ru/apiV3/application/getCode',
            [
                'appId'  => $config->getAppId(),
                'secret' => $secret,
            ]
        );
        $content = $response->getBody()->getContents();

        if (!$this->checkResponse($response, $content, __METHOD__)) {
            return '';
        }
        try{
            $object = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return '';
        }
        $codeKey = $object['desc']['code'] ?? false;
        if (!is_string($codeKey)) {
            $this->logError(
                'Code not found in response.',
                [
                    'method'          => __METHOD__,
                    'response_object' => $object
                ]
            );
            return '';
        }
        return $codeKey;
    }

    /**
     * SLID - Получение токена приложения.
     * @see https://developer.starline.ru/#api-SLID-getAppToken
     * @param string $code
     * @return string
     * @throws Exception|GuzzleException
     */
    public function fetchToken(string $code): string {
        if (empty($code)) {
            return '';
        }
        /** @noinspection PhpUnhandledExceptionInspection */
        $config = $this->getConfig();
        $secret = md5($config->getSecret().$code);
        $response = $this->createGetRequest(
            'https://id.starline.ru/apiV3/application/getToken',
            [
                'appId'  => $config->getAppId(),
                'secret' => $secret,
            ]
        );
        $content = $response->getBody()->getContents();

        if (!$this->checkResponse($response, $content, __METHOD__)) {
            return '';
        }
        try {
            $object = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return '';
        }
        $token_key = $object['desc']['token'] ?? false;
        if (!is_string($token_key)) {
            $this->logError(
                'Token not found in response api',
                [
                    'method' => __METHOD__,
                    'response_object' => $object
                ]
            );
            return '';
        }
        return $token_key;
    }

    protected function checkResponse(ResponseInterface $response, string $content, string $method = ''): bool
    {
        //status code != 200
        if ((int)$response->getStatusCode() !== 200) {
            $this->logError(
                'Respond status code: ' . $response->getStatusCode(),
                [
                    'method' => $method
                ]
            );
            return false;
        }
        if (empty($content)) {
            $this->logError(
                'Response is empty: ' . $content,
                [
                    'method'  => $method,
                    'content' => $content
                ]
            );
            return false;
        }
        return true;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @param Config $config
     * @return $this
     */
    public function setConfig(Config $config): self
    {
        $this->config = $config;
        return $this;
    }

    /**
     * @return Config
     * @throws Exception
     */
    public function getConfig(): Config
    {
        if ($this->config === null) {
            throw new Exception('Logger not set, '. Config::class);
        }
        return $this->config;
    }

    /**
     * @param string $url
     * @param array $params
     * @param array $headers
     * @return ResponseInterface
     * @throws GuzzleException
     */
    protected function createPostRequest(string $url, array $params = [], array $headers = []): ResponseInterface
    {
        return $this->getClient()->request(
            'POST',
            $url,
            [
                'form_params' => $params,
                'headers'     => $headers,
            ]
        );
    }

    /**
     * @param string $url
     * @param array $params
     * @param array $headers
     * @return ResponseInterface
     * @throws GuzzleException
     */
    protected function createGetRequest(string $url, array $params = [], array $headers = []): ResponseInterface
    {
        return $this->getClient()->get(
            $url,
            [
                'query'   => $params,
                'headers' => $headers,
            ]);
    }

    protected function getClient(): Client
    {
        return new Client(
            [
                'timeout' => static::GUZZLE_TIMEOUT_SECONDS,
                'verify'  => false,
            ]
        );
    }

    /**
     * @param string $message
     * @param array $params
     */
    protected function logError(string $message, array $params = []): void
    {
        if ($this->logger === null) {
            return;
        }
        $this->logger->logError($message, $params);
    }
}
