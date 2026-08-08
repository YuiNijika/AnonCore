<?php

namespace Anon\Core\Container;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionException;
use Anon\Core\Exception\Container as ContainerError;

class Container
{
    /**
     * @var Container|null 容器单例
     */
    protected static ?Container $instance = null;

    /**
     * @var array 绑定的标识与具体的类或闭包的映射
     */
    protected array $bindings = [];

    /**
     * @var array 已经实例化的单例对象池
     */
    protected array $instances = [];

    /**
     * @var array 当前请求/任务作用域内的对象池
     */
    protected array $scopedInstances = [];

    /**
     * @var array<string, 'singleton'|'scoped'|'transient'>
     */
    protected array $lifetimes = [];

    /**
     * @var array 正在构建的类栈
     */
    protected array $buildStack = [];

    /**
     * @var array 缓存类的反射信息以提高性能
     */
    protected array $reflectionCache = [];

    /**
     * @var array 缓存类的反射参数信息
     */
    protected array $dependenciesCache = [];

    /**
     * 获取当前容器的单例实例
     *
     * 注意：子类（如 App）不得在此处无参 `new static`——App 构造需要 basePath。
     * 未引导时对子类调用应抛错，避免 ArgumentCountError。
     */
    public static function getInstance(): static
    {
        if (is_null(static::$instance)) {
            if (static::class !== self::class) {
                throw new ContainerError(
                    static::class . ' has not been bootstrapped. Construct it with the required arguments first (e.g. new App($basePath)).'
                );
            }

            static::$instance = new self();
        }

        /** @var static $instance */
        $instance = static::$instance;

        return $instance;
    }

    /**
     * 设置当前容器的单例实例
     */
    public static function setInstance(?Container $container = null): ?Container
    {
        return static::$instance = $container;
    }

    /**
     * 绑定一个类、闭包或接口到容器
     * @param string $abstract 标识
     * @param mixed $concrete 具体实现
     * @return $this
     */
    public function bind(string $abstract, mixed $concrete = null): self
    {
        $this->bindings[$abstract] = $concrete ?: $abstract;
        $this->lifetimes[$abstract] = 'singleton';
        return $this;
    }

    public function scoped(string $abstract, mixed $concrete = null): self
    {
        $this->bindings[$abstract] = $concrete ?: $abstract;
        $this->lifetimes[$abstract] = 'scoped';
        return $this;
    }

    public function transient(string $abstract, mixed $concrete = null): self
    {
        $this->bindings[$abstract] = $concrete ?: $abstract;
        $this->lifetimes[$abstract] = 'transient';
        return $this;
    }

    /**
     * 直接绑定一个已存在的实例到容器
     * @param string $abstract 标识
     * @param mixed $instance 实例对象
     * @return $this
     */
    public function instance(string $abstract, mixed $instance): self
    {
        $this->instances[$abstract] = $instance;
        $this->lifetimes[$abstract] = 'singleton';
        return $this;
    }

    public function scopedInstance(string $abstract, mixed $instance): self
    {
        $this->scopedInstances[$abstract] = $instance;
        $this->lifetimes[$abstract] = 'scoped';
        return $this;
    }

    public function flushScope(): void
    {
        $this->scopedInstances = [];
    }

    /**
     * 解析出给定标识的实例
     * @param string $abstract 标识或类名
     * @param array $vars 手动指定的参数
     * @param bool $newInstance 是否强制创建新实例
     * @return mixed
     * @throws Exception
     */
    public function make(string $abstract, array $vars = [], bool $newInstance = false): mixed
    {
        if (isset($this->instances[$abstract]) && !$newInstance) {
            return $this->instances[$abstract];
        }

        if (isset($this->scopedInstances[$abstract]) && !$newInstance) {
            return $this->scopedInstances[$abstract];
        }

        // 检测循环依赖
        if (isset($this->buildStack[$abstract])) {
            throw new ContainerError("Circular dependency detected while resolving {$abstract}");
        }
        $this->buildStack[$abstract] = true;

        try {
            // 获取实际应该实例化的具体内容
            $concrete = $this->bindings[$abstract] ?? $abstract;

            // 如果是闭包，执行闭包并传入容器实例和参数
            if ($concrete instanceof Closure) {
                $object = $concrete($this, $vars);
            } else {
                // 否则尝试利用反射 API 实例化该类
                $object = $this->invokeClass($concrete, $vars);
            }
        } finally {
            unset($this->buildStack[$abstract]);
        }

        if (!$newInstance) {
            $lifetime = $this->lifetimes[$abstract] ?? 'singleton';
            if ($lifetime === 'singleton') {
                $this->instances[$abstract] = $object;
            } elseif ($lifetime === 'scoped') {
                $this->scopedInstances[$abstract] = $object;
            }
        }

        return $object;
    }

    /**
     * 利用反射实例化类，并自动解析其构造函数依赖
     * @param string $class 类名
     * @param array $vars 手动传入的参数
     * @return object
     * @throws Exception
     */
    public function invokeClass(string $class, array $vars = []): object
    {
        try {
            if (isset($this->reflectionCache[$class])) {
                $reflect = $this->reflectionCache[$class];
            } else {
                $reflect = new ReflectionClass($class);
                $this->reflectionCache[$class] = $reflect;
            }
        } catch (ReflectionException $e) {
            throw new ContainerError("Class {$class} does not exist", 0, $e);
        }

        if (!$reflect->isInstantiable()) {
            throw new ContainerError("Class {$class} is not instantiable");
        }

        $constructor = $reflect->getConstructor();
        if (is_null($constructor)) {
            return new $class();
        }

        // 解析并注入构造函数依赖
        $args = $this->bindParams($constructor, $vars);
        
        return $reflect->newInstanceArgs($args);
    }

    /**
     * 解析方法参数
     * @param ReflectionMethod $reflect 方法反射对象
     * @param array $vars 手动提供的参数
     * @return array 解析后的参数数组
     * @throws Exception
     */
    protected function bindParams(ReflectionMethod $reflect, array $vars = []): array
    {
        $args = [];
        $cacheKey = $reflect->getDeclaringClass()->getName() . '::' . $reflect->getName();

        if (!isset($this->dependenciesCache[$cacheKey])) {
            $this->dependenciesCache[$cacheKey] = $reflect->getParameters();
        }

        $params = $this->dependenciesCache[$cacheKey];
        
        foreach ($params as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // 优先使用手动传入的参数
            if (array_key_exists($name, $vars)) {
                $args[] = $vars[$name];
            } 
            // 尝试通过容器解析非内置类型的参数对象
            elseif ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->make($type->getName());
            }
            // 支持PHP8联合类型，暂取首个非内置类型实例化
            elseif ($type instanceof \ReflectionUnionType) {
                $resolved = false;
                foreach ($type->getTypes() as $unionType) {
                    if ($unionType instanceof \ReflectionNamedType && !$unionType->isBuiltin()) {
                        $args[] = $this->make($unionType->getName());
                        $resolved = true;
                        break;
                    }
                }
                if (!$resolved && $param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                    $resolved = true;
                }
                if (!$resolved) {
                    throw new ContainerError("Cannot resolve union type dependency '{$name}' in " . $reflect->getDeclaringClass()->getName());
                }
            }
            // 若存在默认值则使用默认值
            elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } 
            // 无法解析时抛出异常
            else {
                throw new ContainerError("Cannot resolve dependency '{$name}' in " . $reflect->getDeclaringClass()->getName());
            }
        }

        return $args;
    }
}