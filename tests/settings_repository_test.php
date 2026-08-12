<?php

namespace think\facade {
    /** 独立测试用最小 Db facade，验证事务和精确行更新，不连接真实 CRMEB 数据库。 */
    class Db
    {
        public static $rows = array();
        public static $transactions = 0;

        public static function name($table)
        {
            if ($table !== 'system_config') {
                throw new \RuntimeException('unexpected table');
            }
            return new SettingsQuery();
        }

        public static function transaction($callback)
        {
            self::$transactions++;
            $before = self::$rows;
            try {
                return $callback();
            } catch (\Throwable $e) {
                self::$rows = $before;
                throw $e;
            }
        }
    }

    class SettingsQuery
    {
        private $menuName = '';

        public function where($field, $value)
        {
            if ($field !== 'menu_name') {
                throw new \RuntimeException('unexpected where');
            }
            $this->menuName = (string)$value;
            return $this;
        }

        public function find()
        {
            return isset(Db::$rows[$this->menuName]) ? Db::$rows[$this->menuName] : null;
        }

        public function update(array $values)
        {
            if (!isset(Db::$rows[$this->menuName])) {
                return 0;
            }
            Db::$rows[$this->menuName] = array_merge(Db::$rows[$this->menuName], $values);
            return 1;
        }

        public function delete()
        {
            $exists = isset(Db::$rows[$this->menuName]);
            unset(Db::$rows[$this->menuName]);
            return $exists ? 1 : 0;
        }
    }
}

namespace crmeb\services {
    class CacheService
    {
        public static $deleted = array();

        public static function delete($key)
        {
            self::$deleted[] = (string)$key;
            return true;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/src/settings/SettingsException.php';
    require_once dirname(__DIR__) . '/src/settings/SecretStore.php';
    require_once dirname(__DIR__) . '/src/settings/SettingsRepository.php';

    use app\bailing\settings\SettingsException;
    use app\bailing\settings\SettingsRepository;
    use app\bailing\settings\SecretStore;
    use crmeb\services\CacheService;
    use think\facade\Db;

    $failed = 0;
    $checks = 0;

    function repositoryCheck($condition, $message)
    {
        global $failed, $checks;
        $checks++;
        if (!$condition) {
            fwrite(STDERR, "FAIL {$message}\n");
            $failed++;
        }
    }

    function configRow($value, $status = 1)
    {
        return array('value' => json_encode($value), 'status' => $status);
    }

    $oldToken = 'old-access-token-123456';
    $oldSecret = 'old-sign-secret-123456';
    $newToken = 'new-access-token-654321';
    $tempRoot = sys_get_temp_dir() . '/bailing-settings-repository-' . bin2hex(random_bytes(6));
    mkdir($tempRoot . '/runtime', 0755, true);
    $secretStore = new SecretStore($tempRoot);
    $secretStore->update(array(
        SecretStore::ACCESS_TOKEN => null,
        SecretStore::SIGN_SECRET => $oldSecret,
    ));
    Db::$rows = array(
        SettingsRepository::HUB_URL => configRow('https://hub.example.com'),
        SettingsRepository::CHAT_ENTRY => configRow('pub_existing'),
        SettingsRepository::LEGACY_ACCESS_TOKEN => configRow($oldToken, 1),
        SettingsRepository::LEGACY_SIGN_SECRET => configRow('must-not-migrate-sign-secret', 1),
        SettingsRepository::EMBED_CODE => configRow('<script>legacy pending input</script>'),
        SettingsRepository::LEGACY_ROUTE => configRow('unused-route'),
    );
    Db::$transactions = 0;
    CacheService::$deleted = array();

    $repository = new SettingsRepository($secretStore);
    $status = $repository->save(array(
        'chat' => null,
        'access_token' => $newToken,
        'sign_secret' => null,
    ));

    repositoryCheck(Db::$transactions === 1, 'save uses exactly one transaction');
    repositoryCheck(json_decode(Db::$rows[SettingsRepository::HUB_URL]['value'], true) === 'https://hub.example.com'
        && json_decode(Db::$rows[SettingsRepository::CHAT_ENTRY]['value'], true) === 'pub_existing', 'omitted chat settings are preserved');
    repositoryCheck($secretStore->get(SecretStore::ACCESS_TOKEN) === $newToken, 'non-empty token is saved only in the private store');
    repositoryCheck($secretStore->get(SecretStore::SIGN_SECRET) === $oldSecret, 'empty signing secret preserves the private-store value');
    repositoryCheck(!isset(Db::$rows[SettingsRepository::LEGACY_ACCESS_TOKEN])
        && !isset(Db::$rows[SettingsRepository::LEGACY_SIGN_SECRET]), 'legacy generic secret rows are deleted without migration');
    repositoryCheck(json_decode(Db::$rows[SettingsRepository::EMBED_CODE]['value'], true) === '', 'raw legacy embed input is cleared');
    repositoryCheck(!isset(Db::$rows[SettingsRepository::LEGACY_ROUTE]), 'unused legacy route row is deleted');
    repositoryCheck($status['access_token_configured'] === true && $status['sign_secret_configured'] === true, 'save returns configured flags only');
    repositoryCheck(strpos(json_encode($status), $newToken) === false && strpos(json_encode($status), $oldSecret) === false, 'save response does not expose either secret');

    $expectedCacheKeys = array_map(function ($name) {
        return 'system_config_' . $name;
    }, array(
        SettingsRepository::HUB_URL,
        SettingsRepository::CHAT_ENTRY,
        SettingsRepository::LEGACY_ACCESS_TOKEN,
        SettingsRepository::LEGACY_SIGN_SECRET,
        SettingsRepository::EMBED_CODE,
        SettingsRepository::LEGACY_ROUTE,
    ));
    repositoryCheck(CacheService::$deleted === $expectedCacheKeys, 'all affected CRMEB config caches are cleared');

    // hub 更新后 entry 缺失时必须回滚 hub，不能留下半套聊天配置。
    Db::$rows = array(
        SettingsRepository::HUB_URL => configRow('https://old.example.com'),
    );
    try {
        $repository->save(array(
            'chat' => array('hub_url' => 'https://new.example.com', 'entry_key' => 'pub_new'),
            'access_token' => null,
            'sign_secret' => null,
        ));
        repositoryCheck(false, 'missing required row rejects save');
    } catch (SettingsException $e) {
        repositoryCheck($e->httpStatus() === 409, 'missing required row returns a safe conflict');
    }
    repositoryCheck(json_decode(Db::$rows[SettingsRepository::HUB_URL]['value'], true) === 'https://old.example.com', 'failed paired chat update rolls back the first row');
    repositoryCheck($secretStore->get(SecretStore::ACCESS_TOKEN) === $newToken
        && $secretStore->get(SecretStore::SIGN_SECRET) === $oldSecret, 'failed database save leaves private secrets unchanged');

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($tempRoot, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tempRoot);

    if ($failed > 0) {
        exit(1);
    }
    echo 'Settings repository: ' . $checks . " checks passed\n";
}
