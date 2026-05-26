<?php

namespace Anon\Core\Facade;

/**
 * @method static \Anon\Core\Action\Definition post(string $name, string $handler, array $options = [])
 * @method static \Anon\Core\Action\Definition register(string $name, string $handler, array $options = [])
 * @method static \Anon\Core\Action\Definition|null get(string $name)
 * @method static array all()
 */
class Action extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'action.registry';
    }
}