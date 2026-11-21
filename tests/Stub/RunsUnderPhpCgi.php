<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

/**
 * Runs a scenario under php-cgi and returns the response CGI wrote.
 *
 * The CLI SAPI never stores headers (`headers_list()` is always empty), so what a
 * runner leaves in PHP's header list is only visible from another SAPI. CGI prints the
 * status and every queued header before the body. The scenario is a public static
 * method of the test class; the script that calls it loads the autoloader and
 * `tests/bootstrap.php`, so the `oxphp_*()` polyfills are there. Tests that use it are
 * skipped when php-cgi isn't installed.
 */
trait RunsUnderPhpCgi
{
    /**
     * @return array{status: int, headers: list<string>, body: string, stderr: string}
     */
    private static function runUnderPhpCgi(string $scenario): array
    {
        $cgi = self::phpCgi();

        if ($cgi === null) {
            self::markTestSkipped('php-cgi not available');
        }

        $root = \dirname(__DIR__, 2);
        $script = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-cgi-script');
        $stdout = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-cgi-out');
        $stderr = (string) \tempnam(\sys_get_temp_dir(), 'oxphp-cgi-err');

        try {
            \file_put_contents($script, \sprintf(
                "<?php\n\ndeclare(strict_types=1);\n\nrequire %s;\nrequire %s;\n\n\\%s::%s();\n",
                \var_export($root . '/vendor/autoload.php', true),
                \var_export($root . '/tests/bootstrap.php', true),
                static::class,
                $scenario,
            ));

            // A minimal environment, so no CGI variable of the parent's changes how php-cgi treats the script.
            $process = \proc_open(
                [$cgi, '-d', 'display_errors=stderr', $script],
                [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
                $pipes,
                null,
                ['PATH' => (string) \getenv('PATH')],
            );
            self::assertIsResource($process, 'php-cgi did not start');
            \fclose($pipes[0]);
            \proc_close($process);

            return self::parseCgiResponse((string) \file_get_contents($stdout)) + ['stderr' => (string) \file_get_contents($stderr)];
        } finally {
            foreach ([$script, $stdout, $stderr] as $file) {
                if (\is_file($file)) {
                    \unlink($file);
                }
            }
        }
    }

    /**
     * The values of every header named $name, in the order CGI sent them.
     *
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private static function headerValues(array $headers, string $name): array
    {
        $values = [];

        foreach ($headers as $line) {
            [$lineName, $value] = \explode(':', $line, 2) + [1 => ''];

            if (\strcasecmp(\trim($lineName), $name) === 0) {
                $values[] = \trim($value);
            }
        }

        return $values;
    }

    private static function phpCgi(): ?string
    {
        $candidates = [\dirname(\PHP_BINARY) . '/php-cgi'];

        foreach (\explode(\PATH_SEPARATOR, (string) \getenv('PATH')) as $dir) {
            if ($dir !== '') {
                $candidates[] = $dir . '/php-cgi';
            }
        }

        foreach ($candidates as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Splits CGI output into its status, its header lines (without `Status:`) and its body.
     *
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function parseCgiResponse(string $raw): array
    {
        [$head, $body] = \str_contains($raw, "\r\n\r\n") ? \explode("\r\n\r\n", $raw, 2) : [$raw, ''];
        $status = 200;
        $headers = [];

        foreach (\explode("\r\n", $head) as $line) {
            if ($line === '') {
                continue;
            }

            if (\preg_match('/^Status:\s*(\d{3})/i', $line, $m) === 1) {
                $status = (int) $m[1];

                continue;
            }

            $headers[] = $line;
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
