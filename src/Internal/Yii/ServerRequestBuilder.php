<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Internal\Yii;

use OxPHP\Http\RequestInterface as OxRequest;
use OxPHP\Runtime\Internal\Runner\Psr7Support;
use OxPHP\Runtime\Internal\Runner\ServerParams;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * @internal Builds the PSR-7 request a Yii application receives from an OxPHP
 * request proxy, matching what `Yiisoft\Yii\Runner\Http\RequestFactory` makes
 * from the superglobals — without reading them (OxPHP may leave them empty),
 * except for uploaded files.
 */
final class ServerRequestBuilder
{
    public static function build(
        OxRequest $ox,
        ServerRequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        UploadedFileFactoryInterface $uploads,
        string $serverSoftware,
    ): ServerRequestInterface {
        $request = Psr7Support::baseRequest($ox, $requests, $streams, ServerParams::build($ox, $serverSoftware));

        // Yii's own conditions: only a POSTed form is parsed, JSON and non-POST bodies stay raw.
        if ($ox->method() === 'POST') {
            $contentType = $request->getHeaderLine('content-type');
            if (\preg_match('~^application/x-www-form-urlencoded($| |;)~', $contentType) === 1
                || \preg_match('~^multipart/form-data($| |;)~', $contentType) === 1
            ) {
                $payload = $ox->payload();
                $request = $request->withParsedBody(\is_array($payload) ? $payload : []);
            }
        }

        // $_FILES is the one superglobal the runtime reads: OxPHP\Http\UploadedFileInterface has no form field name,
        // and $_FILES is populated even with SUPERGLOBALS_ENABLED=false and restored per fiber.
        $files = [];
        foreach ($_FILES as $field => $info) {
            $files[$field] = self::uploadedFiles(
                $info['name'],
                $info['tmp_name'],
                $info['type'],
                $info['size'],
                $info['error'],
                $streams,
                $uploads,
            );
        }

        return $request->withUploadedFiles($files);
    }

    /**
     * Walks one `$_FILES` entry the way Yii's `RequestFactory` does: a leaf becomes an uploaded file, an array of
     * names becomes an array of the same keys.
     *
     * @return UploadedFileInterface|array<array-key, mixed>
     */
    private static function uploadedFiles(
        mixed $names,
        mixed $tmpNames,
        mixed $types,
        mixed $sizes,
        mixed $errors,
        StreamFactoryInterface $streams,
        UploadedFileFactoryInterface $uploads,
    ): UploadedFileInterface|array {
        if (\is_array($names)) {
            $files = [];
            foreach ($names as $key => $name) {
                $files[$key] = self::uploadedFiles(
                    $name,
                    $tmpNames[$key],
                    $types[$key],
                    $sizes[$key],
                    $errors[$key],
                    $streams,
                    $uploads,
                );
            }

            return $files;
        }

        try {
            $stream = $streams->createStreamFromFile($tmpNames);
        } catch (\RuntimeException) {
            // No file behind the entry (UPLOAD_ERR_NO_FILE and friends): an empty stream stands in for it.
            $stream = $streams->createStream();
        }

        return $uploads->createUploadedFile($stream, (int) $sizes, (int) $errors, $names, $types);
    }
}
