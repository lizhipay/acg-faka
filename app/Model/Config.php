<?php
declare (strict_types=1);

namespace App\Model;


use App\Util\Context;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model;
use Kernel\File\File as LockedFile;
use Kernel\Exception\RuntimeException;
use Kernel\Util\Binary;

/**
 * @property int $id
 * @property string $key
 * @property string $value
 */
class Config extends Model
{
    /**
     * @var string
     */
    protected $table = 'config';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = ['id' => 'integer'];

    private const CACHE_FILE = BASE_PATH . "/runtime/config";
    private const CONTEXT_SNAPSHOT = '_DB_CONFIG_SNAPSHOT';
    private const CONTEXT_EXCLUSIVE_LOCK = '_DB_CONFIG_EXCLUSIVE_LOCK';

    /**
     * Read the cache from the start of a handle whose lock is already held.
     * Re-entrant writers may reuse a stream left at EOF by a prior publish.
     *
     * @throws RuntimeException
     */
    private static function lockedCacheContents(LockedFile $file): string
    {
        if (!is_resource($file->resource) || fseek($file->resource, 0) !== 0) {
            throw new RuntimeException('could not seek configuration cache');
        }
        return $file->contents();
    }

    /**
     * @return array<string, string|int>
     */
    private static function decodeCache(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        try {
            $binary = Binary::inst();
            $configs = @$binary->unpack($contents);
            return is_array($configs) ? $configs : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Replace the cache while the caller holds its exclusive lock. Kernel's
     * generic writer only distinguishes false from a short fwrite(), which can
     * leave a truncated encrypted payload looking successful.
     *
     * @throws RuntimeException
     */
    private static function replaceCacheContents(LockedFile $file, string $contents): void
    {
        $resource = $file->resource;
        if (!is_resource($resource) || fseek($resource, 0) !== 0) {
            throw new RuntimeException('could not seek configuration cache');
        }

        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($resource, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('could not write configuration cache');
            }
            $offset += $written;
        }

        if (!ftruncate($resource, $length) || !fflush($resource)) {
            throw new RuntimeException('could not finalize configuration cache');
        }
    }

    /**
     * Readers participate in the same lock as putMany(), so they can never
     * decrypt a half-written payload or fall through to a partially compensated
     * MyISAM batch.
     *
     * @return array<string, string|int>
     * @throws RuntimeException
     */
    private static function readCacheSnapshot(): array
    {
        $state = Context::get(self::CONTEXT_EXCLUSIVE_LOCK);
        if (is_object($state) && ($state->file ?? null) instanceof LockedFile) {
            return self::decodeCache(self::lockedCacheContents($state->file));
        }

        $file = new LockedFile(self::CACHE_FILE, 'c+');
        $file->shareLock();
        try {
            return self::decodeCache(self::lockedCacheContents($file));
        } finally {
            $file->close();
        }
    }

    /**
     * Acquire or reuse this request's exclusive configuration-cache lock.
     *
     * @template T
     * @param callable(LockedFile):T $callback
     * @return T
     * @throws \Throwable
     */
    private static function useExclusiveLock(callable $callback): mixed
    {
        $state = Context::get(self::CONTEXT_EXCLUSIVE_LOCK);
        if (is_object($state) && ($state->file ?? null) instanceof LockedFile) {
            $state->depth = (int)($state->depth ?? 0) + 1;
            try {
                return $callback($state->file);
            } finally {
                $state->depth--;
            }
        }

        $file = new LockedFile(self::CACHE_FILE, 'c+');
        $file->lock();
        $state = (object)['file' => $file, 'depth' => 1];
        Context::set(self::CONTEXT_EXCLUSIVE_LOCK, $state);
        try {
            return $callback($file);
        } finally {
            Context::set(self::CONTEXT_EXCLUSIVE_LOCK, null);
            $file->close();
        }
    }

    /**
     * Run a critical section under the same exclusive application lock used by
     * every configuration writer. This is needed by destructive operations
     * that must keep legacy MyISAM configuration references stable until their
     * own database transaction commits.
     *
     * Callers must acquire this lock before database row locks, matching the
     * lock order used by putMany().
     *
     * @template T
     * @param callable():T $callback
     * @return T
     * @throws \Throwable
     */
    public static function withExclusiveLock(callable $callback): mixed
    {
        return self::useExclusiveLock(static fn(LockedFile $file): mixed => $callback());
    }

    /**
     * @param array<string, string|int> $snapshot
     * @param array<int, string> $changedKeys
     */
    private static function publishContextSnapshot(array $snapshot, array $changedKeys = []): void
    {
        Context::set(self::CONTEXT_SNAPSHOT, $snapshot);
        foreach ($changedKeys as $key) {
            Context::set('_DB_CONFIG_' . $key, array_key_exists($key, $snapshot) ? $snapshot[$key] : '');
        }
    }

    /**
     * @return int
     * @throws RuntimeException
     */
    public static function getSessionExpire(): int
    {
        $expire = self::get("session_expire") ?: (86400 * 30);
        if ($expire < 120) {
            return 86400 * 30;
        }
        return (int)$expire;
    }


    /**
     * 为了方便，在这里直接静态get
     * @param string $key
     * @return string
     * @throws RuntimeException
     */
    public static function get(string $key): string
    {
        $cacheKey = "_DB_CONFIG_" . $key;
        $cache = Context::get($cacheKey);

        // Empty strings and numeric zero are valid configuration values. A
        // truthiness check would ignore the value just published by putMany().
        if ($cache !== null) {
            return (string)$cache;
        }

        $configs = Context::get(self::CONTEXT_SNAPSHOT);
        if (!is_array($configs)) {
            $configs = self::readCacheSnapshot();
            self::publishContextSnapshot($configs);
        }

        if (array_key_exists($key, $configs)) {
            Context::set($cacheKey, $configs[$key]);
            return (string)$configs[$key];
        }

        // The request snapshot did not contain this key. Recheck both cache and
        // database under the writer lock so a concurrent putMany() cannot be
        // overwritten with an older value fetched before its cache publish.
        $configs = self::useExclusiveLock(function (LockedFile $file) use ($key): array {
            $configs = self::decodeCache(self::lockedCacheContents($file));
            if (!array_key_exists($key, $configs)) {
                $cfg = self::query()->where('key', $key)->first();
                if (!$cfg) {
                    self::publishContextSnapshot($configs);
                    return $configs;
                }
                $configs[$key] = (string)$cfg->value;
                $encoded = Binary::inst()->pack($configs);
                if ($encoded === '') {
                    throw new RuntimeException('could not encode configuration cache');
                }
                self::replaceCacheContents($file, $encoded);
            }
            return $configs;
        });

        if (!array_key_exists($key, $configs)) {
            return '';
        }

        self::publishContextSnapshot($configs, [$key]);
        return (string)$configs[$key];
    }

    /**
     * @return array
     */
    public static function list(): array
    {
        $cfg = Config::query()->get();
        $list = [];
        foreach ($cfg as $item) {
            $list[$item->key] = $item->value;
        }
        return $list;
    }


    /**
     * @param string $key
     * @param string|int $value
     * @throws RuntimeException
     */
    public static function put(string $key, string|int $value): void
    {
        self::putMany([$key => $value]);
    }

    /** @param array<string, string|int> $values */
    private static function validateBatch(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '' || (!is_string($value) && !is_int($value))) {
                throw new \InvalidArgumentException('Invalid configuration batch');
            }
        }
    }

    /**
     * Persist a related set of configuration values with application-level
     * atomic compensation and keep the file/request caches on the same final
     * snapshot.
     *
     * @param array<string, string|int> $values
     * @throws \Throwable
     */
    public static function putMany(array $values): void
    {
        if ($values === []) {
            return;
        }
        self::validateBatch($values);
        self::useExclusiveLock(static function (LockedFile $cacheFile) use ($values): void {
            self::putManyLocked($cacheFile, $values);
        });
    }

    /**
     * Validate relational guards and persist a configuration batch while the
     * cache-file lock remains held across the database transaction commit.
     * Lock order is always cache file, database transaction, caller row locks;
     * the guard is for locking reads and validation, not database mutations.
     *
     * @param array<string, string|int> $values
     * @param callable():void $guard
     * @throws \Throwable
     */
    public static function putManyGuarded(array $values, callable $guard): void
    {
        if ($values === []) {
            return;
        }
        self::validateBatch($values);
        self::useExclusiveLock(static function (LockedFile $cacheFile) use ($values, $guard): void {
            DB::transaction(static function () use ($cacheFile, $values, $guard): void {
                $guard();
                self::putManyLocked($cacheFile, $values);
            });
        });
    }

    /**
     * Legacy installations use MyISAM for the config table, so the cache-file
     * lock supplies writer serialization and compensation atomicity. The caller
     * must already hold that lock. We snapshot row existence, ids and values,
     * compensate successful writes in reverse order on failure, and publish the
     * rebuilt file/request cache only after every row has saved.
     *
     * @param array<string, string|int> $values
     * @throws \Throwable
     */
    private static function putManyLocked(LockedFile $cacheFile, array $values): void
    {
        $oldCache = self::lockedCacheContents($cacheFile);

        $originalRows = [];
        $writtenKeys = [];

        try {
            // Snapshot the complete rollback state before changing any row.
            foreach ($values as $key => $value) {
                $cfg = self::query()->where('key', $key)->first();
                $originalRows[$key] = $cfg ? [
                    'exists' => true,
                    'id' => (int)$cfg->id,
                    'value' => (string)$cfg->value,
                ] : ['exists' => false];
            }

            foreach ($values as $key => $value) {
                $cfg = self::query()->where('key', $key)->first();
                if (!$cfg) {
                    $cfg = new self();
                    $cfg->key = $key;
                }
                $cfg->value = $value;
                // Include the key before save: even an exception raised after
                // the storage engine accepted a row must be compensated.
                $writtenKeys[] = $key;
                if ($cfg->save() !== true) {
                    throw new RuntimeException('could not save configuration:' . $key);
                }
            }

            $newSnapshot = self::list();
            $newCache = Binary::inst()->pack($newSnapshot);
            if ($newCache === '') {
                throw new RuntimeException('could not encode configuration cache');
            }
            self::replaceCacheContents($cacheFile, $newCache);
            self::publishContextSnapshot($newSnapshot, array_keys($values));
        } catch (\Throwable $e) {
            $rollbackError = null;
            foreach (array_reverse($writtenKeys) as $key) {
                try {
                    $original = $originalRows[$key];
                    if ($original['exists'] === false) {
                        self::query()->where('key', $key)->delete();
                        if (self::query()->where('key', $key)->exists()) {
                            throw new RuntimeException('could not remove newly-created configuration:' . $key);
                        }
                        continue;
                    }

                    $cfg = self::query()->where('id', $original['id'])->first()
                        ?: self::query()->where('key', $key)->first();
                    if (!$cfg) {
                        $cfg = new self();
                        $cfg->id = $original['id'];
                    }
                    $cfg->key = $key;
                    $cfg->value = $original['value'];
                    if ($cfg->save() !== true) {
                        throw new RuntimeException('could not restore configuration:' . $key);
                    }
                } catch (\Throwable $restoreRowError) {
                    $rollbackError ??= $restoreRowError;
                }
            }

            if ($rollbackError === null) {
                try {
                    self::replaceCacheContents($cacheFile, $oldCache);
                    $restoredSnapshot = self::decodeCache($oldCache);
                    foreach ($originalRows as $key => $original) {
                        if ($original['exists'] === true) {
                            $restoredSnapshot[$key] = $original['value'];
                        } else {
                            unset($restoredSnapshot[$key]);
                        }
                    }
                    self::publishContextSnapshot($restoredSnapshot, array_keys($values));
                } catch (\Throwable $restoreCacheError) {
                    $rollbackError = $restoreCacheError;
                }
            }

            if ($rollbackError !== null) {
                // Never deliberately restore stale cache bytes over a database
                // state that may only have been partially compensated. Rebuild
                // from the rows that actually remain and align this request's
                // Context before surfacing the fatal rollback failure.
                try {
                    $actualSnapshot = self::list();
                    $actualCache = Binary::inst()->pack($actualSnapshot);
                    if ($actualCache === '') {
                        throw new RuntimeException('could not encode recovered configuration cache');
                    }
                    self::replaceCacheContents($cacheFile, $actualCache);
                    self::publishContextSnapshot($actualSnapshot, array_keys($values));
                } catch (\Throwable $synchronizeError) {
                    throw new RuntimeException(
                        'Configuration save failed; rollback and runtime cache synchronization both failed',
                        0,
                        $synchronizeError
                    );
                }
            }

            if ($rollbackError !== null) {
                throw new RuntimeException(
                    'Configuration save failed and its previous database snapshot could not be fully restored',
                    0,
                    $rollbackError
                );
            }
            throw $e;
        }
    }

}
