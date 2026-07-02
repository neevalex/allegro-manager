<?php
declare(strict_types=1);

namespace App\Allegro;

/**
 * Atomic JSON file persistence with safe atomic writes.
 * 
 * Uses temporary files and rename to prevent corruption on write failure.
 */
final class JsonStore
{
    public function __construct(private string $path)
    {
    }

    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if ($json === false) {
            throw new \RuntimeException('Could not encode JSON store.');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write temporary JSON store.');
        }
        
        chmod($tmp, 0660);
        
        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not replace JSON store.');
        }
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
