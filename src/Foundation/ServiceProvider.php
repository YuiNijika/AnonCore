<?php

namespace Anon\Core\Foundation;

abstract class ServiceProvider
{
    public function __construct(protected App $app)
    {
    }

    /**
     * 注册容器绑定、配置合并或基础服务
     */
    public function register(): void
    {
    }

    /**
     * 启动依赖路由、事件或中间件的服务
     */
    public function boot(): void
    {
    }

    public function app(): App
    {
        return $this->app;
    }
}