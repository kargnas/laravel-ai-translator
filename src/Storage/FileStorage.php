<?php

namespace Kargnas\LaravelAiTranslator\Storage;

use Kargnas\LaravelAiTranslator\Contracts\StorageInterface;

/**
 * FileStorage - File-based storage implementation for translation states
 * 
 * Primary Responsibilities:
 * - Provides persistent storage using the local filesystem
 * - Manages file-based caching with automatic directory creation
 * - Handles JSON serialization/deserialization of data
 * - Supports TTL-based expiration for cached data
 * - Implements atomic file operations for data integrity
 * - Provides directory-based organization for multi-tenant support
 * 
 * File Organization:
 * Files are organized in a hierarchical structure:
 * - Base path (e.g., storage/app/ai-translator/states/)
 * - Tenant subdirectories (optional)
 * - State files with .json extension
 * - Metadata files for TTL and versioning
 * 
 * Performance Characteristics:
 * - Fast for small to medium datasets
 * - No external dependencies
 * - Suitable for single-server deployments
 * - Limited by filesystem I/O performance
 */
class FileStorage implements StorageInterface
{
    /**
     * @var string Base storage path
     */
    protected string $basePath;

    /**
     * @var string File extension for storage files
     */
    protected string $extension = '.json';

    /**
     * @var bool Whether to use compression
     */
    protected bool $useCompression;

    /**
     * @var int Default file permissions
     */
    protected int $filePermissions = 0644;

    /**
     * @var int Default directory permissions
     */
    protected int $directoryPermissions = 0755;

    /**
     * Constructor
     * 
     * @param string $basePath Base storage directory path
     * @param bool $useCompression Whether to compress stored data
     */
    public function __construct(string $basePath, bool $useCompression = false)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->useCompression = $useCompression;
        
        // Ensure base directory exists
        $this->ensureDirectoryExists($this->basePath);
    }

    /**
     * Get data from storage
     * 
     * Responsibilities:
     * - Read file from filesystem
     * - Deserialize JSON data
     * - Check TTL expiration if set
     * - Handle decompression if enabled
     * 
     * @param string $key Storage key
     * @return mixed Stored data or null if not found/expired
     */
    public function get(string $key): mixed
    {
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return null;
        }

        try {
            $content = file_get_contents($filePath);
            
            if ($this->useCompression) {
                $content = gzuncompress($content);
                if ($content === false) {
                    throw new \RuntimeException('Failed to decompress data');
                }
            }

            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON data: ' . json_last_error_msg());
            }

            // Check TTL if present
            if (isset($data['__ttl']) && $data['__ttl'] < time()) {
                $this->delete($key);
                return null;
            }

            // Return data without metadata
            unset($data['__ttl'], $data['__stored_at']);
            
            return $data;
        } catch (\Exception $e) {
            // Log error and return null
            error_log("FileStorage::get error for key '{$key}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Put data into storage
     * 
     * Responsibilities:
     * - Serialize data to JSON
     * - Add TTL metadata if specified
     * - Apply compression if enabled
     * - Write atomically to prevent corruption
     * - Create parent directories as needed
     * 
     * @param string $key Storage key
     * @param mixed $value Data to store
     * @param int|null $ttl Time to live in seconds
     * @return bool Success status
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $filePath = $this->getFilePath($key);
        $directory = dirname($filePath);
        
        // Ensure directory exists
        $this->ensureDirectoryExists($directory);

        try {
            // Prepare data with metadata
            $data = $value;
            
            if (is_array($data)) {
                $data['__stored_at'] = time();
                
                if ($ttl !== null) {
                    $data['__ttl'] = time() + $ttl;
                }
            } else {
                // Wrap non-array data
                $data = [
                    '__value' => $data,
                    '__stored_at' => time(),
                ];
                
                if ($ttl !== null) {
                    $data['__ttl'] = time() + $ttl;
                }
            }

            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            if ($content === false) {
                throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
            }

            if ($this->useCompression) {
                $content = gzcompress($content, 9);
                if ($content === false) {
                    throw new \RuntimeException('Failed to compress data');
                }
            }

            // Write atomically using temporary file
            $tempFile = $filePath . '.tmp.' . uniqid();
            
            if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to write file');
            }

            // Set permissions
            chmod($tempFile, $this->filePermissions);
            
            // Atomic rename
            if (!rename($tempFile, $filePath)) {
                @unlink($tempFile);
                throw new \RuntimeException('Failed to rename temporary file');
            }

            return true;
        } catch (\Exception $e) {
            error_log("FileStorage::put error for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a key exists in storage
     * 
     * @param string $key Storage key
     * @return bool Whether the key exists
     */
    public function has(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return false;
        }

        // Check if not expired
        $data = $this->get($key);
        return $data !== null;
    }

    /**
     * Delete data from storage
     * 
     * @param string $key Storage key
     * @return bool Success status
     */
    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return true; // Already deleted
        }

        try {
            return unlink($filePath);
        } catch (\Exception $e) {
            error_log("FileStorage::delete error for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all data from storage
     * 
     * Responsibilities:
     * - Remove all files in storage directory
     * - Optionally preserve directory structure
     * - Handle subdirectories recursively
     * 
     * @return bool Success status
     */
    public function clear(): bool
    {
        try {
            $this->clearDirectory($this->basePath);
            return true;
        } catch (\Exception $e) {
            error_log("FileStorage::clear error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get file path for a storage key
     * 
     * Converts storage key to filesystem path
     * 
     * @param string $key Storage key
     * @return string File path
     */
    protected function getFilePath(string $key): string
    {
        // Sanitize key for filesystem
        $sanitizedKey = $this->sanitizeKey($key);
        
        // Convert colons to directory separators for organization
        $path = str_replace(':', '/', $sanitizedKey);
        
        return $this->basePath . '/' . $path . $this->extension;
    }

    /**
     * Sanitize storage key for filesystem usage
     * 
     * @param string $key Original key
     * @return string Sanitized key
     */
    protected function sanitizeKey(string $key): string
    {
        // Replace problematic characters
        $key = preg_replace('/[^a-zA-Z0-9_\-:.]/', '_', $key);
        
        // Remove multiple consecutive underscores
        $key = preg_replace('/_+/', '_', $key);
        
        // Trim underscores
        return trim($key, '_');
    }

    /**
     * Ensure directory exists with proper permissions
     * 
     * @param string $directory Directory path
     * @throws \RuntimeException If directory cannot be created
     */
    protected function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, $this->directoryPermissions, true)) {
                throw new \RuntimeException("Failed to create directory: {$directory}");
            }
        }
    }

    /**
     * Clear all files in a directory recursively
     * 
     * @param string $directory Directory to clear
     */
    protected function clearDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }

    /**
     * Get all keys in storage
     * 
     * @return array List of storage keys
     */
    public function keys(): array
    {
        $keys = [];
        
        if (!is_dir($this->basePath)) {
            return $keys;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $this->extension)) {
                $relativePath = str_replace($this->basePath . '/', '', $file->getRealPath());
                $key = str_replace($this->extension, '', $relativePath);
                $key = str_replace('/', ':', $key);
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Get storage statistics
     * 
     * @return array Storage statistics
     */
    public function getStats(): array
    {
        $stats = [
            'total_files' => 0,
            'total_size' => 0,
            'base_path' => $this->basePath,
            'compression' => $this->useCompression,
        ];

        if (!is_dir($this->basePath)) {
            return $stats;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $stats['total_files']++;
                $stats['total_size'] += $file->getSize();
            }
        }

        $stats['total_size_mb'] = round($stats['total_size'] / 1024 / 1024, 2);

        return $stats;
    }

    /**
     * Clean up expired entries
     * 
     * @return int Number of expired entries removed
     */
    public function cleanup(): int
    {
        $removed = 0;
        $keys = $this->keys();

        foreach ($keys as $key) {
            // Getting the key will automatically remove it if expired
            $data = $this->get($key);
            if ($data === null) {
                $removed++;
            }
        }

        return $removed;
    }
}