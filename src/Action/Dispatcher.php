<?php

namespace Anon\Core\Action;

use Anon\Core\Exception\Http;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Session;
use Anon\Core\Foundation\App;
use Anon\Core\Http\FormRequest;
use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Routing\Router;
use ReflectionMethod;
use ReflectionNamedType;

class Dispatcher
{
    public function __construct(
        protected Registry $registry
    ) {
    }

    public function handle(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            throw new Http(405, 'Server actions only accept POST requests.', [], null, 'METHOD_NOT_ALLOWED');
        }

        $name = (string) $request->route('action', '');
        $definition = $this->registry->require($name);
        $action = $this->makeAction($definition);

        $this->verifyCsrf($request);

        $middlewares = array_merge(
            $this->globalMiddlewares(),
            $definition->middlewares(),
            $this->actionMiddlewares($action)
        );

        /** @var Router $router */
        $router = App::getInstance()->make('router');

        return $router->runMiddlewareStack($middlewares, $request, function (Request $request) use ($definition, $action) {
            return $this->execute($definition, $action, $request);
        });
    }

    protected function execute(Definition $definition, object $action, Request $request): Response
    {
        $app = App::getInstance();

        $requestForAction = $this->resolveActionRequest($action, $request);
        $this->authorize($action, $requestForAction);

        $reflect = new ReflectionMethod($action, 'handle');
        $args = $this->resolveHandleArguments($reflect, $requestForAction, $app);
        $result = $reflect->invokeArgs($action, $args);

        if ($result instanceof Response) {
            return $result;
        }

        return Response::success(
            $result,
            $this->actionMessage($action, $result, $requestForAction),
            $this->actionStatusCode($action, $result, $requestForAction),
            $this->actionCode($action, $result, $requestForAction),
            $this->actionMeta($action, $result, $requestForAction),
            $this->actionLinks($action, $result, $requestForAction)
        );
    }

    protected function makeAction(Definition $definition): object
    {
        $app = App::getInstance();
        $handlerClass = $definition->handler();

        $action = $app->make($handlerClass, [], true);
        if (!is_object($action) || !method_exists($action, 'handle')) {
            throw new Http(500, "Action handler is missing handle method: {$handlerClass}");
        }

        return $action;
    }

    /**
     * @return array<int, string>
     */
    protected function actionMiddlewares(object $action): array
    {
        if (!method_exists($action, 'middleware')) {
            return [];
        }

        $middlewares = $action->middleware();
        if (is_string($middlewares)) {
            return [$middlewares];
        }

        if (!is_array($middlewares)) {
            return [];
        }

        return array_values(array_map('strval', $middlewares));
    }

    protected function authorize(object $action, Request $request): void
    {
        if (!method_exists($action, 'authorize')) {
            return;
        }

        if ($action->authorize($request) !== true) {
            throw Http::forbidden('This action is not allowed.');
        }
    }

    protected function actionCode(object $action, mixed $result, Request $request): string
    {
        if (!method_exists($action, 'code')) {
            return 'OK';
        }

        $code = $action->code($result, $request);

        return is_string($code) && $code !== '' ? $code : 'OK';
    }

    protected function actionMessage(object $action, mixed $result, Request $request): string
    {
        if (!method_exists($action, 'message')) {
            return 'OK';
        }

        $message = $action->message($result, $request);

        return is_string($message) && $message !== '' ? $message : 'OK';
    }

    protected function actionStatusCode(object $action, mixed $result, Request $request): int
    {
        if (!method_exists($action, 'statusCode')) {
            return 200;
        }

        $statusCode = (int) $action->statusCode($result, $request);

        return $statusCode >= 100 && $statusCode <= 599 ? $statusCode : 200;
    }

    /**
     * @return array<string, mixed>
     */
    protected function actionMeta(object $action, mixed $result, Request $request): array
    {
        if (!method_exists($action, 'meta')) {
            return [];
        }

        $meta = $action->meta($result, $request);

        return is_array($meta) ? $meta : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function actionLinks(object $action, mixed $result, Request $request): array
    {
        if (!method_exists($action, 'links')) {
            return [];
        }

        $links = $action->links($result, $request);

        return is_array($links) ? $links : [];
    }

    protected function resolveActionRequest(object $action, Request $request): Request
    {
        if (!method_exists($action, 'request')) {
            return $request;
        }

        $requestClass = $action->request();
        if ($requestClass === null || $requestClass === '') {
            return $request;
        }

        if (!is_string($requestClass) || !class_exists($requestClass)) {
            throw new Http(500, 'Action request class does not exist.');
        }

        if (!is_a($requestClass, Request::class, true)) {
            throw new Http(500, 'Action request class must extend Request.');
        }

        /** @var Request $actionRequest */
        $actionRequest = App::getInstance()->make($requestClass, [], true);
        $actionRequest->cloneFrom($request);

        if ($actionRequest instanceof FormRequest) {
            $actionRequest->validateResolved();
        }

        return $actionRequest;
    }

    /**
     * @return array<int, mixed>
     */
    protected function resolveHandleArguments(ReflectionMethod $reflect, Request $request, App $app): array
    {
        $args = [];
        $routeParams = $request->getRouteParams();

        foreach ($reflect->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $className = $type->getName();

                if (is_a($className, Request::class, true)) {
                    if ($request instanceof $className) {
                        $args[] = $request;
                        continue;
                    }

                    /** @var Request $typedRequest */
                    $typedRequest = $app->make($className, [], true);
                    $typedRequest->cloneFrom($request);
                    if ($typedRequest instanceof FormRequest) {
                        $typedRequest->validateResolved();
                    }
                    $args[] = $typedRequest;
                    continue;
                }

                $args[] = $app->make($className);
                continue;
            }

            if (array_key_exists($name, $routeParams) && is_scalar($routeParams[$name])) {
                $args[] = $routeParams[$name];
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            $args[] = null;
        }

        return $args;
    }

    protected function verifyCsrf(Request $request): void
    {
        if (!(bool) Config::get('actions.csrf', true)) {
            return;
        }

        $expected = (string) Session::get('_token', '');
        $given = (string) ($request->header('X-CSRF-TOKEN', '') ?: $request->input('_token', ''));

        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw new Http(419, 'CSRF token mismatch.', [], null, 'CSRF_TOKEN_MISMATCH');
        }
    }

    /**
     * @return string[]
     */
    protected function globalMiddlewares(): array
    {
        $middlewares = Config::get('actions.middleware', ['throttle:60,1']);

        if (is_string($middlewares)) {
            return [$middlewares];
        }

        if (!is_array($middlewares)) {
            return [];
        }

        return array_values(array_map('strval', $middlewares));
    }
}