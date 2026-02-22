<?php

namespace App\Helpers;

class EnvLoader
{
    private static array $variables = [];
    private static bool $loaded = false;

    /**
     * Load .env file from project root
     */
    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        $path = $path ?? BASE_PATH . '/.env';

        if (!file_exists($path)) {
            throw new \RuntimeException(".env file not found at: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove surrounding quotes
            if (
                (strlen($value) >= 2) &&
                (($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
                 ($value[0] === "'" && $value[strlen($value) - 1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Convert special values
            $value = match (strtolower($value)) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                ''      => '',
                default => $value,
            };

            self::$variables[$key] = $value;

            // Also set in $_ENV and putenv for global access
            $_ENV[$key] = $value;
            if (is_string($value) || is_numeric($value)) {
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    /**
     * Get environment variable
     */
    public static function get(string $key, $default = null)
    {
        if (isset(self::$variables[$key])) {
            return self::$variables[$key];
        }

        $envValue = getenv($key);
        if ($envValue !== false) {
            return $envValue;
        }

        return $_ENV[$key] ?? $default;
    }

    /**
     * Check if variable exists
     */
    public static function has(string $key): bool
    {
        return isset(self::$variables[$key]) || isset($_ENV[$key]) || getenv($key) !== false;
    }
}