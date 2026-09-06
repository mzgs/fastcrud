<?php
declare(strict_types=1);

use FastCrud\Crud;
use FastCrud\CrudAssets;
use FastCrud\CrudConfig;
use FastCrud\CrudStyle;
use PHPUnit\Framework\TestCase;

final class CrudAssetsTest extends TestCase
{
    protected function tearDown(): void
    {
        CrudConfig::$script_url = null;
    }

    public function testInlineModeIncludesTheSharedRuntime(): void
    {
        CrudConfig::$script_url = null;
        $html = CrudAssets::renderInitializer(['id' => 'example']);
        self::assertStringContainsString(file_get_contents(__DIR__ . '/../assets/fastcrud.js'), $html);
        self::assertStringContainsString('"id":"example"', $html);
    }

    public function testExternalModeIsSmallVersionedAndEscapesConfiguration(): void
    {
        CrudConfig::$script_url = '/vendor/fastcrud/fastcrud.js?language=en#asset';
        $html = CrudAssets::renderInitializer(['id' => 'example', 'texts' => ['add' => '</script><script>alert(1)</script>']]);
        $version = substr(hash_file('sha256', __DIR__ . '/../assets/fastcrud.js'), 0, 16);
        self::assertStringContainsString('language=en\\u0026v=' . $version . '#asset', $html);
        self::assertStringNotContainsString('function FastCrudInit', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertSame(1, substr_count($html, '</script>'));
        self::assertLessThan(5000, strlen($html));
    }

    public function testEachTableKeepsItsTextAndIconConfiguration(): void
    {
        CrudConfig::$script_url = '/assets/fastcrud.js';
        $pdo = new PDO('sqlite::memory:');
        $renderScript = new ReflectionMethod(Crud::class, 'generateAjaxScript');
        $oldIcon = CrudStyle::$edit_action_icon;
        try {
            CrudStyle::$edit_action_icon = 'custom-edit';
            $first = $renderScript->invoke((new Crud('first', $pdo))->text('add', 'Create first'));
            $second = $renderScript->invoke((new Crud('second', $pdo))->text('add', 'Create second'));
        } finally {
            CrudStyle::$edit_action_icon = $oldIcon;
        }
        self::assertStringContainsString('Create first', $first);
        self::assertStringNotContainsString('Create second', $first);
        self::assertStringContainsString('Create second', $second);
        self::assertStringContainsString('custom-edit', $first);
        self::assertStringContainsString('custom-edit', $second);
    }

    public function testPublisherCopiesAndRefreshesTheAsset(): void
    {
        $directory = sys_get_temp_dir() . '/fastcrud-publish-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $target = $directory . '/fastcrud.js';
        try {
            file_put_contents($target, 'old version');
            $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/fastcrud-assets', $directory],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertStringContainsString('Published:', $output);
            self::assertSame(hash_file('sha256', __DIR__ . '/../assets/fastcrud.js'), hash_file('sha256', $target));
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
            rmdir($directory);
        }
    }
}
