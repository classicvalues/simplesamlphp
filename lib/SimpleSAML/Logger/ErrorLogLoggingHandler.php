<?php

declare(strict_types=1);

namespace SimpleSAML\Logger;

use Psr\Log\LogLevel;
use SimpleSAML\Configuration;
use SimpleSAML\Logger;

/**
 * A class for logging to the default php error log.
 *
 * @package SimpleSAMLphp
 */
class ErrorLogLoggingHandler implements LoggingHandlerInterface
{
    /**
     * This array contains the mappings from syslog log level to names.
     *
     * @var array<string, string>
     */
    private static array $levelNames = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /**
     * The name of this process.
     *
     * @var string
     */
    private string $processname;


    /**
     * ErrorLogLoggingHandler constructor.
     *
     * @param \SimpleSAML\Configuration $config The configuration object for this handler.
     */
    public function __construct(Configuration $config)
    {
        // Remove any non-printable characters before storing
        $this->processname = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $config->getString('logging.processname', 'SimpleSAMLphp'));
    }


    /**
     * Set the format desired for the logs.
     *
     * @param string $format The format used for logs.
     */
    public function setLogFormat(string $format): void
    {
        // we don't need the format here
    }


    /**
     * Log a message to syslog.
     *
     * @param string $level The log level.
     * @param string $string The formatted message to log.
     */
    public function log(string $level, string $string): void
    {
        if (in_array($level, self::$levelNames, true)) {
            $levelName = $level;
        } else {
            $levelName = sprintf('UNKNOWN%d', $level);
        }

        $formats = ['%process', '%level'];
        $replacements = [$this->processname, $levelName];
        $string = str_replace($formats, $replacements, $string);
        $string = trim($string);

        error_log($string);
    }
}
