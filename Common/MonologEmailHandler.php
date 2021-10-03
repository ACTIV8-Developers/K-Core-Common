<?php

namespace Common;

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
                ($record['Email'] ?? "") . "<br/>" .
                ($record['server'] ?? "") . ($record['url'] ?? "") . "<br/>" .
                ($record['message'] ?? "") . "<br/>" .
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
            $this->logger->notice($e->getMessage());
        }
    }
}