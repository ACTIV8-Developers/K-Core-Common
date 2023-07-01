<?php

namespace Common;

use Exception;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class MonologSlackHandler extends AbstractProcessingHandler
{
    private string $apiToken = "";

    public function __construct($token, $level = Logger::DEBUG)
    {
        parent::__construct($level, true);
        $this->apiToken = $token;
    }

    protected function write(array $record): void
    {
        try {
            $this->slack($record);
        } catch (Exception $e) {

        }
    }

    protected function slack($record)
    {
        $webhook_url = $this->apiToken;

        // Slack message payload
        $payload = [
            'text' => 'An exception occurred in TMS ' . ("Environment: " . APP_MODE) . ' ' . ($record['message'] ?? ""),
            'attachments' => [
                [
                    'color' => '#FF0000',
                    'text' => ($record['message'] ?? ""),
                    'fields' => [
                        [
                            'title' => 'Email',
                            'value' => ($record['extra']['Email'] ?? "")
                        ],
                        [
                            'title' => 'ContactID',
                            'value' => ($record['extra']['ContactID'] ?? "")
                        ],
                        [
                            'title' => 'Method',
                            'value' => ($record['extra']['http_method'] ?? "")
                        ],
                        [
                            'title' => 'Url',
                            'value' => ($record['extra']['server'] ?? "") . ($record['extra']['url'] ?? "")
                        ],[
                            'title' => 'IP',
                            'value' => ($record['extra']['ip'] ?? "")
                        ]
                    ]
                ]
            ]
        ];

        // Send the message to Slack
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $webhook_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);
    }
}