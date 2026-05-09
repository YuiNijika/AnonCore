<?php

namespace Anon\Core\Http;

use Exception;

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
     * 获取原始文件名
     */
    public function getClientOriginalName(): string
    {
        return $this->originalName;
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
     * 获取文件大小（字节）
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
     * @param string $directory 目标目录
     * @param string|null $name 可选的新文件名，如果不传则生成随机名
     * @return string 返回保存的完整路径
     * @throws Exception
     */
    public function move(string $directory, ?string $name = null): string
    {
        if (!$this->isValid()) {
            throw new Exception("Cannot move invalid uploaded file. Error code: {$this->error}");
        }

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new Exception("Directory '{$directory}' was not created");
            }
        }

        $name = $name ?? $this->generateUniqueName();
        $targetPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;

        if (!move_uploaded_file($this->tempName, $targetPath)) {
            throw new Exception("Could not move the file to '{$targetPath}'");
        }

        return $targetPath;
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
