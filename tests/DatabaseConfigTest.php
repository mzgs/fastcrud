<?php
declare(strict_types=1);

use FastCrud\CrudConfig;
use FastCrud\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    public function testDriverDefaultPortsAndExplicitPortOverrides(): void
    {
        $previous = CrudConfig::getDbConfig();
        $buildDsn = new ReflectionMethod(Database::class, 'buildDsn');
        try {
            foreach ([['pgsql', null, 5432], ['mysql', null, 3306], ['pgsql', 6543, 6543]] as [$driver, $port, $expected]) {
                $config = ['driver' => $driver, 'database' => 'example'];
                if ($port !== null) {
                    $config['port'] = $port;
                }
                CrudConfig::setDbConfig($config);
                self::assertStringContainsString(';port=' . $expected . ';', $buildDsn->invoke(null, CrudConfig::getDbConfig()));
            }
        } finally {
            CrudConfig::setDbConfig($previous);
        }
    }
}
