<?php

/**
 * Helper functions
 */

use App\Helpers\EnvLoader;

/**
 * Get environment variable (shorthand)
 */
function env(string $key, $default = null)
{
    return EnvLoader::get($key, $default);
}

/**
 * Log message to file
 */
function app_log(string $message, string $level = 'INFO'): void
{
    $logDir = BASE_PATH . '/storage/logs/';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND);
}

/**
 * Sanitize string input
 */
function sanitize(string $input): string
{
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate UUID v4
 */
function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}