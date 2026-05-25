<?php

namespace Anon\Core\Http;

use Exception;
use Anon\Core\Facade\Config;

class UploadedFile
{
    protected string $originalName;
    protected string $mimeType;
    protected int $size;
    protected int $error;
    protected string $tempName;

    public function __construct(array $fileInfo)
    {
        $this->originalName = $fileInfo['name'] ?? '';
        $this->mimeType = $fileInfo['type'] ?? '';
        $this->size = $fileInfo['size'] ?? 0;
        $this->error = $fileInfo['error'] ?? UPLOAD_ERR_NO_FILE;
        $this->tempName = $fileInfo['tmp_name'] ?? '';
    }

    /**
     * 获取原始文件名 (经过 basename 处理以防止路径穿越)
     */
    public function getClientOriginalName(): string
    {
        return basename($this->originalName);
    }

    /**
     * 获取文件扩展名
     */
    public function getClientOriginalExtension(): string
    {
        return pathinfo($this->originalName, PATHINFO_EXTENSION);
    }

    /**
     * 获取文件MIME类型
     */
    public function getClientMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * 获取文件大小
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * 获取上传错误码
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * 判断文件是否上传成功
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tempName);
    }

    /**
     * 获取临时文件路径
     */
    public function getTempName(): string
    {
        return $this->tempName;
    }

    /**
     * 获取文件内容的完整字符串
     */
    public function getContents(): string
    {
        if (!$this->isValid()) {
            throw new Exception("Cannot read contents of invalid uploaded file.");
        }
        return file_get_contents($this->tempName);
    }

    /**
     * 将文件移动到指定目录
     *
     * @param string|null $directory 目标目录（如果为 null，则从配置读取 upload.path）
     * @param string|null $name 可选的新文件名，如果不传则生成随机名
     * @return string 返回保存的完整路径
     * @throws Exception
     */
    public function move(?string $directory = null, ?string $name = null): string
    {
        if (!$this->isValid()) {
            throw new Exception("Cannot move invalid uploaded file. Error code: {$this->error}");
        }

        $defaultUploadPath = defined('BASE_PATH') ? BASE_PATH . '/run/storage' : sys_get_temp_dir();
        $directory = $directory ?? Config::get('upload.path', $defaultUploadPath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new Exception("Directory '{$directory}' was not created");
            }
        }

        $name = $name ?? $this->generateUniqueName();
        $name = $this->sanitizeTargetName($name);
        $targetPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;

        $realDirectory = realpath($directory);
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new Exception("Directory '{$targetDir}' was not created");
            }
        }

        $targetDirectory = realpath($targetDir);
        if ($realDirectory === false || $targetDirectory === false || !$this->isInsideDirectory($targetDirectory, $realDirectory)) {
            throw new Exception("Invalid upload target path.");
        }

        if (!move_uploaded_file($this->tempName, $targetPath)) {
            throw new Exception("Could not move the file to '{$targetPath}'");
        }

        return $targetPath;
    }

    protected function sanitizeTargetName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $name = ltrim($name, '/');

        if ($name === '' || str_contains($name, '..') || preg_match('#^[A-Za-z]:/#', $name)) {
            throw new Exception("Invalid uploaded file name.");
        }

        return $name;
    }

    protected function isInsideDirectory(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }

    /**
     * 生成一个基于时间与随机数的唯一文件名，保留原有扩展名
     */
    protected function generateUniqueName(): string
    {
        $extension = $this->getClientOriginalExtension();
        $baseName = md5(uniqid() . microtime(true));
        return $extension ? "{$baseName}.{$extension}" : $baseName;
    }
}
