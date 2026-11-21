<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub;

/**
 * The uploads of one multipart form, in the $_FILES shape OxPHP builds for it
 * (captured on a real server, SUPERGLOBALS_ENABLED=false):
 *
 *   avatar        me.jpg    image/jpeg  "hello-avatar"
 *   photos[]      p1.jpg    image/jpeg  "photo-one"
 *                 p2.jpg    image/png   "photo-two"
 *   user[avatar]  me.jpg    image/jpeg  "nested-avatar"  (same client filename as avatar)
 *   deep[a][b]    deep.txt  text/plain  "deep-file"
 *   empty         no file chosen (UPLOAD_ERR_NO_FILE)
 *
 * The temporary files are real: Symfony checks that an upload's path exists.
 */
final class UploadedFilesFixture
{
    /** @var list<string> */
    private array $paths = [];

    /** @return array<string, array<string, mixed>> */
    public function form(): array
    {
        $avatar = $this->tmp('hello-avatar');
        $p1 = $this->tmp('photo-one');
        $p2 = $this->tmp('photo-two');
        $nested = $this->tmp('nested-avatar');
        $deep = $this->tmp('deep-file');

        return [
            'avatar' => [
                'name' => 'me.jpg',
                'full_path' => 'me.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $avatar,
                'error' => 0,
                'size' => 12,
            ],
            'photos' => [
                'name' => ['p1.jpg', 'p2.jpg'],
                'full_path' => ['p1.jpg', 'p2.jpg'],
                'type' => ['image/jpeg', 'image/png'],
                'tmp_name' => [$p1, $p2],
                'error' => [0, 0],
                'size' => [9, 9],
            ],
            'user' => [
                'name' => ['avatar' => 'me.jpg'],
                'full_path' => ['avatar' => 'me.jpg'],
                'type' => ['avatar' => 'image/jpeg'],
                'tmp_name' => ['avatar' => $nested],
                'error' => ['avatar' => 0],
                'size' => ['avatar' => 13],
            ],
            'deep' => [
                'name' => ['a' => ['b' => 'deep.txt']],
                'full_path' => ['a' => ['b' => 'deep.txt']],
                'type' => ['a' => ['b' => 'text/plain']],
                'tmp_name' => ['a' => ['b' => $deep]],
                'error' => ['a' => ['b' => 0]],
                'size' => ['a' => ['b' => 9]],
            ],
            'empty' => [
                'name' => '',
                'full_path' => '',
                'type' => '',
                'tmp_name' => '',
                'error' => \UPLOAD_ERR_NO_FILE,
                'size' => 0,
            ],
        ];
    }

    /**
     * A one-file form, for telling requests apart.
     *
     * @return array<string, array<string, mixed>>
     */
    public function single(string $field, string $clientName, string $contents): array
    {
        return [
            $field => [
                'name' => $clientName,
                'full_path' => $clientName,
                'type' => 'text/plain',
                'tmp_name' => $this->tmp($contents),
                'error' => 0,
                'size' => \strlen($contents),
            ],
        ];
    }

    public function cleanUp(): void
    {
        foreach ($this->paths as $path) {
            if (\is_file($path)) {
                \unlink($path);
            }
        }
        $this->paths = [];
    }

    private function tmp(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'oxup');
        if ($path === false) {
            throw new \RuntimeException('tempnam() failed');
        }
        \file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }
}
