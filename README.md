# Anon Framework Next Core

这是一个现代化、轻量级的 PHP 后端 API 框架核心库。内置了基础组件，用于开发 RESTful API。

## 核心特性

- **路由系统**：支持路由组、中间件、参数捕获及 RESTful 路由映射。
- **门面模式 (Facade)**：无需依赖注入即可静态调用底层动态对象。
- **依赖注入容器**：自动解析类依赖，支持单例与实例绑定。
- **JWT 无状态认证**：提供 JSON Web Token 签发与验证，支持多端多密钥隔离，适配 API 场景。
- **结构化配置**：支持通过项目根目录下的 `anon.config.php` 管理应用配置，并兼容 `.env` 作为敏感值来源。

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
