<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Hlášení průběhu a varování. Z příkazové řádky na stderr, na webu do
 * error_logu aplikace (logs/errors.log) — konstanta STDERR tam neexistuje.
 */
final class Log
{
    public static function warn(string $message): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, $message . "\n");
        } else {
            error_log('[cestopis] ' . $message);
        }
    }

    /** Průběh (jen z příkazové řádky — na webu by zaplnil log). */
    public static function info(string $message): void
    {
        if (PHP_SAPI === 'cli' && defined('STDERR')) {
            fwrite(STDERR, $message . "\n");
        }
    }
}
