<?php

namespace Common;

use Exception;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Purli\Purli;

class MonologEmailHandler extends AbstractProcessingHandler
{
    private array $emails;

    /**
     * @param int|string $level The minimum logging level at which this handler will be triggered
     */
    public function __construct(array $emails, $level = Logger::DEBUG)
    {
        parent::__construct($level, true);

        $this->emails = $emails;
    }

    protected function write(array $record): void
    {
        $data = ['Mails' => $this->emails,
            'Message' =>
                ("Environment: " . APP_MODE) . "<br/>" .
                ($record['extra']['Email'] ?? "") . " - " .($record['extra']['ContactID'] ?? "") . "<br/>" .
                ($record['extra']['http_method'] ?? "") . " " . ($record['extra']['server'] ?? "") . ($record['extra']['url'] ?? "") . "<br/>" .
                ($record['extra']['ip'] ?? "") . "<br/>" .
                "<pre>" . ($record['message'] ?? "") . "</pre><br/>" .
                ($record['trace'] ?? ""),
            'Subject' => "Error happened"
        ];
        try{
         (new Purli(Purli::SOCKET))
            ->setHeader('Content-Type', 'application/json')
            ->setParams(json_encode($data))
            ->post(getenv("NOTIFICATION_SERVICE")."/sendMail")
            ->close();
        } catch (Exception $e) {

        }
    }
}