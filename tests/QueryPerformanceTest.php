<?php
declare(strict_types=1);

use FastCrud\Crud;
use FastCrud\CrudAjax;
use FastCrud\Database;
use PHPUnit\Framework\TestCase;

final class PerformancePDO extends PDO
{
    public array $queries = [];
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, amount REAL, owner INTEGER, reviewer INTEGER)');
        $this->exec("INSERT INTO items VALUES (1,'Alpha',10,1,2),(2,'Beta',20,2,1),(3,'Gamma',30,1,1)");
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return parent::prepare($query, $options);
    }
}

final class QueryPerformanceTest extends TestCase
{
    protected function setUp(): void { Database::clearSchemaCache(); }

    private function countQueries(PerformancePDO $pdo): array
    {
        return array_values(array_filter($pdo->queries, static fn($sql) => str_starts_with($sql, 'SELECT COUNT(')));
    }

    public function testShortFirstPageAndAllRowsAvoidCountWithoutChangingTotals(): void
    {
        $pdo = new PerformancePDO();
        $crud = new Crud('items', $pdo);
        $result = $crud->getTableData(1, 10);
        self::assertSame(3, $result['pagination']['total_rows']);
        self::assertCount(3, $result['rows']);
        self::assertSame([], $this->countQueries($pdo));
        $result = $crud->getTableData(50, 0);
        self::assertSame(1, $result['pagination']['current_page']);
        self::assertSame(3, $result['pagination']['total_rows']);
        self::assertSame([], $this->countQueries($pdo));
        $empty = $crud->getTableData(1, 10, 'missing', 'name');
        self::assertSame(0, $empty['pagination']['total_rows']);
        self::assertSame([], $this->countQueries($pdo));
    }

    public function testFullPagesAndOutOfRangePagesKeepAccuratePagination(): void
    {
        $pdo = new PerformancePDO();
        $crud = new Crud('items', $pdo);
        $first = $crud->getTableData(1, 2);
        self::assertSame(2, $first['pagination']['total_pages']);
        self::assertCount(1, $this->countQueries($pdo));
        $last = $crud->getTableData(50, 2);
        self::assertSame(2, $last['pagination']['current_page']);
        self::assertCount(1, $last['rows']);
        self::assertSame(3, $last['pagination']['total_rows']);
    }

    public function testSummariesShareOneAggregateQueryAndPreservePrecision(): void
    {
        $pdo = new PerformancePDO();
        $crud = (new Crud('items', $pdo))->column_summary('amount', 'sum', 'Total', 2)
            ->column_summary('amount', 'avg', 'Average', 1);
        $result = $crud->getTableData(1, 10);
        self::assertSame(['60.00', '20.0'], array_column($result['meta']['summaries'], 'value'));
        self::assertCount(1, array_filter($pdo->queries, static fn($sql) => str_starts_with($sql, 'SELECT SUM(')));
        self::assertCount(0, array_filter($pdo->queries, static fn($sql) => str_starts_with($sql, 'SELECT AVG(')));
        $crud->column_summary('missing', 'sum');
        $result = $crud->getTableData(1, 10);
        self::assertSame(['60.00', '20.0'], array_column($result['meta']['summaries'], 'value'));
    }

    public function testEquivalentRelationsShareLabelsAndLargeLookupsAreChunked(): void
    {
        $pdo = new PerformancePDO();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO people VALUES (1,'One'), (2,'Two')");
        $crud = (new Crud('items', $pdo))->relation('owner', 'people', 'id', 'name')
            ->relation('reviewer', 'people', 'id', 'name');
        $method = new ReflectionMethod($crud, 'applyRelations');
        $rows = $method->invoke($crud, [['owner' => 1, 'reviewer' => 2], ['owner' => 2, 'reviewer' => 1]]);
        self::assertCount(1, $pdo->queries);
        self::assertSame('One', $rows[0]['owner']);
        self::assertSame('Two', $rows[0]['reviewer']);
        self::assertSame(1, $rows[0]['__fastcrud_raw']['owner']);
        $pdo->queries = [];
        $rows = array_map(static fn($i) => ['owner' => $i, 'reviewer' => $i], range(1, 1200));
        $method->invoke($crud, $rows);
        self::assertCount(3, $pdo->queries);
        foreach ($pdo->queries as $sql) { self::assertLessThanOrEqual(500, substr_count($sql, ':rel_')); }
    }

    public function testMetadataReuseKeepsSummariesFreshAndDetectsChangedPermissions(): void
    {
        $format = new ReflectionMethod(CrudAjax::class, 'buildFetchResponse');
        $data = ['rows' => [['id' => 1]], 'columns' => ['id'], 'pagination' => [],
            'meta' => ['form' => ['editable' => true], 'summaries' => [['value' => 10]]]];
        $first = $format->invoke(null, $data, []);
        self::assertSame($data['meta'], $first['meta']);
        $data['meta']['summaries'][0]['value'] = 20;
        $second = $format->invoke(null, $data, ['meta_hash' => $first['meta_hash']]);
        self::assertNull($second['meta']);
        self::assertSame(20, $second['summaries'][0]['value']);
        self::assertSame($data['rows'], $second['data']);
        $data['meta']['form']['editable'] = false;
        $third = $format->invoke(null, $data, ['meta_hash' => $first['meta_hash']]);
        self::assertSame(false, $third['meta']['form']['editable']);
    }

    public function testHeaderDiscoveryDoesNotEvaluateRowExpressions(): void
    {
        $pdo = class_exists(Pdo\Sqlite::class)
            ? new Pdo\Sqlite('sqlite::memory:')
            : new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE items AS SELECT 1 AS id');
        $calls = 0;
        $callback = function() use (&$calls) { $calls++; return 1; };
        if ($pdo instanceof Pdo\Sqlite) {
            $pdo->createFunction('expensive', $callback);
        } else {
            $pdo->sqliteCreateFunction('expensive', $callback);
        }
        $pdo->exec('CREATE VIEW calculated AS SELECT id, expensive() AS calculated FROM items');
        $crud = new Crud('calculated', $pdo);
        $columns = (new ReflectionMethod($crud, 'getColumnNames'))->invoke($crud);
        self::assertSame(['id', 'calculated'], $columns);
        self::assertSame(0, $calls);
        self::assertSame(1, (int) $pdo->query('SELECT calculated FROM calculated')->fetchColumn());
        self::assertSame(1, $calls);
    }

    public function testJoinsCountDisplayedRowsAndSearchAndSortingStayCorrect(): void
    {
        $pdo = new PerformancePDO();
        $pdo->exec('CREATE TABLE details (id INTEGER PRIMARY KEY, item_id INTEGER)');
        $pdo->exec('INSERT INTO details VALUES (1,1),(2,1),(3,2)');
        $crud = (new Crud('items', $pdo))->join('id', 'details', 'item_id');
        $result = $crud->getTableData(1, 10);
        self::assertSame(4, $result['pagination']['total_rows']);
        self::assertCount(4, $result['rows']);
        self::assertCount(1, $this->countQueries($pdo));
        $crud = (new Crud('items', $pdo))->where('amount >= 20')->order_by('amount', 'desc');
        $result = $crud->getTableData(1, 10, 'a', 'name');
        self::assertSame(2, $result['pagination']['total_rows']);
        self::assertSame([3, 2], array_column($result['rows'], 'id'));
    }

    public function testJoinPaginationCanReachEveryMatchingRow(): void
    {
        $pdo = new PerformancePDO();
        $pdo->exec('CREATE TABLE details (id INTEGER PRIMARY KEY, item_id INTEGER)');
        $pdo->exec('INSERT INTO details VALUES (1,1),(2,1),(3,1),(4,1),(5,2)');
        $crud = (new Crud('items', $pdo))->join('id', 'details', 'item_id')->order_by('id');
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $result = $crud->getTableData($page, 2);
            self::assertSame(6, $result['pagination']['total_rows']);
            self::assertSame(3, $result['pagination']['total_pages']);
            self::assertSame($page, $result['pagination']['current_page']);
            array_push($ids, ...array_column($result['rows'], 'id'));
        }
        self::assertSame([1, 1, 1, 1, 2, 3], $ids);
        $filtered = $crud->getTableData(2, 2, 'Alpha', 'name');
        self::assertSame(4, $filtered['pagination']['total_rows']);
        self::assertSame(2, $filtered['pagination']['total_pages']);
        self::assertCount(2, $filtered['rows']);
        self::assertCount(6, $crud->getTableData(1, 0)['rows']);
    }

    public function testPartialClientStatePreservesNestedTables(): void
    {
        $pdo = new PerformancePDO();
        $crud = new Crud('items', $pdo);
        $crud->nested_table('children', 'id', 'items', 'owner');
        (new ReflectionMethod($crud, 'applyClientConfig'))->invoke($crud, ['per_page' => 10]);
        $tables = (new ReflectionProperty($crud, 'nestedTables'))->getValue($crud);
        self::assertArrayHasKey('children', $tables);
        (new ReflectionMethod($crud, 'applyClientConfig'))->invoke($crud, ['nested_tables' => []]);
        self::assertSame([], (new ReflectionProperty($crud, 'nestedTables'))->getValue($crud));
    }
}
