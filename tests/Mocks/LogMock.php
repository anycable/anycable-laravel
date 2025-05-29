<?php

namespace AnyCable\Laravel\Tests\Mocks;

class LogMock
{
    /**
     * The logged messages.
     *
     * @var array
     */
    public static $messages = [];

    /**
     * Clear the logged messages.
     *
     * @return void
     */
    public static function clear()
    {
        static::$messages = [];
    }

    /**
     * Log an error message.
     *
     * @param  string  $message
     * @return void
     */
    public static function error($message, array $context = [])
    {
        static::$messages[] = [
            'level' => 'error',
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * Log a warning message.
     *
     * @param  string  $message
     * @return void
     */
    public static function warning($message, array $context = [])
    {
        static::$messages[] = [
            'level' => 'warning',
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * Log an info message.
     *
     * @param  string  $message
     * @return void
     */
    public static function info($message, array $context = [])
    {
        static::$messages[] = [
            'level' => 'info',
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * Log a debug message.
     *
     * @param  string  $message
     * @return void
     */
    public static function debug($message, array $context = [])
    {
        static::$messages[] = [
            'level' => 'debug',
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * Get all logged messages.
     *
     * @return array
     */
    public static function getMessages()
    {
        return static::$messages;
    }

    /**
     * Get messages for a specific level.
     *
     * @param  string  $level
     * @return array
     */
    public static function getMessagesForLevel($level)
    {
        return array_filter(static::$messages, function ($message) use ($level) {
            return $message['level'] === $level;
        });
    }
}
