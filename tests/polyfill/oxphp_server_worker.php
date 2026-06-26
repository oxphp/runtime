<?php

declare(strict_types=1);

/**
 * Polyfill for the OxPHP\Server\Worker class provided by the oxphp_sapi PHP extension.
 *
 * Loaded only when the real extension is not present. The single source of truth
 * for these signatures is oxphp.stub.php in the oxphp server repository — keep
 * both files in sync. Only the members the runtime relies on are modelled:
 * current(), isWorkerMode(), serve(). Introspection accessors (id, requestCount,
 * rss, …) are intentionally omitted (YAGNI).
 */

namespace OxPHP\Server {

    use OxPHP\Runtime\Tests\Stub\OxPHPHarness;
    use OxPHP\Server\Exception\InvalidServeContextException;

    final class Worker
    {
        private static ?self $instance = null;

        public static function current(): self
        {
            return self::$instance ??= new self();
        }

        public static function isWorkerMode(): bool
        {
            return OxPHPHarness::instance()->isWorker();
        }

        public function serve(callable $handler): void
        {
            if (!OxPHPHarness::instance()->isWorker()) {
                throw new InvalidServeContextException('serve() called outside worker mode');
            }

            OxPHPHarness::instance()->driveWorker($handler);
        }
    }
}

namespace OxPHP\Server\Exception {

    final class InvalidServeContextException extends \RuntimeException {}
}
