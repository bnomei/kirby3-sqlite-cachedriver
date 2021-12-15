<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cache\FileCache;
use Kirby\Cache\Value;
use Kirby\Toolkit\A;
use Kirby\Toolkit\F;
use Kirby\Toolkit\Str;
use SQLite3;
use SQLite3Stmt;

final class SQLiteCache extends FileCache
{
    public const DB_VERSION = '1';
    public const DB_FILENAME = 'sqlitecache-';
    public const DB_VALIDATE = 'sqlitecache-';


    private $shutdownCallbacks = [];

    /**
     * @var SQLite3
     */
    protected $database;
    /**
     * @var SQLite3Stmt
     */
    private $deleteStatement;
    /**
     * @var SQLite3Stmt
     */
    private $insertStatement;
    /**
     * @var SQLite3Stmt
     */
    private $selectStatement;
    /**
     * @var SQLite3Stmt
     */
    private $updateStatement;

    /** @var array $store */
    private $store;

    /**
     * @var int
     */
    private $transactionsCount = 0;

    public function __construct(array $options = [])
    {
        $this->setOptions($options);

        parent::__construct($this->options);

        $this->loadDatabase();
        $this->applyPragmas('pragmas-construct');

        $this->database->exec('CREATE TABLE IF NOT EXISTS cache (id TEXT primary key unique, expire_at INTEGER, data TEXT)');

        $this->prepareStatements();
        $this->store = [];

        $this->beginTransaction();

        if ($this->options['debug']) {
            $this->flush();
        }

        $this->garbagecollect();
    }

    public function register_shutdown_function($callback) {
        $this->shutdownCallbacks[] = $callback;
    }

    public function __destruct()
    {
        foreach($this->shutdownCallbacks as $callback) {
            if (!is_string($callback) && is_callable($callback)) {
                $callback();
            }
        }

        if ($this->database) {
            $this->endTransaction();
            $this->applyPragmas('pragmas-destruct');
            $this->database->close();
            $this->database = null;
        }
    }

    /**
     * @param string|null $key
     * @return array
     */
    public function option(?string $key = null)
    {
        if ($key) {
            return A::get($this->options, $key);
        }
        return $this->options;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, $value, int $minutes = 0): bool
    {
        /* SHOULD SET EVEN IN DEBUG
        if ($this->option('debug')) {
            return true;
        }
        */

        return $this->updateOrInsert($key, $value, $minutes);
    }

    private function updateOrInsert(string $key, $value, int $minutes = 0): bool
    {
        $rawKey = $key;
        $key = $this->key($key);
        $value = new Value($value, $minutes);
        $expire = $value->expires();
        $data = htmlspecialchars($value->toJson(), ENT_QUOTES);

        if ($this->existsEvenIfExpired($rawKey)) {
            $this->updateStatement->bindValue(':id', $key, SQLITE3_TEXT);
            $this->updateStatement->bindValue(':expire_at', $expire ?? 0, SQLITE3_INTEGER);
            $this->updateStatement->bindValue(':data', $data, SQLITE3_TEXT);
            $this->updateStatement->execute();
            $this->updateStatement->clear();
            $this->updateStatement->reset();
        } else {
            $this->insertStatement->bindValue(':id', $key, SQLITE3_TEXT);
            $this->insertStatement->bindValue(':expire_at', $expire ?? 0, SQLITE3_INTEGER);
            $this->insertStatement->bindValue(':data', $data, SQLITE3_TEXT);
            $this->insertStatement->execute();
            $this->insertStatement->clear();
            $this->insertStatement->reset();
        }

        if ($this->option('store') && (empty($this->option('store-ignore')) || str_contains($key, $this->option('store-ignore')) === false)) {
            $this->store[$key] = $value;
        }

        return true;
    }

    private function existsEvenIfExpired(string $key): bool
    {
        $key = $this->key($key);

        $this->selectStatement->bindValue(':id', $key, SQLITE3_TEXT);
        $results = $this->selectStatement->execute()->fetchArray(SQLITE3_ASSOC);
        $this->selectStatement->clear();
        $this->selectStatement->reset();

        return $results !== false;
    }

    /**
     * @inheritDoc
     */
    public function retrieve(string $key): ?Value
    {
        $key = $this->key($key);

        $value = A::get($this->store, $key);
        if ($value === null) {
            $this->selectStatement->bindValue(':id', $key, SQLITE3_TEXT);
            $results = $this->selectStatement->execute()->fetchArray(SQLITE3_ASSOC);
            $this->selectStatement->clear();
            $this->selectStatement->reset();
            if ($results === false) {
                return null;
            }
            $value = htmlspecialchars_decode(strval($results['data']), ENT_QUOTES);
            $value = $value ? Value::fromJson($value) : null;

            if ($this->option('store') && (empty($this->option('store-ignore')) || str_contains($key, $this->option('store-ignore')) === false)) {
                $this->store[$key] = $value;
            }
        }
        return $value;
    }

    public function get(string $key, $default = null)
    {
        if ($this->option('debug')) {
            return $default;
        }

        return parent::get($key, $default);
    }

    /**
     * @inheritDoc
     */
    public function remove(string $key): bool
    {
        $key = $this->key($key);

        if (array_key_exists($key, $this->store)) {
            unset($this->store[$key]);
        }

        $this->deleteStatement->bindValue(':id', $key, SQLITE3_TEXT);
        $this->deleteStatement->execute();
        $this->deleteStatement->clear();
        $this->deleteStatement->reset();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function flush(): bool
    {
        $this->store = [];
        kirby()->cache('bnomei.sqlite-cachedriver')->remove(static::DB_VALIDATE . static::DB_VERSION);
        $success = $this->database->exec("DELETE FROM cache WHERE id != '' ");

        return $success;
    }

    public function garbagecollect(): bool
    {
        return $this->database->exec("DELETE FROM cache WHERE expire_at > 0 AND expire_at <= " . time());
    }

    private static $singleton;
    public static function singleton(array $options = []): self
    {
        if (self::$singleton) {
            return self::$singleton;
        }
        self::$singleton = new self($options);
        return self::$singleton;
    }

    private function loadDatabase()
    {
        $file = $this->file(static::DB_FILENAME . static::DB_VERSION);
        try {
            $this->database = new SQLite3($file);
        } catch (\Exception $exception) {
            $this->resetDB($file);
            throw new \Exception($exception->getMessage());
        }
    }

    private function resetDB(string $file): void
    {
        kirby()->cache('bnomei.sqlite-cachedriver')->remove(static::DB_VALIDATE . static::DB_VERSION);
        F::remove($file);
        F::remove($file . '-wal');
        F::remove($file . '-shm');
        $this->database = new SQLite3($file);
    }

    private function applyPragmas(string $pragmas)
    {
        // TODO: recover from SQLite3::exec(): database is locked
        foreach ($this->options[$pragmas] as $pragma) {
            $this->database->exec($pragma);
        }
    }

    private function setOptions(array $options)
    {
        $root = null;
        $cache = kirby()->cache('bnomei.sqlite-cachedriver');
        if (is_a($cache, FileCache::class)) {
            $root = A::get($cache->options(), 'root');
            if ($prefix =  A::get($cache->options(), 'prefix')) {
                $root .= '/' . $prefix;
            }
        } else {
            $root = kirby()->roots()->cache();
        }

        $this->options = array_merge([
            'root' => $root,
            'debug' => \option('debug'),
            'store' => \option('bnomei.sqlite-cachedriver.store', true),
            'store-ignore' => \option('bnomei.sqlite-cachedriver.store-ignore'),
            'pragmas-construct' => \option('bnomei.sqlite-cachedriver.pragmas-construct'),
            'pragmas-destruct' => \option('bnomei.sqlite-cachedriver.pragmas-destruct'),
        ], $options);

        // overwrite *.cache in all constructors
        $this->options['extension'] = 'sqlite';

        foreach ($this->options as $key => $call) {
            if (!is_string($call) && is_callable($call) && in_array($key, [
                    'pragmas-construct',
                    'pragmas-destruct',
                ])) {
                $this->options[$key] = $call();
            }
        }
    }

    public function beginTransaction()
    {
        $this->database->exec("BEGIN TRANSACTION;");
        $this->transactionsCount++;
    }

    public function endTransaction()
    {
        $this->database->exec("END TRANSACTION;");
        $this->transactionsCount = 0;
    }

    private function prepareStatements()
    {
        $this->selectStatement = $this->database->prepare("SELECT data FROM cache WHERE id = :id");
        $this->insertStatement = $this->database->prepare("INSERT INTO cache (id, expire_at, data) VALUES (:id, :expire_at, :data)");
        $this->deleteStatement = $this->database->prepare("DELETE FROM cache WHERE id = :id");
        $this->updateStatement = $this->database->prepare("UPDATE cache SET expire_at = :expire_at, data = :data WHERE id = :id");
    }

    public function transactionsCount(): int
    {
        return $this->transactionsCount;
    }

    public function benchmark(int $count = 10)
    {
        $prefix = "feather-benchmark-";
        $sqlite = $this;
        $file = kirby()->cache('bnomei.sqlite-cachedriver'); // neat, right? ;-)

        foreach (['sqlite' => $sqlite, 'file' => $file] as $label => $driver) {
            $time = microtime(true);
            if ($label === 'sqlite') {
                $driver->beginTransaction();
            }
            for ($i = 0; $i < $count; $i++) {
                $key = $prefix . $i;
                if (!$driver->get($key)) {
                    $driver->set($key, Str::random(1000));
                }
            }
            for ($i = $count * 0.6; $i < $count * 0.8; $i++) {
                $key = $prefix . $i;
                $driver->remove($key);
            }
            for ($i = $count * 0.8; $i < $count; $i++) {
                $key = $prefix . $i;
                $driver->set($key, Str::random(1000));
            }
            if ($label === 'sqlite') {
                $this->endTransaction();
                $this->applyPragmas('pragmas-destruct');
                $this->applyPragmas('pragmas-construct');
            }
            echo $label . ' : ' . (microtime(true) - $time) . PHP_EOL;
        }

        // cleanup
        for ($i = 0; $i < $count; $i++) {
            $key = $prefix . $i;
            $driver->remove($key);
        }
    }
}
