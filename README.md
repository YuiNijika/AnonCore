# Anon Framework Next Core

这是一个现代化、轻量级且功能强大的 PHP 后端 API 框架核心库。它采用了极其优雅的设计理念，内置了丰富的基础组件，使得开发高性能的 RESTful API 变得简单且高效。

## 特性 (Features)

- **依赖注入容器 (IoC Container)**：支持自动依赖装配与循环依赖检测，极大提升代码解耦能力。
- **极简路由系统**：支持动态参数、路由组嵌套，结合洋葱模型中间件栈，实现精准的请求拦截与分发。
- **强大的门面模式 (Facade)**：无需繁琐的依赖注入即可静态调用底层动态对象。
- **多数据库支持**：内置防注入的 QueryBuilder 和 ActiveRecord 风格的 ORM，无缝支持 `MySQL`、`PostgreSQL`、`SQLite`、`SQLServer` 和 `Oracle`。
- **JWT 无状态认证**：原生提供安全的 JSON Web Token 签发与验证，支持多端多密钥隔离，完美适配 API 场景。
- **丰富的内置组件**：开箱即用的文件存储 (Storage)、缓存 (Cache)、数据验证 (Validator)、日志 (Log) 和事件/钩子 (Event/Hook) 机制。
- **开发者友好的 CLI 工具**：内置 `anon` 命令行控制台，支持快速启动开发服务器与代码生成器。

## 环境要求

- PHP >= 8.1
- PDO 扩展 (及其对应的数据库驱动如 pdo_mysql, pdo_sqlite 等)
- JSON 扩展
- OpenSSL 扩展

## 安装

通常情况下，你不应该直接克隆或安装本核心库。推荐使用 [Anon Framework Next Skeleton](https://github.com/yuinijika/anon) 作为你的应用脚手架，核心库将作为依赖被自动安装。

如果你想在现有的项目中独立使用该核心库：

```bash
composer require yuinijika/anon-core
```

## 文档

完整的框架使用文档和 API 参考，请参阅随 Skeleton 提供的 VitePress 文档。

## 许可证 (License)

本项目遵循 [MIT 许可证](LICENSE)。
