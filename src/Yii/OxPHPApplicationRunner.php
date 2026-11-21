<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Yii;

use OxPHP\Runtime\Internal\Bridge\OxPHP;
use OxPHP\Runtime\Internal\Yii\ContainerBlueprint;
use OxPHP\Runtime\Internal\Yii\SharedServices;
use OxPHP\Runtime\Internal\Yii\YiiRunner;
use Psr\Log\NullLogger;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\HtmlRenderer;
use Yiisoft\PsrEmitter\EmitterInterface;
use Yiisoft\Yii\Event\ListenerConfigurationChecker;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Runner\ApplicationRunner;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

/**
 * Runs a Yii 3 web application on OxPHP, serving every request from a container of
 * its own. In `yiisoft/app`'s `public/index.php`, it takes the place of
 * `HttpApplicationRunner`, with the same arguments:
 *
 * ```php
 * $runner = new OxPHPApplicationRunner(
 *     rootPath: $root,
 *     debug: Environment::appDebug(),
 *     checkEvents: Environment::appDebug(),
 *     environment: Environment::appEnv(),
 *     temporaryErrorHandler: new ErrorHandler(…),
 *     sharedServices: [ConnectionInterface::class, CacheInterface::class],
 * );
 * $runner->run();
 * ```
 *
 * Off the OxPHP SAPI, `run()` is `HttpApplicationRunner::run()`, built from the same
 * arguments. Under OxPHP, the services in `$sharedServices` are built once per
 * worker and every other service once per request. See "Yii 3" in the README for
 * the rules a shared service must follow.
 */
final class OxPHPApplicationRunner extends ApplicationRunner
{
    /**
     * @param string $rootPath the absolute path to the project root
     * @param bool $debug whether the debug mode is enabled
     * @param bool $checkEvents whether to check the events' configuration
     * @param string|null $environment the environment name
     * @param string $bootstrapGroup the bootstrap configuration group name
     * @param string $eventsGroup the events' configuration group name
     * @param string $diGroup the container definitions' configuration group name
     * @param string $diProvidersGroup the container providers' configuration group name
     * @param string $diDelegatesGroup the container delegates' configuration group name
     * @param string $diTagsGroup the container tags' configuration group name
     * @param string $paramsGroup the configuration parameters group name
     * @param list<string> $nestedParamsGroups configuration group names included into the parameters group
     * @param list<string> $nestedEventsGroups configuration group names included into the events' group
     * @param list<object> $configModifiers modifiers for the config
     * @param string $configDirectory the path from $rootPath to the configuration storage
     * @param string $vendorDirectory the path from $rootPath to the vendor directory
     * @param string $configMergePlanFile the path from $configDirectory to the merge plan
     * @param ErrorHandler|null $temporaryErrorHandler handles errors until the application's error handler is built
     * @param EmitterInterface|null $emitter sends the response; under OxPHP, null means the runtime's own emitter
     * @param list<string> $sharedServices ids of the services built once per worker instead of once per request
     */
    public function __construct(
        string $rootPath,
        bool $debug = false,
        bool $checkEvents = false,
        ?string $environment = null,
        string $bootstrapGroup = 'bootstrap-web',
        string $eventsGroup = 'events-web',
        string $diGroup = 'di-web',
        string $diProvidersGroup = 'di-providers-web',
        string $diDelegatesGroup = 'di-delegates-web',
        string $diTagsGroup = 'di-tags-web',
        string $paramsGroup = 'params-web',
        array $nestedParamsGroups = ['params'],
        array $nestedEventsGroups = ['events'],
        array $configModifiers = [],
        string $configDirectory = 'config',
        string $vendorDirectory = 'vendor',
        string $configMergePlanFile = '.merge-plan.php',
        private ?ErrorHandler $temporaryErrorHandler = null,
        private readonly ?EmitterInterface $emitter = null,
        private readonly array $sharedServices = [],
    ) {
        parent::__construct(
            $rootPath,
            $debug,
            $checkEvents,
            $environment,
            $bootstrapGroup,
            $eventsGroup,
            $diGroup,
            $diProvidersGroup,
            $diDelegatesGroup,
            $diTagsGroup,
            $paramsGroup,
            $nestedParamsGroups,
            $nestedEventsGroups,
            $configModifiers,
            $configDirectory,
            $vendorDirectory,
            $configMergePlanFile,
        );
    }

    /**
     * Returns a new instance with the specified temporary error handler, which
     * handles errors until the application's error handler is built.
     */
    public function withTemporaryErrorHandler(ErrorHandler $temporaryErrorHandler): self
    {
        $new = clone $this;
        $new->temporaryErrorHandler = $temporaryErrorHandler;

        return $new;
    }

    public function run(): void
    {
        // The check `Runtime::getRunner()` makes; no oxphp_*() call may come before it.
        if (\PHP_SAPI === 'cli' || !\function_exists('oxphp_server_info')) {
            $this->runFallback();

            return;
        }

        $this->runUnderOxPHP();
    }

    private function runFallback(): void
    {
        if (!\class_exists(HttpApplicationRunner::class)) {
            throw new \LogicException('OxPHPApplicationRunner needs yiisoft/yii-runner-http to run outside the OxPHP SAPI. Install it with "composer require yiisoft/yii-runner-http".');
        }

        $runner = new HttpApplicationRunner(
            rootPath: $this->rootPath,
            debug: $this->debug,
            checkEvents: $this->checkEvents,
            environment: $this->environment,
            bootstrapGroup: $this->bootstrapGroup,
            eventsGroup: $this->eventsGroup,
            diGroup: $this->diGroup,
            diProvidersGroup: $this->diProvidersGroup,
            diDelegatesGroup: $this->diDelegatesGroup,
            diTagsGroup: $this->diTagsGroup,
            paramsGroup: $this->paramsGroup,
            nestedParamsGroups: $this->nestedParamsGroups,
            nestedEventsGroups: $this->nestedEventsGroups,
            configModifiers: $this->configModifiers,
            configDirectory: $this->configDirectory,
            vendorDirectory: $this->vendorDirectory,
            configMergePlanFile: $this->configMergePlanFile,
            temporaryErrorHandler: $this->temporaryErrorHandler,
            emitter: $this->emitter,
        );

        $config = $this->parentProperty('config');
        if ($config !== null) {
            $runner = $runner->withConfig($config);
        }

        $container = $this->parentProperty('container');
        if ($container !== null) {
            $runner = $runner->withContainer($container);
        }

        $runner->run();
    }

    private function runUnderOxPHP(): void
    {
        if ($this->parentProperty('container') !== null) {
            throw new \LogicException('OxPHPApplicationRunner builds a new container for every request from the config, so withContainer() cannot be used under OxPHP. Use withConfig() instead.');
        }

        // Catches errors while the config and the containers are built, as in `HttpApplicationRunner`.
        $temporaryErrorHandler = $this->temporaryErrorHandler ?? new ErrorHandler(new NullLogger(), new HtmlRenderer());
        $this->registerErrorHandler($temporaryErrorHandler);

        $blueprint = ContainerBlueprint::fromConfig(
            $this->getConfig(),
            $this->diGroup,
            $this->diProvidersGroup,
            $this->diDelegatesGroup,
            $this->diTagsGroup,
        );
        // Builds and guards the listed services now, so a misused list fails the worker at boot.
        $blueprint = $blueprint->withSharedInstances(SharedServices::resolve($blueprint, $this->sharedServices, $this->debug));

        // Serves the worker-wide work: the error handler, the events check, start() and shutdown().
        // Its non-shared services are never seen by a request.
        $boot = $blueprint->createRequestContainer($this->debug);

        /** @var ErrorHandler $errorHandler */
        $errorHandler = $boot->get(ErrorHandler::class);
        $this->registerErrorHandler($errorHandler, $temporaryErrorHandler);

        // Not the parent's checkEvents(): its getContainer() would build one more container.
        if ($this->checkEvents && null !== $events = $this->getConfiguration($this->eventsGroup)) {
            $boot->get(ListenerConfigurationChecker::class)->check($events);
        }

        /** @var Application $application */
        $application = $boot->get(Application::class);
        $application->start();

        try {
            new YiiRunner(
                new OxPHP(),
                $blueprint,
                $this->getConfiguration($this->bootstrapGroup) ?? [],
                $this->emitter,
            )->run();
        } finally {
            $application->shutdown();
            $errorHandler->unregister();
        }
    }

    private function registerErrorHandler(ErrorHandler $registered, ?ErrorHandler $unregistered = null): void
    {
        $unregistered?->unregister();

        if ($this->debug) {
            $registered->debug();
        }

        $registered->register();
    }

    /**
     * Reads `ApplicationRunner`'s private `config` or `container`, which it sets only
     * through `withConfig()`, `withContainer()` and its getters.
     */
    private function parentProperty(string $name): mixed
    {
        return new \ReflectionProperty(ApplicationRunner::class, $name)->getValue($this);
    }
}
