<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TestRunnerTest extends TestCase
{
    public function testRunnerPreservesNpmExitStatus(): void
    {
        $directory = sys_get_temp_dir() . '/fastcrud-runner-' . bin2hex(random_bytes(6));
        mkdir($directory);
        mkdir($directory . '/uitest');
        copy(__DIR__ . '/../test.sh', $directory . '/test.sh');
        try {
            foreach ([0, 7] as $exitCode) {
                file_put_contents($directory . '/npm', "#!/bin/sh\nexit $exitCode\n");
                chmod($directory . '/npm', 0755);
                $process = proc_open(['bash', $directory . '/test.sh'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                    array_replace(getenv(), ['PATH' => $directory . PATH_SEPARATOR . getenv('PATH')]));
                self::assertIsResource($process);
                stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame($exitCode, proc_close($process), $error);
            }
        } finally {
            unlink($directory . '/npm');
            unlink($directory . '/test.sh');
            rmdir($directory . '/uitest');
            rmdir($directory);
        }
    }
}
