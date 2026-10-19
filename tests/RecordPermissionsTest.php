<?php
declare(strict_types=1);

use FastCrud\Crud;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordPermissionsTest extends TestCase
{
    private PDO $pdo;
    private Crud $crud;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, group_id INTEGER, locked INTEGER, value TEXT, position INTEGER)');
        $this->pdo->exec("INSERT INTO items VALUES (1,1,0,'first',1),(2,1,1,'protected',2)");
        $this->crud = (new Crud('items', $this->pdo))
            ->enable_delete(true, 'locked', '=', 0)
            ->enable_duplicate()
            ->enable_row_ordering('position');
    }

    public static function recordOperations(): array
    {
        return [
            'delete' => ['deleteRecord', [1]],
            'batch delete' => ['deleteRecords', [[1]]],
            'update' => ['updateRecord', [1, ['value' => 'changed']]],
            'reorder' => ['reorderRecords', [[1]]],
            'duplicate' => ['duplicateRecord', [1]],
            'read' => ['getRecord', [1]],
        ];
    }

    #[DataProvider('recordOperations')]
    public function testRecordOperationsRejectUnconfiguredKeys(string $method, array $arguments): void
    {
        $before = $this->pdo->query('SELECT * FROM items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        try {
            $this->crud->$method('group_id', ...$arguments);
            self::fail('An unconfigured record key was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('configured primary key', $exception->getMessage());
        }
        self::assertSame($before, $this->pdo->query('SELECT * FROM items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testBulkUpdateRejectsUnconfiguredKeys(): void
    {
        $result = $this->crud->updateRecords('group_id', [1], ['value' => 'changed']);
        self::assertSame(0, $result['updated']);
        self::assertCount(1, $result['failures']);
        self::assertSame(['first', 'protected'], $this->pdo->query('SELECT value FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testConfiguredCustomKeysStillWork(): void
    {
        $this->pdo->exec('CREATE TABLE documents (code TEXT PRIMARY KEY, title TEXT)');
        $this->pdo->exec("INSERT INTO documents VALUES ('a','original'),('b','other')");
        $crud = (new Crud('documents', $this->pdo))->primary_key('code');
        self::assertSame('updated', $crud->updateRecord('code', 'a', ['title' => 'updated'])['title']);
        self::assertSame('other', $crud->getRecord('code', 'b')['title']);
        self::assertTrue($crud->deleteRecord('code', 'a'));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn());
    }

    public static function isUnlocked(array $row): bool
    {
        return !$row['locked'];
    }

    public static function fieldRules(): array
    {
        return [['field_editable_if'], ['field_visible_if']];
    }

    #[DataProvider('fieldRules')]
    public function testFieldPermissionsUseStoredValues(string $rule): void
    {
        $this->crud->readonly('locked')->$rule('value', [self::class, 'isUnlocked'], 'edit');
        $this->crud->updateRecord('id', 2, ['locked' => 0, 'value' => 'changed']);
        self::assertSame(['locked' => 1, 'value' => 'protected'],
            $this->pdo->query('SELECT locked, value FROM items WHERE id=2')->fetch(PDO::FETCH_ASSOC));
        self::assertSame('allowed', $this->crud->updateRecord('id', 1, ['value' => 'allowed'])['value']);
    }

    public static function readModes(): array
    {
        return [['view', 'enable_view'], ['edit', 'enable_edit'], ['create', 'enable_view']];
    }

    #[DataProvider('readModes')]
    public function testReadEnforcesTheModePermission(string $mode, string $configure): void
    {
        $this->crud->$configure(true, 'locked', '=', 0);
        self::assertSame('first', $this->crud->getRecord('id', 1, $mode)['value']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('action is not permitted');
        $this->crud->getRecord('id', 2, $mode);
    }

    public function testDisabledViewDoesNotDisableEditing(): void
    {
        $this->crud->enable_view(false);
        self::assertSame('first', $this->crud->getRecord('id', 1, 'edit')['value']);
        self::assertNull($this->crud->getRecord('id', 999, 'view'));
        $this->expectException(RuntimeException::class);
        $this->crud->getRecord('id', 1, 'view');
    }
}
