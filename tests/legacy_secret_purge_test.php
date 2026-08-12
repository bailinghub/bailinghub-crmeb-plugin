<?php

namespace think {
    class Service
    {
    }
}

namespace think\facade {
    class Db
    {
        public static $rows = array();
        public static $fail = false;

        public static function name($table)
        {
            if ($table !== 'system_config') {
                throw new \RuntimeException('unexpected table');
            }
            return new LegacyQuery();
        }
    }

    class LegacyQuery
    {
        private $name = '';

        public function where($field, $value)
        {
            if ($field !== 'menu_name') {
                throw new \RuntimeException('unexpected field');
            }
            $this->name = (string)$value;
            return $this;
        }

        public function delete()
        {
            if (Db::$fail) {
                throw new \RuntimeException('simulated database failure');
            }
            $exists = isset(Db::$rows[$this->name]);
            unset(Db::$rows[$this->name]);
            return $exists ? 1 : 0;
        }
    }
}

namespace crmeb\services {
    class CacheService
    {
        public static $keys = array();
        public static $retain = false;

        public static function delete($name)
        {
            if (!self::$retain) {
                unset(self::$keys[$name]);
            }
            // 匹配 CRMEB Redis/File 驱动：键不存在时允许返回 false。
            return false;
        }

        public static function has($name)
        {
            return isset(self::$keys[$name]);
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/src/PluginInfo.php';
    require_once dirname(__DIR__) . '/src/BailingService.php';

    class PurgeProbe extends \app\bailing\BailingService
    {
        public function runPurge()
        {
            $this->purgeLegacyGenericSecrets();
        }
    }

    $failed = 0;
    $checks = 0;
    function purgeCheck($condition, $message)
    {
        global $failed, $checks;
        $checks++;
        if (!$condition) {
            fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
            $failed++;
        }
    }

    $probe = new PurgeProbe();
    \think\facade\Db::$rows = array(
        'bailing_access_token' => 'legacy-token-marker',
        'bailing_sign_secret' => 'legacy-sign-marker',
        'unrelated' => 'keep',
    );
    \crmeb\services\CacheService::$keys = array(
        'system_config_bailing_access_token' => true,
        'system_config_bailing_sign_secret' => true,
        'system_config_unrelated' => true,
    );
    $probe->runPurge();
    purgeCheck(!isset(\think\facade\Db::$rows['bailing_access_token'])
        && !isset(\think\facade\Db::$rows['bailing_sign_secret']), 'boot deletes both legacy generic secret rows');
    purgeCheck(isset(\think\facade\Db::$rows['unrelated']), 'boot preserves unrelated configuration rows');
    purgeCheck(!isset(\crmeb\services\CacheService::$keys['system_config_bailing_access_token'])
        && !isset(\crmeb\services\CacheService::$keys['system_config_bailing_sign_secret']), 'boot clears both legacy named caches');
    purgeCheck(isset(\crmeb\services\CacheService::$keys['system_config_unrelated']), 'boot preserves unrelated cache keys');

    // 删除不存在的缓存键返回 false 是 CRMEB 正常语义，不能因此让每个请求 500。
    $probe->runPurge();
    purgeCheck(true, 'already-clean state remains idempotent when cache delete returns false');

    \crmeb\services\CacheService::$retain = true;
    \crmeb\services\CacheService::$keys['system_config_bailing_access_token'] = true;
    try {
        $probe->runPurge();
        purgeCheck(false, 'remaining named cache must fail closed');
    } catch (\RuntimeException $e) {
        purgeCheck(strpos($e->getMessage(), 'legacy-token-marker') === false, 'cache cleanup failure is safe and fail-closed');
    }
    \crmeb\services\CacheService::$retain = false;
    \crmeb\services\CacheService::$keys = array();

    \think\facade\Db::$fail = true;
    try {
        $probe->runPurge();
        purgeCheck(false, 'database cleanup failure must fail closed');
    } catch (\RuntimeException $e) {
        purgeCheck(strpos($e->getMessage(), 'simulated') === false, 'database failure does not expose backend details');
    }

    if ($failed > 0) {
        exit(1);
    }
    echo 'Legacy secret purge: ' . $checks . " checks passed\n";
}
