<?php
// +----------------------------------------------------------------------
// | BailingHub 工具 spec 注册中心（ACC 声明层入口）
// |
// | 架构原则：
// | 1. 元能力（原子能力）：一个工具 = 一个资源 + 一个动作。
// |    组合/编排是中枢 AI 的事，插件不声明工作流式的组合能力，
// |    否则中枢 AI 失去自由组合空间，只能死板调用。
// | 2. 声明即既有能力：插件声明的是 CRMEB 系统本来就有的业务能力
// |    （与后台按钮同一语义），不发明新接口、不创造新流程。
// | 3. 声明层独立：每个业务领域一个声明模块（src/spec/*Spec.php），
// |    升级/增删能力只动对应模块文件，互不影响。
// | 4. 权限归业务侧：票据只传递 CRMEB 管理员主体，每次工具执行都
// |    由 CRMEB 原生账号、角色和 system_menus 规则实时裁决。
// | 5. 版本化：SPEC_VERSION 随能力集合变更而递增，中枢可据此判断
// |    是否需要重新拉取 spec。
// +----------------------------------------------------------------------
namespace app\bailing;

use Bailing\Connect\ToolSpec;
use app\bailing\spec\ProductSpec;
use app\bailing\spec\OrderSpec;
use app\bailing\spec\RefundSpec;
use app\bailing\spec\UserSpec;
use app\bailing\spec\MarketingSpec;
use app\bailing\spec\FinanceSpec;
use app\bailing\spec\StatsSpec;
use app\bailing\spec\StoreSpec;

class BailingSpec
{
    /**
     * 能力集合版本（语义化版本）：
     * - MAJOR：破坏性变更（删除工具/改 path/改必填参数）
     * - MINOR：新增工具或可选参数（向后兼容）
     * - PATCH：描述/示例文案修订
     * 中枢重新拉取 spec 的依据，记录在 tools.json 的 info.version
     */
    const SPEC_VERSION = '2.3.0';

    /**
     * 已注册的能力声明模块（按业务领域）
     * 新增领域能力 = 新增一个实现 SpecModule 的类并在此登记
     * @var array<class-string>
     */
    protected static $modules = [
        ProductSpec::class,
        OrderSpec::class,
        RefundSpec::class,
        UserSpec::class,
        MarketingSpec::class,
        FinanceSpec::class,
        StatsSpec::class,
        StoreSpec::class,
    ];

    /**
     * 全部工具名 => spec path 映射（控制器验签与 spec 生成共用，单一数据源）
     */
    public static function toolPaths()
    {
        $paths = [];
        foreach (self::$modules as $module) {
            $paths = array_merge($paths, $module::paths());
        }
        return $paths;
    }

    /**
     * 构建工具 spec（工具源 tools.json）
     */
    public static function build()
    {
        $spec = ToolSpec::create('CRMEB 商城系统', self::SPEC_VERSION)
            ->authzProbe('/bailing/authz-probe');

        foreach (self::$modules as $module) {
            $module::register($spec);
        }

        return $spec->build();
    }
}
