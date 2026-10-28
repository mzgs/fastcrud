<?php
declare(strict_types=1);

use FastCrud\Crud;
use FastCrud\CrudAssets;
use FastCrud\CrudConfig;
use FastCrud\CrudStyle;
use PHPUnit\Framework\TestCase;

final class CrudAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        CrudAssets::$runtimeEndpointEnabled = false;
        (new ReflectionProperty(CrudAssets::class, 'inlineRuntimeRendered'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        CrudConfig::$script_url = null;
        CrudAssets::$runtimeEndpointEnabled = false;
        (new ReflectionProperty(CrudAssets::class, 'inlineRuntimeRendered'))->setValue(null, false);
    }

    public function testInlineFallbackIncludesRuntimeOnlyOncePerResponse(): void
    {
        CrudConfig::$script_url = '';
        $first = CrudAssets::renderInitializer(['id' => 'first']);
        $second = CrudAssets::renderInitializer(['id' => 'second']);
        self::assertSame(1, substr_count($first . $second, 'function FastCrudInit'));
        self::assertLessThan(2000, strlen($second));
        self::assertStringContainsString('"id":"second"', $second);
    }

    public function testInitializedPagesUseTheVersionedRuntimeEndpointByDefault(): void
    {
        CrudAssets::$runtimeEndpointEnabled = true;
        CrudConfig::$script_url = null;
        $html = CrudAssets::renderInitializer(['id' => 'example']);
        self::assertStringContainsString('action=runtime', $html);
        self::assertStringContainsString('v=' . CrudAssets::version(), $html);
        self::assertStringNotContainsString('function FastCrudInit', $html);
        self::assertLessThan(2000, strlen($html));
    }

    public function testRuntimeEndpointServesTheAssetWithoutADatabase(): void
    {
        $script = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ';'
            . '$_GET = ["fastcrud_ajax" => "1", "action" => "runtime"]; FastCrud\\Crud::init();';
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame(file_get_contents(__DIR__ . '/../assets/fastcrud.js'), $output);
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

    public function testBatchSelectionSupportsShiftClickRanges(): void
    {
        $script = file_get_contents(__DIR__ . '/../assets/fastcrud.js');

        self::assertStringContainsString('function selectRowRange(checkbox, selected)', $script);
        self::assertStringContainsString("table.on('click', '.fastcrud-select-row'", $script);
        self::assertStringContainsString('if (event.shiftKey)', $script);
        self::assertStringContainsString('applyRowSelection($(this), selected);', $script);
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
