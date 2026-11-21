<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

/**
 * Puts PHP's error handling back the way a test found it, after a Yii
 * `ErrorHandler` was registered.
 *
 * `ErrorHandler::unregister()` only disables the handler: the closures `register()`
 * pushed stay on PHP's handler stacks, so PHPUnit would report the test as risky.
 * `restore()` pops them until the captured handlers are current, and puts back the
 * `display_errors` setting `register()` turns off.
 */
final class ErrorHandlerStack
{
    private const int MAX_POPS = 10;

    private function __construct(
        private readonly mixed $errorHandler,
        private readonly mixed $exceptionHandler,
        private readonly string|false $displayErrors,
    ) {}

    public static function capture(): self
    {
        return new self(self::currentErrorHandler(), self::currentExceptionHandler(), \ini_get('display_errors'));
    }

    public function restore(): void
    {
        for ($pops = 0; self::currentErrorHandler() !== $this->errorHandler; $pops++) {
            if ($pops === self::MAX_POPS) {
                throw new \LogicException('The captured error handler is no longer on the stack.');
            }
            \restore_error_handler();
        }

        for ($pops = 0; self::currentExceptionHandler() !== $this->exceptionHandler; $pops++) {
            if ($pops === self::MAX_POPS) {
                throw new \LogicException('The captured exception handler is no longer on the stack.');
            }
            \restore_exception_handler();
        }

        if ($this->displayErrors !== false) {
            \ini_set('display_errors', $this->displayErrors);
        }
    }

    private static function currentErrorHandler(): mixed
    {
        $handler = \set_error_handler(null);
        \restore_error_handler();

        return $handler;
    }

    private static function currentExceptionHandler(): mixed
    {
        $handler = \set_exception_handler(null);
        \restore_exception_handler();

        return $handler;
    }
}
