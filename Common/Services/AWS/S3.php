<?php

namespace Common\Services\AWS;

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Common\Services\IAM\Interfaces\IAMInterface;
use Core\Core\Core;
use Core\Http\Response;

class S3
{
    private ?S3Client $client = null;

    private ?IAMInterface $IAM;

    public function __construct($config, $IAM = null)
    {
        $this->client = new S3Client([
            'region' => $config['region'],
            'version' => 'latest',
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ]
        ]);
        $this->IAM = $IAM;
    }

    /**
     * @param string $key
     * @param string $filepath
     * @param string $bucket
     * @return Result
     */
    public function put(string $key, string $filepath, $bucket = "default_bucket")
    {
        list ($fixKey, $fixBucket) = $this->fixKeyBucket($key, $bucket);
        return $this->client->putObject([
            'Bucket' => $fixBucket,
            'Key' => $fixKey,
            'SourceFile' => $filepath
        ]);
    }

    /**
     * @param $key
     * @param string $bucket
     * @return Result
     */
    public function get($key, $bucket = "default_bucket")
    {
        list ($fixKey, $fixBucket) = $this->fixKeyBucket($key, $bucket);

        $result = $this->client->getObject(array(
            'Bucket' => $fixBucket,
            'Key' => $fixKey
        ));

        return $result;
    }

    /**
     * @param $key
     * @param string $bucket
     * @return boolean
     */
    public function delete($key, string $bucket = "default_bucket"): bool
    {
        /*
         'Bucket' => '<string>', // REQUIRED
        'BypassGovernanceRetention' => true || false,
        'ExpectedBucketOwner' => '<string>',
        'Key' => '<string>', // REQUIRED
        'MFA' => '<string>',
        'RequestPayer' => 'requester',
        'VersionId' => '<string>',
        */
        try {
            list ($fixKey, $fixBucket) = $this->fixKeyBucket($key, $bucket);

            $this->client->deleteObject([
                'Bucket' => $fixBucket,
                'Key' => $fixKey
            ]);
        } catch (S3Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @param $key
     * @param string $bucket
     * @return Response
     */
    public function getObjectAsResponse($key, string $bucket = "default_bucket", $forceStream = false, $overrideName = null): Response
    {
        list($path, $name) = $this->getObjectAsFile($key, $bucket, $forceStream, $overrideName);

        $response = new Response();
        $response->setHeader('Content-Description', 'File Transfer');
        $response->setHeader('Content-Type', 'application/octet-stream');
        $response->setHeader('Access-Control-Expose-Headers', 'Origin, Authorization, Content-Type, Accept-Ranges');
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        $response->setHeader('Expires', '0');
        $response->setHeader('Cache-Control', 'must-revalidate');
        $response->setHeader('Pragma', 'public');
        $response->setHeader("Content-Transfer-Encoding", "binary");
        $response->setHeader('Content-Length', filesize($path));
        $response->setBody(file_get_contents($path));

        return $response;
    }

    /**
     * @param $key
     * @param string $bucket
     * @param bool $forceStream
     * @param null $overrideName
     * @return array
     */
    public function getObjectAsFile($key, string $bucket = "default_bucket", bool $forceStream = false, $overrideName = null): array
    {
        $this->client->registerStreamWrapper();
        list ($fixKey, $fixBucket) = $this->fixKeyBucket($key, $bucket);
        $path = 's3://' . $fixBucket . '/' . $fixKey;
        $name = (($overrideName !== null) ? $overrideName : $key);
        // HACK
        if (Core::getInstance()->getContainer()->get('request')->get->has('video')) {
            $array = explode(".", $key);
            $tmpPath = "/tmp/video." . end($array);
            file_put_contents($tmpPath, file_get_contents($path));
            $this->videoStream($tmpPath);
        }
        if ($forceStream || Core::getInstance()->getContainer()->get('request')->get->has('stream')) {
            $this->fileStream($path, $name);
        }

        return [$path, $name];
    }

    /**
     * @throws \Exception
     */
    private function videoStream($filename)
    {
        $stream = new VideoStream($filename);
        $stream->start();
        die;
    }

    private function fileStream($filename, $key): void
    {
        set_time_limit(300);
        $size = intval(sprintf("%u", filesize($filename)));
        header('Content-Type: application/octet-stream');
        header('Content-Description: File Transfer');
        header('Content-Transfer-Encoding: binary');
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment;filename="' . $key . '"');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Credentials: true');
        $handle = fopen($filename, 'rb');
        if ($handle === false) {
            return;
        }
        $chunksize = 3 * (1024 * 1024);
        ob_clean();
        while (!feof($handle)) {
            $buffer = fread($handle, $chunksize);
            echo $buffer;
            ob_flush();
            flush();
        }
        fclose($handle);
        die;
    }

    public function putFileAsResponse($key, $bucket = "default_bucket", $tmpPath = '/tmp/tmp_name')
    {
        $this->client->registerStreamWrapper();
        list ($fixKey, $fixBucket) = $this->fixKeyBucket($key, $bucket);
        $path = 's3://' . $fixBucket . '/' . $fixKey;
        file_put_contents($tmpPath, file_get_contents($path));
    }

    private function fixKeyBucket($key, $bucket): array
    {
        $keyPrefix = null;
        if (!empty($this->IAM) && ($this->IAM->getCompanyID() !== null)) {
            $keyPrefix = $this->IAM->getCompanyID();
        }
        return [!empty($keyPrefix) ? $keyPrefix . "/" . $key : $key, $bucket];
    }

    public function getPath(string $key, string $bucket = 'default_bucket'): string
    {
        $this->client->registerStreamWrapper();
        list ($fixKey, $fixBucket) = $this->fixKeyBucket($key, $bucket);
        return 's3://' . $fixBucket . '/' . $fixKey;
    }
}