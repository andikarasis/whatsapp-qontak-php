<?php

declare(strict_types=1);

namespace Inisiatif\WhatsappQontakPhp;

use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Client\Common\HttpMethodsClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Inisiatif\WhatsappQontakPhp\Message\Message;
use Http\Client\Common\HttpMethodsClientInterface;
use Psr\Http\Client\ClientInterface as HttpClient;
use Inisiatif\WhatsappQontakPhp\Exceptions\ClientSendingException;

final class Client implements ClientInterface
{
    /**
     * @var HttpMethodsClientInterface
     */
    private $httpClient;

    /**
     * @var string|null
     */
    private $accessToken = null;

    /**
     * @var Credential
     */
    private $credential;

    /**
     * @var LoggerInterface
     */
    private $logger = null;

    public function __construct(Credential $credential, HttpClient $httpClient = null, LoggerInterface $logger = null)
    {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->httpClient = $httpClient ?? new HttpMethodsClient(
            Psr18ClientDiscovery::find(),
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory()
        );

        $this->credential = $credential;
        $this->logger = is_null($logger) ? new NullLogger() : $logger;
    }

    public function send(?string $accessToken, string $templateId, string $channelId, Message $message): Response
    {
        // If accessToken is not provided, use the class's accessToken property
        $token = $accessToken ?? $this->accessToken;

        // Make sure to retrieve the token if it's still not set
        if (!$token) {
            $this->getAccessToken();  // Ensure token is available
            $token = $this->accessToken;
        }

        $response = $this->httpClient->post(
            'https://service-chat.qontak.com/api/open/v1/broadcasts/whatsapp/direct',
            [
                'content-type' => 'application/json',
                'Authorization' => \sprintf('Bearer %s', $token ?? ''),
            ],
            \json_encode(
                [
                    'message_template_id' => $templateId,
                    'channel_integration_id' => $channelId,
                ] + $this->makeRequestBody($message)
            )
        );

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            /** @var array $responseBody */
            $responseBody = \json_decode((string) $response->getBody(), true);

            $this->logger->info(sprintf('[WhatsappQontakPhp] Response %s', $response->getStatusCode()), $responseBody);

            Assert::keyExists($responseBody, 'data');

            /** @var array<string, string|int> $responseData */
            $responseData = $responseBody['data'];
            Assert::keyExists($responseData, 'id');
            Assert::keyExists($responseData, 'name');

            return new Response((string) $responseData['id'], (string) $responseData['name'], $responseData);
        }

        throw ClientSendingException::make($response);
    }

    private function getAccessToken(): void
    {
        if ($this->accessToken === null) {
            $response = $this->httpClient->post(
                'https://service-chat.qontak.com/oauth/token',
                [
                    'content-type' => 'application/json',
                ],
                \json_encode($this->credential->getOAuthCredential())
            );

            /** @var array<array-key, string> $body */
            $body = \json_decode((string) $response->getBody(), true);

            Assert::keyExists($body, 'access_token');

            $this->accessToken = $body['access_token'];
        }
    }

    private function makeRequestBody(Message $message): array
    {
        return MessageUtil::makeRequestBody($message);
    }
}
