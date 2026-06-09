<?php
declare(strict_types=1);

namespace Cms\Lib;

final class Storage
{
    private static ?string $dataDir = null;

    private static function dataDir(): string
    {
        if (self::$dataDir === null) {
            $dir = getenv('DATA_DIR') ?: __DIR__ . '/../../data';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$dataDir = $dir;
        }
        return self::$dataDir;
    }

    public static function readCollection(string $file): array
    {
        $path = self::dataDir() . '/' . $file;
        if (!file_exists($path)) {
            return [];
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read data file: $path");
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException("Data file corrupted: $path");
        }
        return is_array($data) ? $data : [];
    }

    public static function writeCollection(string $file, array $items): void
    {
        $dir = self::dataDir();
        $path = $dir . '/' . $file;
        $tmp = $path . '.tmp';
        $body = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException("Cannot encode data for $path");
        }
        file_put_contents($tmp, $body);
        rename($tmp, $path);
    }

    public static function withLock(string $file, callable $fn): mixed
    {
        $dir = self::dataDir();
        $lockPath = $dir . '/' . $file . '.lock';
        $fh = fopen($lockPath, 'c+');
        if ($fh === false) {
            throw new \RuntimeException("Cannot acquire lock: $lockPath");
        }
        flock($fh, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
