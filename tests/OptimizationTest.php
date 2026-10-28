<?php
declare(strict_types=1);

use FastCrud\Crud;
use FastCrud\CrudAjax;
use FastCrud\Database;
use PHPUnit\Framework\TestCase;

final class OptimizationPDO extends PDO
{
    public array $queries = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)');
        $this->exec("WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n<10000)
            INSERT INTO people SELECT n, 'Person ' || n, n % 2 FROM seq");
        $this->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, owner INTEGER, name TEXT)');
        $this->exec("WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n<1201)
            INSERT INTO items SELECT n, 1, 'Item ' || n FROM seq");
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return parent::prepare($query, $options);
    }
}

final class OptimizationTest extends TestCase
{
    private OptimizationPDO $pdo;
    private static int $processed = 0;
    private static int $fetches = 0;

    protected function setUp(): void
    {
        Database::clearSchemaCache();
        $this->pdo = new OptimizationPDO();
        self::$processed = self::$fetches = 0;
    }

    public static function computed(array $row): string
    {
        self::$processed++;
        return 'Computed ' . $row['name'];
    }

    public static function afterFetch(array $data): array
    {
        self::$fetches++;
        $data['rows'] = array_reverse($data['rows']);
        return $data;
    }

    public function testTableAndBootstrapDoNotLoadCompleteRelationLists(): void
    {
        $crud = (new Crud('items', $this->pdo))->relation('owner', 'people', 'id', 'name');
        $bootstrap = (new ReflectionMethod(Crud::class, 'buildClientConfigPayload'))->invoke($crud);
        $data = $crud->getTableData(1, 10);
        self::assertSame(['relation_field' => 'owner'], $data['meta']['form']['behaviours']['change_type']['owner']['params']);
        self::assertSame('owner', $bootstrap['form']['behaviours']['change_type']['owner']['params']['relation_field']);
        self::assertSame('owner', $data['meta']['query_builder']['fields'][1]['relation_field']);
        self::assertCount(10, $data['rows']);
        self::assertSame('Person 1', $data['rows'][0]['owner']);
        $related = array_values(array_filter($this->pdo->queries, static fn($sql) => str_contains($sql, 'FROM people')));
        self::assertCount(1, $related);
        self::assertStringContainsString('WHERE id IN', $related[0]);
    }

    public function testRelationChoicesArePagedSearchableAndRespectFilters(): void
    {
        $crud = (new Crud('items', $this->pdo))->relation('owner', 'people', 'id', 'name', ['active' => 1], 'id ASC');
        $first = $crud->getRelationOptions('owner');
        $second = $crud->getRelationOptions('owner', '', 2);
        self::assertCount(50, $first['results']);
        self::assertTrue($first['pagination']['more']);
        self::assertSame('1', $first['results'][0]['id']);
        self::assertSame('101', $second['results'][0]['id']);
        $found = $crud->getRelationOptions('owner', 'Person 9999');
        self::assertSame([['id' => '9999', 'text' => 'Person 9999']], $found['results']);
        self::assertFalse($found['pagination']['more']);
        self::assertSame([], $crud->getRelationOptions('owner', 'Person 10000')['results']);
        self::assertSame([['id' => '9999', 'text' => 'Person 9999']], $crud->getRelationOptions('owner', '', 1, [9999, 10000])['results']);
    }

    public function testUnknownRelationFieldsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Crud('items', $this->pdo))->getRelationOptions('unknown');
    }

    public function testSelectedExportFiltersInSqlAndPreservesSelectionOrderAndCallbacks(): void
    {
        $crud = (new Crud('items', $this->pdo))->where('id > 1')->or_where('id = 1')
            ->columns(['id', 'computed'])->custom_column('computed', [self::class, 'computed']);
        $batches = iterator_to_array($crud->getExportBatches(null, null, [1201, 2, 1201, 9999]));
        self::assertSame([1201, 2, 1201], array_column($batches[0]['rows'], 'id'));
        self::assertSame('Computed Item 1201', $batches[0]['rows'][0]['computed']);
        self::assertSame(2, self::$processed);
        self::assertCount(1, $this->pdo->queries);
        self::assertStringContainsString(') AND "main"."id" IN (:export_pk_', $this->pdo->queries[0]);
        self::assertStringNotContainsString('1201', $this->pdo->queries[0]);
        self::assertCount(10, $crud->getTableData(1, 10)['rows']);
    }

    public function testExportBatchesStayBoundedAndPreserveSearchRelationsAndColumnVisibility(): void
    {
        $crud = (new Crud('items', $this->pdo))->relation('owner', 'people', 'id', 'name')
            ->columns(['id', 'owner', 'computed'])->custom_column('computed', [self::class, 'computed'])
            ->set_column_labels('owner', 'Person')->search_columns(['name'])->order_by('id', 'desc');
        $batches = iterator_to_array($crud->getExportBatches());
        self::assertSame([500, 500, 201], array_map(static fn($batch) => count($batch['rows']), $batches));
        self::assertSame(1201, $batches[0]['rows'][0]['id']);
        self::assertSame(1, $batches[2]['rows'][200]['id']);
        self::assertSame(['id', 'owner', 'computed'], $batches[0]['columns']);
        self::assertSame(['owner' => 'Person'], $batches[0]['labels']);
        self::assertSame('Person 1', $batches[0]['rows'][0]['owner']);
        self::assertSame(1201, self::$processed);
        $searched = iterator_to_array($crud->getExportBatches('Item 1201', 'name'));
        self::assertCount(1, $searched[0]['rows']);
        $empty = iterator_to_array($crud->getExportBatches('missing', 'name'));
        self::assertSame([], $empty[0]['rows']);
        self::assertSame(['id', 'owner', 'computed'], $empty[0]['columns']);
    }

    public function testLargeSelectionsAreChunkedAndWholeDatasetCallbacksRunOnce(): void
    {
        $crud = (new Crud('items', $this->pdo))->order_by('id');
        $batches = iterator_to_array($crud->getExportBatches(null, null, range(1201, 1)));
        self::assertSame(range(1201, 1), array_merge(...array_map(static fn($batch) => array_column($batch['rows'], 'id'), $batches)));
        foreach ($this->pdo->queries as $query) {
            self::assertLessThanOrEqual(500, substr_count($query, ':export_pk_'));
        }
        $crud->after_fetch([self::class, 'afterFetch']);
        $batches = iterator_to_array($crud->getExportBatches());
        self::assertSame(1, self::$fetches);
        self::assertCount(1, $batches);
        self::assertSame(1201, $batches[0]['rows'][0]['id']);
    }

    public function testQueryBuilderFiltersApplyToTablesAndExportsWithoutOtherWhereConditions(): void
    {
        $crud = Crud::fromAjaxRequest('items', null, ['config_state' => [
            'query_builder' => ['filters' => [['field' => 'id', 'operator' => 'gt', 'value' => 1200]]],
        ]], $this->pdo);
        self::assertSame([1201], array_column($crud->getTableData(1, 10)['rows'], 'id'));
        $batches = iterator_to_array($crud->getExportBatches());
        self::assertSame([1201], array_column($batches[0]['rows'], 'id'));
    }

    public function testCustomRecordKeysAndUntrustedSelectionValues(): void
    {
        $this->pdo->exec('CREATE TABLE codes (code TEXT PRIMARY KEY, name TEXT)');
        $this->pdo->exec("INSERT INTO codes VALUES ('a', 'Alpha'), ('b', 'Beta')");
        $crud = (new Crud('codes', $this->pdo))->primary_key('code');
        $batches = iterator_to_array($crud->getExportBatches(null, null, ['b', "a' OR 1=1 --"]));
        self::assertSame(['b'], array_column($batches[0]['rows'], 'code'));
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array($crud->getExportBatches(null, null, [['a']]));
    }

    public function testRelationSearchMatchesCombinedLabelsAndRejectsNestedValues(): void
    {
        $crud = (new Crud('items', $this->pdo))->relation('owner', 'people', 'id', ['name', 'id']);
        self::assertSame([['id' => '1201', 'text' => 'Person 1201 1201']],
            $crud->getRelationOptions('owner', 'Person 1201 1201')['results']);
        $this->expectException(InvalidArgumentException::class);
        $crud->getRelationOptions('owner', '', 1, [[1]]);
    }

    public function testCsvOutputLargerThanTheMemoryLimitSpoolsToDisk(): void
    {
        $script = <<<'CODE'
require $argv[1];
$stream = fopen('php://temp/maxmemory:2097152', 'w+');
$batches = (function () {
    for ($i = 0; $i < 5000; $i++) {
        yield ['columns' => ['name'], 'labels' => [], 'rows' => [['name' => str_repeat('x', 4096)]]];
    }
})();
(new ReflectionMethod(FastCrud\CrudAjax::class, 'writeCsv'))->invoke(null, $stream, $batches);
echo fstat($stream)['size'];
fclose($stream);
CODE;
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=16M', '-r', $script, __DIR__ . '/../vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertGreaterThan(20_000_000, (int) $output);
    }

    public function testCsvStreamPreservesHeadersQuotingUnicodeAndEmptyResults(): void
    {
        $write = new ReflectionMethod(CrudAjax::class, 'writeCsv');
        $stream = fopen('php://temp', 'w+');
        try {
            $write->invoke(null, $stream, [['columns' => ['name', 'notes'], 'labels' => ['name' => 'Label'],
                'rows' => [['name' => 'İstanbul, "hello"', 'notes' => null]]],
                ['columns' => ['name', 'notes'], 'labels' => [], 'rows' => [['name' => 'second', 'notes' => ['x' => 1]]]]]);
            rewind($stream);
            self::assertSame("\xEF\xBB\xBF", fread($stream, 3));
            self::assertSame(['Label', 'notes'], fgetcsv($stream, null, ',', '"', '\\'));
            self::assertSame(['İstanbul, "hello"', ''], fgetcsv($stream, null, ',', '"', '\\'));
            self::assertSame(['second', '{"x":1}'], fgetcsv($stream, null, ',', '"', '\\'));
            self::assertFalse(fgetcsv($stream, null, ',', '"', '\\'));
        } finally {
            fclose($stream);
        }
    }
}
