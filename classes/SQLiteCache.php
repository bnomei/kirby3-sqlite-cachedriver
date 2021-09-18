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

        if ($this->options['debug']) {
            $this->flush();
        } else {
            if ($this->validate() === false) {
                throw new \Exception('SQLite Cache Driver failed to read/write. Check SQLite binary version ('.SQLite3::version()['versionString'].') or adjust pragmas used by plugin.');
            }
        }
        
        $this->garbagecollect();
    }

    public function __destruct()
    {
        if ($this->database) {
            $this->applyPragmas('pragmas-destruct');
            $this->database->close();
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
        if ($this->option('debug')) {
            return true;
        }

        return $this->removeAndSet($key, $value, $minutes);
    }

    private function removeAndSet(string $key, $value, int $minutes = 0): bool
    {
        $this->remove($key);

        $value = new Value($value, $minutes);
        $expire = $value->expires();
        $data = htmlspecialchars($value->toJson(), ENT_QUOTES);

        $this->insertStatement->bindValue(':id', $key, SQLITE3_TEXT);
        $this->insertStatement->bindValue(':expire_at', $expire ?? 0, SQLITE3_INTEGER);
        $this->insertStatement->bindValue(':data', $data, SQLITE3_TEXT);
        $this->insertStatement->execute();
        $this->insertStatement->clear();
        $this->insertStatement->reset();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function retrieve(string $key, bool $withTransaction = true): ?Value
    {
        $this->selectStatement->bindValue(':id', $key, SQLITE3_TEXT);
        $this->selectStatement->bindValue(':expire_at', time(), SQLITE3_INTEGER);
        $results = $this->selectStatement->execute()->fetchArray(SQLITE3_ASSOC);
        $this->selectStatement->clear();
        $this->selectStatement->reset();
        if ($results === false) {
            return null;
        }
        $json = htmlspecialchars_decode(strval($results['data']));
        return Value::fromJson($json);
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
        kirby()->cache('bnomei.sqlite-cachedriver')->remove(static::DB_VALIDATE . static::DB_VERSION);
        $success = $this->database->exec("DELETE FROM cache WHERE id != '' ");

        if ($this->validate() === false) {
            throw new \Exception('SQLite Cache Driver failed to read/write. Check SQLite binary version ('.SQLite3::version()['versionString'].') or adjust pragmas used by plugin.');
        }

        return $success;
    }

    public function validate(): bool
    {
        $validate = static::DB_VALIDATE . static::DB_VERSION;
        if (kirby()->cache('bnomei.sqlite-cachedriver')->get($validate)) {
            return $this->get($validate) != null;
        }

        $time = time();
        $this->removeAndSet($validate, $time, 0);
        kirby()->cache('bnomei.sqlite-cachedriver')->set($validate, $time, 0);
        
        // a get() is not perfect will not help since it might be just in memory
        // but the file created by file cache can be checked on next request
        // $this->get() will no work with debug mode but we need that in case of exceptions
        // return $this->get($validate) != null;
        // so we use parent which has no debug check clause
        return parent::get($validate) != null;
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
            F::remove($file);
            F::remove($file . '-wal');
            F::remove($file . '-shm');
            $this->database = new SQLite3($file);
            throw new \Exception($exception->getMessage());
        }
    }

    private function applyPragmas(string $pragmas)
    {
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
            'extension' => 'sqlite',
            'debug' => \option('debug'),
            'pragmas-construct' => \option('bnomei.sqlite-cachedriver.pragmas-construct'),
            'pragmas-destruct' => \option('bnomei.sqlite-cachedriver.pragmas-destruct'),
        ], $options);

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
    }

    private function prepareStatements()
    {
        $this->selectStatement = $this->database->prepare("SELECT data FROM cache WHERE id = :id AND expire_at <= :expire_at");
        $this->insertStatement = $this->database->prepare("INSERT INTO cache (id, expire_at, data) VALUES (:id, :expire_at, :data)");
        $this->deleteStatement = $this->database->prepare("DELETE FROM cache WHERE id = :id");
    }

    /**
     * @return int
     */
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
