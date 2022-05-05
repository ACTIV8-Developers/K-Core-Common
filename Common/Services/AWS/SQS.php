<?php

namespace Common\Services\AWS;

use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;

class SQS
{
    private ?SqsClient $client = null;

    public function __construct($config)
    {
        $this->client = new SqsClient([
            'region' => $config['region'],
            'version' => 'latest',
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ]
        ]);
    }

    /**
     * @param string $key
     * @param string $filepath
     * @param string $bucket
     * @return Result
     */
    public function put(string $key, string $filepath, $bucket = "default_bucket")
    {
        return $this->client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SourceFile' => $filepath
        ]);
    }
}