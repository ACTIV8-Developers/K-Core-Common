<?php

namespace Common\Services\AWS;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;
use Monolog\Logger;

class SQS
{
    private ?SqsClient $client = null;
    private Logger $logger;

    public function __construct($config, $logger)
    {
        $this->client = new SqsClient([
            'region' => $config['region'],
            'version' => 'latest',
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ]
        ]);
        $this->logger = $logger;
    }

    public function send(string $QueueUrl, string $MessageBody, array $MessageAttributes = [], int $delay = 3): ?Result
    {
        /*
         * [
                "Title" => [
                    'DataType' => "String",
                    'StringValue' => "The Hitchhiker's Guide to the Galaxy"
                ],
                "Author" => [
                    'DataType' => "String",
                    'StringValue' => "Douglas Adams."
                ],
                "WeeksOn" => [
                    'DataType' => "Number",
                    'StringValue' => "6"
                ]
            ]
         */
        $params = [
            'DelaySeconds' => $delay,
            'MessageAttributes' => $MessageAttributes,
            'MessageBody' => $MessageBody,
            'QueueUrl' => $QueueUrl
        ];

        try {
            $result = $this->client->sendMessage($params);
            $this->logger->error(SQS::class, $params);
            return $result;
        } catch (AwsException $e) {
            $this->logger->error(SQS::class, [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }
}