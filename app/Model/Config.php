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

    /**
     * 这些键只留在数据库里：不进 runtime/config（那份缓存的密钥由 config/database.php
     * 的内容推导，拿到网站目录就能解开），也不进 list() 交给模板的 $config。
     */
    private const NEVER_CACHE = ['request_log_key', 'csp_nonce_secret'];
    private const CONTEXT_SNAPSHOT = '_DB_CONFIG_SNAPSHOT';
    private const CONTEXT_EXCLUSIVE_LOCK = '_DB_CONFIG_EXCLUSIVE_LOCK';
    private const CONTEXT_REPAIRED = '_DB_CONFIG_REPAIRED';

    /**
     * 快照完整性标记。带着它，说明这份 runtime/config 是从 list() **整表**重建出来的，
     * 于是「缓存里没有这个键」= 库里也没有；不带，说明快照是残缺的，缺什么都说明不了。
     *
     * 用 NUL 开头：真实配置键不可能长这样，也绝不会出现在 list() 交给模板的 $config 里。
     */
    private const SNAPSHOT_COMPLETE = "\0complete";

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
            if (is_array($configs)) {
                return $configs;
            }
        } catch (\Throwable) {
            //落到下面统一报告
        }

        //文件非空却解不开：缓存密钥是 md5(库名+口令+账号+前缀+Binary.php 路径) 推出来的，
        //改数据库口令、换账号、改表前缀、把站点挪个目录，都会让整份缓存作废。
        //以前这里静默返回空数组，于是所有只走 cached() 的配置（IP 获取方式、CSP、外链
        //白名单…）会无声无息地退回默认值，站长只能看见"设置自己变回去了"。
        self::reportUnreadableCache();
        return [];
    }

    /** 每个请求只报一次，别让坏缓存把日志刷爆 */
    private static function reportUnreadableCache(): void
    {
        if (Context::get('_DB_CONFIG_UNREADABLE') === true) {
            return;
        }
        Context::set('_DB_CONFIG_UNREADABLE', true);
        try {
            \Kernel\Util\Log::inst()->error(
                'runtime/config 无法解密（数据库口令/账号/库名/表前缀变更，或站点目录被移动过），'
                . '本次已按整表重建。若反复出现，请检查 config/database.php 是否与建立缓存时一致。'
            );
        } catch (\Throwable) {
            //日志不可用不能影响配置读取
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
     * 只读缓存，命中不了就返回 null，全程不碰数据库。
     * 给那些在连接建立之前、甚至安装完成之前就要取值的调用方用。
     *
     * @param string $key
     * @return string|null
     * @throws RuntimeException
     */
    public static function cached(string $key): ?string
    {
        if (in_array($key, self::NEVER_CACHE, true)) {
            return null;
        }

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

        //快照不是整表重建出来的（被清过、或换了数据库口令/站点目录导致解不开）时，
        //「缓存里没有」什么也说明不了——库里很可能有值。先把整份快照修好再回答。
        //修完的快照带完整性标记，之后的未命中就是真未命中，一次数据库都不会碰。
        $repaired = self::repairSnapshot($configs);
        if ($repaired === null || !array_key_exists($key, $repaired)) {
            return null;
        }

        Context::set($cacheKey, $repaired[$key]);
        return (string)$repaired[$key];
    }

    /**
     * 把 runtime/config 从数据库整表重建一次，返回重建后的快照；做不了就返回 null。
     *
     * 只在快照缺少完整性标记时才做，且**一个请求最多做一次**。数据库还没连上
     * （Request 构造期、站点未安装）时静静返回 null——那条「全程不碰数据库」的路径
     * 正是 cached() 存在的理由，不能为了修缓存把它弄挂。
     *
     * @param array<string, string|int> $current
     * @return array<string, string|int>|null
     */
    private static function repairSnapshot(array $current): ?array
    {
        if (array_key_exists(self::SNAPSHOT_COMPLETE, $current)) {
            return null;
        }
        if (Context::get(self::CONTEXT_REPAIRED) === true) {
            return null;
        }
        Context::set(self::CONTEXT_REPAIRED, true);

        try {
            return self::useExclusiveLock(static function (LockedFile $file): array {
                //拿到锁后重读：并发的另一个请求可能已经修好了，别白重建一次
                $configs = self::decodeCache(self::lockedCacheContents($file));
                if (array_key_exists(self::SNAPSHOT_COMPLETE, $configs)) {
                    self::publishContextSnapshot($configs);
                    return $configs;
                }

                $snapshot = self::completeSnapshot(self::list());
                $encoded = Binary::inst()->pack($snapshot);
                if ($encoded === '') {
                    throw new RuntimeException('could not encode configuration cache');
                }
                self::replaceCacheContents($file, $encoded);
                self::publishContextSnapshot($snapshot);
                return $snapshot;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 给整表快照盖上完整性标记。只加在**落盘与进 Context 的那一份**上，
     * list() 本身保持干净——它的返回值会作为 $config 直接进模板。
     *
     * @param array<string, string|int> $snapshot
     * @return array<string, string|int>
     */
    private static function completeSnapshot(array $snapshot): array
    {
        $snapshot[self::SNAPSHOT_COMPLETE] = '1';
        return $snapshot;
    }

    /**
     * 为了方便，在这里直接静态get
     * @param string $key
     * @return string
     * @throws RuntimeException
     */
    public static function get(string $key): string
    {
        if (in_array($key, self::NEVER_CACHE, true)) {
            return self::secret($key);
        }

        $cached = self::cached($key);
        if ($cached !== null) {
            return $cached;
        }

        // The request snapshot did not contain this key. Recheck both cache and
        // database under the writer lock so a concurrent putMany() cannot be
        // overwritten with an older value fetched before its cache publish.
        $configs = self::useExclusiveLock(function (LockedFile $file) use ($key): array {
            $configs = self::decodeCache(self::lockedCacheContents($file));

            //**绝不能在残缺的快照上做增量写回**。这里以前是「解出什么就在什么上加一个键
            //再整份覆盖」——只要 decodeCache 失败过一次（文件被截断、或换了数据库口令/
            //站点目录导致解不开），这一次写回就会把整份缓存**替换成只有一个键的文件**，
            //其余几十个键就此消失，而只走 cached() 读取的设置（IP 获取方式、CSP、外链
            //白名单…）会因此静默退回默认值再也回不来。issue #928 就是这么来的。
            //快照没有完整性标记 = 它不可信，直接按整表重建，只多一次全表查询。
            if (!array_key_exists(self::SNAPSHOT_COMPLETE, $configs)) {
                $configs = self::completeSnapshot(self::list());
                $encoded = Binary::inst()->pack($configs);
                if ($encoded === '') {
                    throw new RuntimeException('could not encode configuration cache');
                }
                self::replaceCacheContents($file, $encoded);
                self::publishContextSnapshot($configs);
                return $configs;
            }

            //快照是全量的：缺这个键就是库里真的没有，不必再查一次
            if (!array_key_exists($key, $configs)) {
                self::publishContextSnapshot($configs);
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
    /**
     * 直接读数据库，不经过任何缓存。
     *
     * @param string $key
     * @return string
     */
    public static function secret(string $key): string
    {
        $cfg = self::query()->where('key', $key)->first();
        return $cfg ? (string)$cfg->value : '';
    }

    /**
     * 直接写数据库，不重建 runtime/config，值不会落到磁盘上。
     *
     * @param string $key
     * @param string $value
     * @return bool
     */
    public static function putSecret(string $key, string $value): bool
    {
        $cfg = self::query()->where('key', $key)->first();
        if (!$cfg) {
            $cfg = new self();
            $cfg->key = $key;
        }
        $cfg->value = $value;
        return $cfg->save() === true;
    }

    public static function list(): array
    {
        $cfg = Config::query()->get();
        $list = [];
        foreach ($cfg as $item) {
            $list[$item->key] = $item->value;
        }
        //货币键缺省回填：老站升级后没保存过货币设置时，模板 $config.currency_* 也要能直接用
        foreach (self::NEVER_CACHE as $secret) {
            unset($list[$secret]);
        }

        $list += [
            'currency_code' => \App\Util\Currency::DEFAULT_CODE,
            'currency_symbol' => \App\Util\Currency::DEFAULT_SYMBOL,
            'currency_rate' => \App\Util\Currency::DEFAULT_RATE,
            'currency_decimals' => (string)\App\Util\Currency::DEFAULT_DECIMALS,
        ];
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

            $newSnapshot = self::completeSnapshot(self::list());
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
                    $actualSnapshot = self::completeSnapshot(self::list());
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
