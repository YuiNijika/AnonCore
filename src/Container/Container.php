<?php

namespace Anon\Core\Container;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionException;
use Exception;

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
     * @var array 已经实例化的对象池 (单例)
     */
    protected array $instances = [];

    /**
     * @var array 正在构建的类栈 (用于检测循环依赖)
     */
    protected array $buildStack = [];

    /**
     * 获取当前容器的单例实例
     */
    public static function getInstance(): static
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }
        return static::$instance;
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
        return $this;
    }

    /**
     * 解析出给定标识的实例 (支持自动注入)
     * @param string $abstract 标识或类名
     * @param array $vars 手动指定的参数
     * @param bool $newInstance 是否强制创建新实例
     * @return mixed
     * @throws Exception
     */
    public function make(string $abstract, array $vars = [], bool $newInstance = false): mixed
    {
        // 1. 如果已经实例化过，且不强制创建新实例，直接返回单例
        if (isset($this->instances[$abstract]) && !$newInstance) {
            return $this->instances[$abstract];
        }

        // 检测循环依赖
        if (isset($this->buildStack[$abstract])) {
            throw new Exception("Circular dependency detected while resolving {$abstract}");
        }
        $this->buildStack[$abstract] = true;

        try {
            // 2. 获取实际应该实例化的具体内容
            $concrete = $this->bindings[$abstract] ?? $abstract;

            // 3. 如果是闭包，执行闭包并传入容器实例和参数
            if ($concrete instanceof Closure) {
                $object = $concrete($this, $vars);
            } else {
                // 4. 否则尝试利用反射 API 实例化该类
                $object = $this->invokeClass($concrete, $vars);
            }
        } finally {
            unset($this->buildStack[$abstract]);
        }

        // 5. 存入实例池
        if (!$newInstance) {
            $this->instances[$abstract] = $object;
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
            $reflect = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            throw new Exception("Class {$class} does not exist", 0, $e);
        }

        if (!$reflect->isInstantiable()) {
            throw new Exception("Class {$class} is not instantiable");
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
     * 解析方法参数 (自动装配核心逻辑)
     * @param ReflectionMethod $reflect 方法反射对象
     * @param array $vars 手动提供的参数
     * @return array 解析后的参数数组
     * @throws Exception
     */
    protected function bindParams(ReflectionMethod $reflect, array $vars = []): array
    {
        $args = [];
        $params = $reflect->getParameters();
        
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
                    throw new Exception("Cannot resolve union type dependency '{$name}' in " . $reflect->getDeclaringClass()->getName());
                }
            }
            // 若存在默认值则使用默认值
            elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } 
            // 无法解析时抛出异常
            else {
                throw new Exception("Cannot resolve dependency '{$name}' in " . $reflect->getDeclaringClass()->getName());
            }
        }

        return $args;
    }
}