<?php

/**
 * Class Logger
 * @package Payop
 */
class Logger
{
    /**
     * @var string
     */
    private $file;

    /**
     * @return \Payop\Logger
     */
    public static function create()
    {
        return new static();
    }

    private function __construct()
    {
        $this->file = __DIR__.'/logs/debug-{date}.log';
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function log($message, array $context = [])
    {
        $message = '['.\date('Y-m-d H:i:s').'] '.$message;
        if ($context) {
            $message .= ' '.\json_encode($context);
        }

        \file_put_contents(
            \str_replace('{date}', \date('Y-m-d'), $this->file),
            "$message\n",
            FILE_APPEND
        );
    }
}
