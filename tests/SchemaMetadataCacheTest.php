<?php
declare(strict_types=1);

use FastCrud\Crud;
use FastCrud\Database;
use FastCrud\DatabaseEditor;
use PHPUnit\Framework\TestCase;

final class SchemaQueryCountingPDO extends PDO
{
    public int $queryCount = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCount++;
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

final class SchemaMetadataCacheTest extends TestCase
{
    protected function setUp(): void
    {
        Database::clearSchemaCache();
    }

    private function metadata(Crud $crud, string $method, string $table = 'items'): array
    {
        return (new ReflectionMethod(Crud::class, $method))->invoke($crud, $table);
    }

    public function testMultipleInstancesShareLookupsButConnectionsStayIsolated(): void
    {
        $first = new SchemaQueryCountingPDO();
        $first->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        for ($i = 0; $i < 3; $i++) {
            $crud = new Crud('items', $first);
            self::assertSame(['id', 'name'], $this->metadata($crud, 'getTableColumnsFor'));
            self::assertSame(['id', 'name'], array_keys($this->metadata($crud, 'getTableSchema')));
        }
        self::assertSame(2, $first->queryCount);

        $second = new SchemaQueryCountingPDO();
        $second->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, price REAL)');
        $crud = new Crud('items', $second);
        self::assertSame(['id', 'price'], $this->metadata($crud, 'getTableColumnsFor'));
        self::assertSame(['id', 'price'], array_keys($this->metadata($crud, 'getTableSchema')));
        self::assertSame(2, $second->queryCount);
    }

    public function testEditorChangesInvalidateColumnsAndTypesForExistingInstances(): void
    {
        $pdo = new SchemaQueryCountingPDO();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
        $crud = new Crud('items', $pdo);
        $this->metadata($crud, 'getTableColumnsFor');
        $this->metadata($crud, 'getTableSchema');
        $editorColumns = new ReflectionMethod(DatabaseEditor::class, 'fetchColumns');
        self::assertCount(1, $editorColumns->invoke(null, $pdo, 'sqlite', 'items'));

        $oldPost = $_POST;
        $oldServer = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['fc_db_editor_action' => 'add_column', 'table_name' => 'items',
                'column_name' => 'description', 'column_type' => 'TEXT'];
            (new ReflectionMethod(DatabaseEditor::class, 'handleRequest'))->invoke(null, $pdo, 'sqlite');
        } finally {
            $_POST = $oldPost;
            $_SERVER = $oldServer;
        }

        self::assertSame(['id', 'description'], $this->metadata($crud, 'getTableColumnsFor'));
        self::assertArrayHasKey('description', $this->metadata($crud, 'getTableSchema'));
        self::assertCount(2, $editorColumns->invoke(null, $pdo, 'sqlite', 'items'));
    }

    public function testFailedLookupIsRetriedAfterTableCreation(): void
    {
        $pdo = new SchemaQueryCountingPDO();
        $crud = new Crud('items', $pdo);
        self::assertSame([], $this->metadata($crud, 'getTableColumnsFor'));
        self::assertSame([], $this->metadata($crud, 'getTableSchema'));
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
        self::assertSame(['id'], $this->metadata($crud, 'getTableColumnsFor'));
        self::assertArrayHasKey('id', $this->metadata($crud, 'getTableSchema'));
    }

    public function testExplicitInvalidationRefreshesOnlySelectedConnection(): void
    {
        $first = new SchemaQueryCountingPDO();
        $second = new SchemaQueryCountingPDO();
        foreach ([$first, $second] as $pdo) {
            $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY)');
            $this->metadata(new Crud('items', $pdo), 'getTableColumnsFor');
        }
        $first->exec('ALTER TABLE items ADD COLUMN extra TEXT');
        Database::clearSchemaCache($first);
        self::assertSame(['id', 'extra'], $this->metadata(new Crud('items', $first), 'getTableColumnsFor'));
        self::assertSame(['id'], $this->metadata(new Crud('items', $second), 'getTableColumnsFor'));
        self::assertSame(1, $second->queryCount);
    }
}
