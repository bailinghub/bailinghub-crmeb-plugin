<?php
// +----------------------------------------------------------------------
// | 百灵中枢（BailingHub）接入服务
// | 安装本包后自动注册 /bailing/* 工具源路由 + 后台配置项 + 后台聊天组件
// +----------------------------------------------------------------------
namespace app\bailing;

use think\Service;

class BailingService extends Service
{
    /** CRMEB 独立适配器版本；与 tools.json 的能力集合版本分开维护。 */
    const ADAPTER_VERSION = PluginInfo::PLUGIN_VERSION;

    /** 插件自有配置/升级状态结构版本。 */
    const CONFIG_SCHEMA_VERSION = PluginInfo::CONFIG_SCHEMA_VERSION;

    /**
     * 聊天组件注入标记（用于与用户其他自定义 JS 共存，只维护标记块内的内容）
     */
    const WIDGET_MARK_BEGIN = '/* ==== bailinghub-widget-begin ==== */';
    const WIDGET_MARK_END = '/* ==== bailinghub-widget-end ==== */';

    /** @var string 本次请求中待应用嵌入代码的本地校验错误 */
    protected $embedConfigError = '';

    public function register()
    {
        // 注册本包路由（think-multi-app 剥离 bailing 后加载本文件）
        $this->registerRoutes(function () {
            include __DIR__ . '/route/route.php';
        });
    }

    public function boot()
    {
        // 2.4.1 的全新安全基线：旧版 system_config 秘密不迁移，启动即删除。
        $this->purgeLegacyGenericSecrets();
        // 注册后台配置项（系统设置 → 百灵中枢配置）
        $this->registerAdminConfig();
        // 应用安装器预填的中枢地址/聊天入口（仅当对应配置为空时回填）
        $this->applyPreset();
        // 把“完整嵌入代码”一次性拆成底层中枢地址 + entry key；纯本地解析，不发外网请求
        $this->syncPendingEmbedCode();
        // 注入后台聊天组件（直读 DB，避免 boot 阶段 Config 未加载到最新值）
        $this->injectChatWidget();
    }

    /**
     * 网页安装器允许用户预填中枢地址（写在 runtime/bailinghub_preset.json），
     * 这里在服务首次启动时回填进 bailing_hub_url 配置
     */
    protected function applyPreset()
    {
        try {
            $presetFile = $this->app->getRootPath() . 'runtime/bailinghub_preset.json';
            if (!is_file($presetFile)) {
                return;
            }
            $preset = json_decode((string)file_get_contents($presetFile), true);
            @unlink($presetFile);
            if (empty($preset['hub_url']) && empty($preset['chat_entry'])) {
                return;
            }
            if (!empty($preset['hub_url']) && $this->readConfigValue('bailing_hub_url') === '') {
                $hubUrl = HubUrl::normalize($preset['hub_url']);
                \think\facade\Db::name('system_config')->where('menu_name', 'bailing_hub_url')->update([
                    'value' => json_encode($hubUrl),
                ]);
                $this->clearConfigCache('bailing_hub_url');
            }
            if (!empty($preset['chat_entry']) && $this->readConfigValue('bailing_chat_entry') === '') {
                $entry = ChatEmbed::normalizeEntryKey($preset['chat_entry']);
                \think\facade\Db::name('system_config')->where('menu_name', 'bailing_chat_entry')->update([
                    'value' => json_encode($entry),
                ]);
                $this->clearConfigCache('bailing_chat_entry');
            }
        } catch (\Throwable $e) {
            // 预设回填失败不影响主流程
        }
    }

    /**
     * 往系统配置表插入 BailingHub 配置项（幂等）
     * 配置项自动出现在 CRMEB 后台「系统设置 → 百灵中枢配置」标签页
     */
    protected function registerAdminConfig()
    {
        try {
            if (!class_exists('\think\facade\Db')) {
                return;
            }

            $tabId = $this->ensureConfigTab();
            if (!$tabId) {
                return;
            }

            $configs = [
                'bailing_embed_code' => ['聊天入口嵌入代码（推荐）', 'textarea', '', '从 BailingHub 控制台「聊天入口 → 嵌入」复制不含临时 data-ticket 的标准代码；插件只提取中枢地址和 entry key，原始输入会立即清空', 100, 1],
                'bailing_hub_url' => ['百灵中枢地址（高级）', 'text', '', '一般无需手填；自建开源版可填写实例根地址；自建商业版或在线体验必须填写具体租户地址。在线体验不等同于商业版', 50, 1],
                'bailing_chat_entry' => ['聊天入口 key（高级）', 'text', '', '公开的聊天入口标识（如 pub_xxx），不是密码。单独填写时还必须同时填写正确的中枢地址', 10, 1],
            ];

            foreach ($configs as $menuName => [$info, $type, $default, $desc, $sort, $status]) {
                $exists = \think\facade\Db::name('system_config')->where('menu_name', $menuName)->find();
                if (!$exists) {
                    \think\facade\Db::name('system_config')->insert([
                        'menu_name' => $menuName,
                        'type' => $type,
                        'input_type' => 'input',
                        'config_tab_id' => $tabId,
                        'parameter' => '',
                        'upload_type' => 1,
                        'required' => '',
                        'width' => $type === 'textarea' ? 13 : 0,
                        'high' => $type === 'textarea' ? 4 : 0,
                        'value' => json_encode($default),
                        'info' => $info,
                        'desc' => $desc,
                        'sort' => $sort,
                        'status' => $status,
                    ]);
                } elseif (
                    (isset($exists['info']) ? $exists['info'] : '') !== $info
                    || (isset($exists['desc']) ? $exists['desc'] : '') !== $desc
                    || (isset($exists['type']) ? $exists['type'] : '') !== $type
                    || (int)(isset($exists['sort']) ? $exists['sort'] : 0) !== $sort
                    || (int)(isset($exists['status']) ? $exists['status'] : 0) !== $status
                    || ($type === 'textarea' && (
                        (int)(isset($exists['width']) ? $exists['width'] : 0) !== 13
                        || (int)(isset($exists['high']) ? $exists['high'] : 0) !== 4
                    ))
                ) {
                    // 只同步本插件自己的展示元数据，不触碰用户已保存的配置值。
                    \think\facade\Db::name('system_config')->where('menu_name', $menuName)->update([
                        'info' => $info,
                        'desc' => $desc,
                        'type' => $type,
                        'sort' => $sort,
                        'status' => $status,
                        'width' => $type === 'textarea' ? 13 : (int)(isset($exists['width']) ? $exists['width'] : 0),
                        'high' => $type === 'textarea' ? 4 : (int)(isset($exists['high']) ? $exists['high'] : 0),
                    ]);
                    $this->clearConfigCache($menuName);
                }
            }

            // bailing_route 从未被运行时读取；升级旧安装时直接删除历史行和缓存。
            \think\facade\Db::name('system_config')->where('menu_name', 'bailing_route')->delete();
            $this->clearConfigCache('bailing_route');
        } catch (\Throwable $e) {
            // 安装阶段数据库不可用时静默跳过（不影响包安装）
        }
    }

    /**
     * 删除旧版 generic 配置中的凭据且绝不迁移。
     *
     * CRMEB 的按名称配置接口可能回显隐藏行，因此 status=0 不是安全边界。新基线
     * 要求管理员在专用配置页重新输入秘密，由 SecretStore 保存到非 Web runtime。
     */
    protected function purgeLegacyGenericSecrets()
    {
        if (!class_exists('\think\facade\Db') || !class_exists('\crmeb\services\CacheService')) {
            throw new \RuntimeException('无法初始化百灵中枢秘密清理边界');
        }
        foreach (array('bailing_access_token', 'bailing_sign_secret') as $menuName) {
            try {
                \think\facade\Db::name('system_config')->where('menu_name', $menuName)->delete();
                $cacheKey = 'system_config_' . $menuName;
                \crmeb\services\CacheService::delete($cacheKey);
                // CRMEB 的 Redis/File 驱动在“键本来就不存在”时也可能返回 false，
                // 因此以删除后的实际存在性为准，不能把安全的空状态误判成故障。
                if (\crmeb\services\CacheService::has($cacheKey)) {
                    throw new \RuntimeException('配置缓存清理失败');
                }
            } catch (\Throwable $e) {
                // 旧行或缓存清理失败时阻止当前请求继续进入 generic 配置 API。
                // 下一次 boot 会重试；错误不包含任何秘密或数据库详情。
                throw new \RuntimeException('无法清理旧版百灵中枢秘密配置');
            }
        }
    }

    /**
     * 把推荐输入框里暂存的完整 embed script 一次性解析到两个底层字段。
     *
     * 成功或失败后都清空临时字段，避免整段 HTML 或临时票据进入长期配置。
     * 这里只做本地语法与地址安全校验，绝不在每次 boot 时阻塞探测外网。
     */
    protected function syncPendingEmbedCode()
    {
        try {
            $raw = $this->readConfigValue('bailing_embed_code');
            if (trim($raw) === '') {
                return;
            }
            $parsed = ChatEmbed::parse($raw, $this->readConfigValue('bailing_hub_url'));
            \think\facade\Db::transaction(function () use ($parsed) {
                \think\facade\Db::name('system_config')->where('menu_name', 'bailing_hub_url')->update([
                    'value' => json_encode($parsed['hub_url']),
                ]);
                \think\facade\Db::name('system_config')->where('menu_name', 'bailing_chat_entry')->update([
                    'value' => json_encode($parsed['entry_key']),
                ]);
                \think\facade\Db::name('system_config')->where('menu_name', 'bailing_embed_code')->update([
                    'value' => json_encode(''),
                ]);
            });
            foreach (array('bailing_hub_url', 'bailing_chat_entry', 'bailing_embed_code') as $name) {
                $this->clearConfigCache($name);
            }
        } catch (\Throwable $e) {
            // 失败时不改底层配置，并暂停注入，避免用户误以为刚粘贴的入口已经生效。
            $this->embedConfigError = $e->getMessage();
            try {
                \think\facade\Db::name('system_config')->where('menu_name', 'bailing_embed_code')->update([
                    'value' => json_encode(''),
                ]);
                $this->clearConfigCache('bailing_embed_code');
            } catch (\Throwable $ignored) {
                // 数据库本身不可写时无法继续清理；仍保持本次请求 fail-closed。
            }
        }
    }

    /**
     * 确保「百灵中枢配置」分组存在，返回 tab_id
     * 分组挂在「系统设置」(eng_title=system_config) 之下，作为其子标签页
     */
    protected function ensureConfigTab()
    {
        try {
            $tab = \think\facade\Db::name('system_config_tab')->where('eng_title', 'bailing_config')->find();
            if ($tab) {
                return $tab['id'];
            }
            // 找到「系统设置」顶级分组，把百灵配置挂为其子分组
            $pid = 0;
            $sysTab = \think\facade\Db::name('system_config_tab')->where('eng_title', 'system_config')->where('pid', 0)->find();
            if ($sysTab) {
                $pid = (int)$sysTab['id'];
            }
            return \think\facade\Db::name('system_config_tab')->insertGetId([
                'pid' => $pid,
                'eng_title' => 'bailing_config',
                'title' => '百灵中枢配置',
                'type' => 0,
                'status' => 1,
                'sort' => 0,
            ]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * custom_admin_js 只保留一个固定、无配置的同源加载器。
     *
     * 配置、聊天和升级逻辑全部由 /bailing/admin-bundle 提供；加载器不读取数据库，
     * 也不会把 hub、entry、token 或签名密钥写进 CRMEB 的通用自定义脚本配置。
     */
    protected function injectChatWidget()
    {
        try {
            $this->writeCustomJsBlock(self::adminLoaderJs());
        } catch (\Throwable $e) {
            // 加载器写入失败时保留 CRMEB 原生配置表单，不影响其他后台功能。
        }
    }

    /**
     * CRMEB custom_admin_js 中的微型加载器。必须保持小、固定且不含配置事实。
     */
    protected static function adminLoaderJs()
    {
        $template = <<<'JS'
(function(){
  if(window.__BAILING_ADMIN_LOADER__) return;
  window.__BAILING_ADMIN_LOADER__=true;
  var id='bailinghub-admin-bundle';
  if(document.getElementById(id)) return;
  var script=document.createElement('script');
  script.id=id;
  script.src=__BAILING_ADMIN_BUNDLE_URL__;
  script.async=true;
  script.setAttribute('data-bailing-admin-bundle','1');
  script.onerror=function(){
    console.error('[百灵中枢] 后台增强脚本加载失败；原生配置表单保持可用，请刷新页面或检查 /bailing/admin-bundle');
  };
  (document.head||document.documentElement).appendChild(script);
})();
JS;
        return str_replace(
            '__BAILING_ADMIN_BUNDLE_URL__',
            json_encode('/bailing/admin-bundle?v=' . rawurlencode(self::ADAPTER_VERSION), JSON_UNESCAPED_SLASHES),
            $template
        );
    }

    /**
     * AdminAssetController 的唯一脚本来源。
     *
     * 这里仅拼接固定前端代码和公开版本号，不读取任何 CRMEB 配置值。
     */
    public static function adminBundleJs()
    {
        return self::adminSettingsAndWidgetJs() . "\n" . self::pluginUpgradeManagerJs();
    }

    /**
     * 专用配置页、登录态聊天组件和连接摘要。
     *
     * GET /settings/status 只使用公开 hub/entry、两个 configured 布尔值、权限和 nonce；
     * token 与签名密钥输入框永远从空值开始，空值不会覆盖服务端现有配置。
     */
    protected static function adminSettingsAndWidgetJs()
    {
        return <<<'JS'
(function(){
  if(window.__BAILING_ADMIN_SETTINGS_BUNDLE__) return;
  window.__BAILING_ADMIN_SETTINGS_BUNDLE__=true;

  var state={status:null,statusPromise:null,widgetStarted:false,tokenWaits:0};

  function decodePart(value){try{return decodeURIComponent(value);}catch(e){return value;}}
  function cookieEntries(){
    var output=[];
    var parts=(document.cookie||'').split(/;\s*/);
    for(var i=0;i<parts.length;i++){
      if(!parts[i]) continue;
      var position=parts[i].indexOf('=');
      output.push([
        decodePart(position<0?parts[i]:parts[i].slice(0,position)),
        decodePart(position<0?'':parts[i].slice(position+1))
      ]);
    }
    return output;
  }
  function readAdminToken(){
    var token='';
    try{
      var entries=cookieEntries();
      for(var i=0;i<entries.length;i++){
        if(entries[i][0]==='from-crmeb-admin:token') token=entries[i][1];
      }
      if(!token){
        for(var j=0;j<entries.length;j++){
          if(entries[j][0]==='token'||/:token$/.test(entries[j][0])){token=entries[j][1];break;}
        }
      }
      token=token
        ||(window.localStorage?localStorage.getItem('from-crmeb-admin:token'):'')
        ||(window.localStorage?localStorage.getItem('token'):'')
        ||(window.sessionStorage?sessionStorage.getItem('from-crmeb-admin:token'):'')
        ||(window.sessionStorage?sessionStorage.getItem('token'):'')
        ||'';
    }catch(e){}
    return (token||'').replace(/^"|"$/g,'');
  }
  function tokenOrThrow(){
    var token=readAdminToken();
    if(!token) throw new Error('未读取到 CRMEB 管理员登录凭证，请重新登录后再试');
    return token;
  }
  function apiRequest(url,options){
    options=options||{};
    options.credentials='same-origin';
    options.cache='no-store';
    return fetch(url,options).then(function(response){
      return response.text().then(function(raw){
        var payload={};
        if(raw){
          try{payload=JSON.parse(raw);}catch(e){throw new Error('服务端返回了无法解析的响应（HTTP '+response.status+'）');}
        }
        if(!response.ok||!payload||Number(payload.status)!==1){
          throw new Error((payload&&(payload.msg||payload.message))||('HTTP '+response.status));
        }
        return payload.data&&typeof payload.data==='object'?payload.data:payload;
      });
    });
  }
  function truthy(value){return value===true||value===1||value==='1';}
  function normalizeStatus(data){
    data=data||{};
    return {
      hub_url:typeof data.hub_url==='string'?data.hub_url:'',
      entry_key:typeof data.entry_key==='string'?data.entry_key:'',
      access_token_configured:truthy(data.access_token_configured),
      sign_secret_configured:truthy(data.sign_secret_configured),
      can_manage:truthy(data.can_manage),
      nonce:data.nonce===undefined||data.nonce===null?'':String(data.nonce)
    };
  }
  function settingsStatus(force){
    if(!force&&state.statusPromise) return state.statusPromise;
    var token;
    try{token=tokenOrThrow();}catch(e){return Promise.reject(e);}
    var request=apiRequest('/bailing/settings/status',{
      method:'GET',
      headers:{'Authori-zation':'Bearer '+token}
    }).then(function(data){
      state.status=normalizeStatus(data);
      return state.status;
    }).catch(function(error){
      if(state.statusPromise===request) state.statusPromise=null;
      throw error;
    });
    state.statusPromise=request;
    return request;
  }
  function itemByLabel(fragment){
    var items=document.querySelectorAll('.ivu-form-item, .el-form-item');
    for(var i=0;i<items.length;i++){
      var label=items[i].querySelector('label');
      if(label&&(label.textContent||'').indexOf(fragment)>-1) return items[i];
    }
    return null;
  }
  function element(tag,text,style){
    var node=document.createElement(tag);
    if(text!==undefined&&text!==null) node.textContent=text;
    if(style) node.style.cssText=style;
    return node;
  }
  function setDisabled(control,disabled){
    control.disabled=!!disabled;
    control.style.opacity=disabled?'0.58':'1';
    control.style.cursor=disabled?'not-allowed':'';
  }
  function field(label,type,placeholder){
    var wrapper=element('label',null,'display:block;margin-bottom:14px');
    wrapper.appendChild(element('div',label,'font-size:13px;font-weight:600;color:#17233d;margin-bottom:6px'));
    var control=document.createElement(type==='textarea'?'textarea':'input');
    if(type!=='textarea') control.type=type;
    control.placeholder=placeholder||'';
    control.autocomplete=type==='password'?'new-password':'off';
    control.style.cssText='box-sizing:border-box;width:100%;min-height:'+(type==='textarea'?'92px':'38px')+';padding:8px 10px;border:1px solid #dcdee2;border-radius:5px;background:#fff;color:#17233d;font-size:13px;line-height:1.5;resize:vertical';
    wrapper.appendChild(control);
    return {root:wrapper,control:control};
  }
  function badge(label,ok){
    var node=element('span',(ok?'✓ ':'○ ')+label,'display:inline-flex;align-items:center;margin:0 8px 8px 0;padding:5px 9px;border-radius:999px;font-size:12px');
    node.style.background=ok?'#f0fff4':'#f8f8f9';
    node.style.color=ok?'#19a15f':'#808695';
    return node;
  }
  function setMessage(node,message,kind){
    node.textContent=message;
    node.style.color=kind==='ok'?'#19a15f':(kind==='error'?'#ed4014':'#808695');
  }
  function hideNativeForm(host,card){
    var items=host.querySelectorAll('.ivu-form-item, .el-form-item');
    for(var i=0;i<items.length;i++){
      if(!card.contains(items[i])){
        items[i].setAttribute('data-bailing-native-hidden','1');
        items[i].style.display='none';
      }
    }
    host.setAttribute('data-bailing-native-form-hidden','1');
  }
  function sourceGuide(container,canManage){
    container.appendChild(element('div','第一步：你现在从哪里使用百灵中枢？','font-size:14px;font-weight:600;color:#17233d;margin-bottom:9px'));
    var choices=element('div',null,'display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:9px;margin-bottom:10px');
    var help=element('div','请选择一种情况；无论哪一种，最后都要从对应租户控制台复制完整聊天入口嵌入代码。','padding:10px 12px;background:#f8f8f9;border-radius:5px;color:#515a6e;margin-bottom:16px');
    var options=[
      {
        id:'open_source',
        title:'我已经部署开源版',
        detail:'进入你部署的 BailingHub 控制台，在“聊天入口 → 嵌入”复制完整代码。'
      },
      {
        id:'self_hosted_tenant',
        title:'我自己部署了商业版，并已进入具体租户',
        detail:'先进入你自己部署的商业版并切换到具体租户，再从该租户控制台复制完整嵌入代码。'
      },
      {
        id:'online_trial',
        title:'我还没有以上环境',
        detail:'先申请在线体验租户；注册并进入分配给你的具体体验租户后，再从该租户控制台复制完整嵌入代码。在线体验只是体验环境，不等于商业版产品，也不是唯一接入地址。'
      }
    ];
    for(var i=0;i<options.length;i++){
      (function(option){
        var label=element('label',null,'display:flex;gap:8px;align-items:flex-start;padding:11px;border:1px solid #e8eaec;border-radius:6px;background:#fff;cursor:pointer');
        var radio=document.createElement('input');
        radio.type='radio';radio.name='bailinghub-source-guide';radio.value=option.id;
        radio.style.marginTop='3px';
        var title=element('span',option.title,'font-weight:600;color:#17233d;line-height:1.45');
        label.appendChild(radio);label.appendChild(title);choices.appendChild(label);
        radio.onchange=function(){
          help.textContent='';
          help.appendChild(document.createTextNode(option.detail));
          if(option.id==='online_trial'){
            help.appendChild(document.createTextNode(' '));
            var link=element('a','申请在线体验租户');
            link.href='https://trial.bailinghub.com/register/';link.target='_blank';link.rel='noopener noreferrer';
            link.style.cssText='color:#2d8cf0;font-weight:600';help.appendChild(link);
          }
        };
        if(!canManage) radio.setAttribute('aria-description','当前账号只读；此选项仅用于查看接入说明');
      })(options[i]);
    }
    container.appendChild(choices);container.appendChild(help);
  }
  function mountSettingsCard(status,anchor){
    if(document.querySelector('[data-bailing-settings-card]')||!document.documentElement.contains(anchor)) return;
    var host=anchor.closest?anchor.closest('.ivu-form, .el-form'):anchor.parentNode;
    if(!host) host=anchor.parentNode;
    if(!host) return;

    var current=status;
    var advancedDirty=false;
    var card=element('section',null,'box-sizing:border-box;width:100%;margin:0 0 22px;padding:20px;border:1px solid #e8eaec;border-radius:8px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.04);font-size:13px;line-height:1.65;color:#515a6e');
    card.setAttribute('data-bailing-settings-card','1');
    card.appendChild(element('h2','百灵中枢快速接入','margin:0 0 4px;color:#17233d;font-size:19px;line-height:1.4'));
    card.appendChild(element('div','只需要一段完整聊天入口代码，以及接入方 token 和工具源签名密钥。已有秘密不会回显，输入框留空即保持原值。','color:#808695;margin-bottom:16px'));
    sourceGuide(card,current.can_manage);

    card.appendChild(element('div','第二步：填写快速接入信息','font-size:14px;font-weight:600;color:#17233d;margin-bottom:9px'));
    var embed=field('完整聊天入口嵌入代码','textarea','<script src="https://你的中枢地址/widget.js" data-entry="pub_xxx"></script>');
    var access=field('接入方 token','password','');
    var secret=field('工具源签名密钥','password','');
    access.control.value='';secret.control.value='';
    card.appendChild(embed.root);card.appendChild(access.root);card.appendChild(secret.root);

    var advanced=document.createElement('details');
    advanced.style.cssText='margin:2px 0 16px;padding:10px 12px;border:1px solid #e8eaec;border-radius:6px;background:#fafafa';
    advanced.appendChild(element('summary','高级设置：分别填写中枢地址和聊天入口 key','cursor:pointer;font-weight:600;color:#515a6e'));
    var advancedBody=element('div',null,'padding-top:12px');
    var hub=field('百灵中枢地址','text','https://hub.example.com 或包含 /tenant/<租户ID> 的租户地址');
    var entry=field('聊天入口 key','text','pub_xxx');
    hub.control.value=current.hub_url;entry.control.value=current.entry_key;
    hub.control.oninput=entry.control.oninput=function(){advancedDirty=true;};
    advancedBody.appendChild(hub.root);advancedBody.appendChild(entry.root);advanced.appendChild(advancedBody);card.appendChild(advanced);

    var connections=element('div',null,'padding:11px 12px;background:#f8f8f9;border-radius:6px;margin-bottom:13px');
    var connectionTitle=element('div','当前连接配置','font-weight:600;color:#17233d;margin-bottom:7px');
    var connectionBadges=element('div');
    connections.appendChild(connectionTitle);connections.appendChild(connectionBadges);card.appendChild(connections);

    var saveButton=element('button','保存接入配置','border:1px solid #2d8cf0;background:#2d8cf0;color:#fff;border-radius:5px;padding:8px 16px;font-size:13px;cursor:pointer');
    saveButton.type='button';
    var saveMessage=element('span','', 'margin-left:10px;word-break:break-word');
    var saveRow=element('div',null,'display:flex;align-items:center;flex-wrap:wrap;margin-bottom:16px');
    saveRow.appendChild(saveButton);saveRow.appendChild(saveMessage);card.appendChild(saveRow);

    var next=element('div',null,'padding:13px 14px;border-left:3px solid #2d8cf0;background:#f5faff;border-radius:4px;margin-bottom:15px');
    next.appendChild(element('div','下一步：把商城登记为工具源','font-weight:600;color:#17233d;margin-bottom:5px'));
    next.appendChild(element('div','spec_url：'+location.origin+'/bailing/tools.json'));
    next.appendChild(element('div','base_url：'+location.origin));
    next.appendChild(element('div','访问策略：签名保护（signed_required）'));
    next.appendChild(element('div','签名密钥：与上方填写的工具源签名密钥保持一致；刷新后应看到正签 200 / 未签名 401 / 错签 401。'));
    card.appendChild(next);

    var maintenance=document.createElement('details');
    maintenance.setAttribute('data-bailing-maintenance','1');
    maintenance.style.cssText='padding:10px 12px;border:1px solid #e8eaec;border-radius:6px;background:#fafafa';
    maintenance.appendChild(element('summary','维护与升级','cursor:pointer;font-weight:600;color:#515a6e'));
    var upgradeHost=element('div');upgradeHost.setAttribute('data-bailing-upgrade-host','1');
    maintenance.appendChild(upgradeHost);card.appendChild(maintenance);

    function renderStatus(nextStatus){
      current=nextStatus;
      connectionBadges.textContent='';
      connectionBadges.appendChild(badge('聊天入口'+(current.hub_url&&current.entry_key?'已配置':'待配置'),!!(current.hub_url&&current.entry_key)));
      connectionBadges.appendChild(badge('接入方 token '+(current.access_token_configured?'已配置':'待配置'),current.access_token_configured));
      connectionBadges.appendChild(badge('签名密钥 '+(current.sign_secret_configured?'已配置':'待配置'),current.sign_secret_configured));
      connectionBadges.appendChild(badge(current.can_manage?'可维护':'当前账号只读',current.can_manage));
      access.control.placeholder=current.access_token_configured?'已配置；留空保持不变':'尚未配置，请填写';
      secret.control.placeholder=current.sign_secret_configured?'已配置；留空保持不变':'尚未配置，请填写';
      embed.control.placeholder=current.hub_url&&current.entry_key?'已配置聊天入口；粘贴新完整代码才会替换':'<script src="https://你的中枢地址/widget.js" data-entry="pub_xxx"></script>';
      var controls=[embed.control,access.control,secret.control,hub.control,entry.control];
      for(var i=0;i<controls.length;i++) setDisabled(controls[i],!current.can_manage);
      setDisabled(saveButton,!current.can_manage);
      if(!current.can_manage) setMessage(saveMessage,'普通管理员可查看状态，只有超级管理员可以修改。','info');
    }
    renderStatus(current);

    saveButton.onclick=function(){
      if(!current.can_manage) return;
      // 每次保存前都重新取一次 nonce，避免页面停留过久或多标签页刷新导致旧 nonce 失效。
      var ensureNonce=settingsStatus(true);
      setDisabled(saveButton,true);setMessage(saveMessage,'正在安全保存…','info');
      ensureNonce.then(function(fresh){
        current=fresh;
        var payload={nonce:current.nonce};
        var summary=[];
        var embedCode=(embed.control.value||'').trim();
        var accessToken=(access.control.value||'').trim();
        var signSecret=(secret.control.value||'').trim();
        if(embedCode){
          payload.embed_code=embedCode;summary.push('聊天入口');
        }else if(advancedDirty){
          var hubUrl=(hub.control.value||'').trim();
          var entryKey=(entry.control.value||'').trim();
          if(!hubUrl||!entryKey) throw new Error('高级设置中的中枢地址和聊天入口 key 必须成对填写');
          payload.hub_url=hubUrl;payload.entry_key=entryKey;summary.push('聊天入口');
        }
        if(accessToken){payload.access_token=accessToken;summary.push('接入方 token');}
        if(signSecret){payload.sign_secret=signSecret;summary.push('工具源签名密钥');}
        if(summary.length===0) throw new Error('没有需要保存的变更；秘密输入框留空会保持原值');
        var token=tokenOrThrow();
        return apiRequest('/bailing/settings/save',{
          method:'POST',
          headers:{'Authori-zation':'Bearer '+token,'Content-Type':'application/json'},
          body:JSON.stringify(payload)
        }).then(function(data){return {data:data,summary:summary};});
      }).then(function(result){
        var updated=normalizeStatus(result.data);
        state.status=updated;state.statusPromise=Promise.resolve(updated);current=updated;
        embed.control.value='';access.control.value='';secret.control.value='';
        hub.control.value=updated.hub_url;entry.control.value=updated.entry_key;advancedDirty=false;
        renderStatus(updated);
        setMessage(saveMessage,'保存成功：'+result.summary.join('、')+'。聊天入口变更后刷新后台即可使用。','ok');
        startWidget(updated);
      }).catch(function(error){
        access.control.value='';secret.control.value='';
        setMessage(saveMessage,'保存失败：'+(error&&error.message?error.message:'未知错误')+'。敏感输入已清空，请确认后重试。','error');
        settingsStatus(true).then(function(fresh){renderStatus(fresh);}).catch(function(){});
      }).then(function(){setDisabled(saveButton,!current.can_manage);});
    };

    host.insertBefore(card,host.firstChild);
    hideNativeForm(host,card);
  }
  function tryMountSettings(){
    if(document.querySelector('[data-bailing-settings-card]')) return;
    var anchor=itemByLabel('聊天入口嵌入代码');
    if(!anchor) return;
    settingsStatus(false).then(function(status){
      try{mountSettingsCard(status,anchor);}catch(error){
        console.error('[百灵中枢] 专用配置卡片初始化失败，已保留 CRMEB 原生配置表单：'+(error&&error.message?error.message:'未知错误'));
      }
    }).catch(function(error){
      console.warn('[百灵中枢] 无法读取专用配置状态，已保留 CRMEB 原生配置表单：'+(error&&error.message?error.message:'未知错误'));
    });
  }
  function startWidget(status){
    if(state.widgetStarted||!status||!status.hub_url||!status.entry_key) return;
    var token=readAdminToken();
    if(!token) return;
    state.widgetStarted=true;
    apiRequest('/bailing/chat-ticket',{
      method:'GET',
      headers:{'Authori-zation':'Bearer '+token}
    }).then(function(data){
      var ticket=data&&typeof data.ticket==='string'?data.ticket:'';
      if(!ticket) throw new Error('响应中没有登录票据');
      var script=document.createElement('script');
      script.src=status.hub_url.replace(/\/+$/,'')+'/widget.js';
      script.setAttribute('data-entry',status.entry_key);
      script.setAttribute('data-ticket',ticket);
      script.async=true;
      script.onerror=function(){console.error('[百灵中枢] widget.js 加载失败，请检查当前租户聊天入口地址');};
      document.body.appendChild(script);
    }).catch(function(error){
      state.widgetStarted=false;
      console.warn('[百灵中枢] 登录聊天组件未加载：'+(error&&error.message?error.message:'未知错误'));
    });
  }
  function startWithLogin(){
    if(!readAdminToken()){
      if(state.tokenWaits++<900) setTimeout(startWithLogin,1000);
      return;
    }
    settingsStatus(false).then(function(status){startWidget(status);tryMountSettings();})
      .catch(function(){tryMountSettings();});
  }

  if(typeof MutationObserver!=='undefined'){
    var timer;
    new MutationObserver(function(){clearTimeout(timer);timer=setTimeout(tryMountSettings,250);})
      .observe(document.body,{childList:true,subtree:true});
  }
  startWithLogin();
  tryMountSettings();
})();
JS;
    }

    /**
     * CRMEB「百灵中枢配置」页的版本与升级卡片。
     *
     * 升级包始终以原始 ZIP 请求体发送给同源受保护接口。这里不自动联网、
     * 不持久化管理员 token，也不把 token 写入 DOM 或日志。服务端负责验签、
     * 兼容性检查、备份、切换与失败回滚；前端只展示服务端返回的事实。
     */
    protected static function pluginUpgradeManagerJs()
    {
        $versions = json_encode([
            'adapter_version' => self::ADAPTER_VERSION,
            'tool_spec_version' => BailingSpec::SPEC_VERSION,
            'config_schema_version' => self::CONFIG_SCHEMA_VERSION,
        ]);

        $template = <<<'JS'
(function(){
  var EMBEDDED_CURRENT=__BAILING_PLUGIN_VERSIONS__;
  function decodePart(v){try{return decodeURIComponent(v);}catch(e){return v;}}
  function cookieEntries(){
    var out=[];
    var parts=(document.cookie||'').split(/;\s*/);
    for(var i=0;i<parts.length;i++){
      if(!parts[i]) continue;
      var pos=parts[i].indexOf('=');
      var rawName=pos<0?parts[i]:parts[i].slice(0,pos);
      var rawValue=pos<0?'':parts[i].slice(pos+1);
      out.push([decodePart(rawName),decodePart(rawValue)]);
    }
    return out;
  }
  function readToken(){
    var t='';
    try{
      var entries=cookieEntries();
      for(var i=0;i<entries.length;i++){
        if(entries[i][0]==='from-crmeb-admin:token') t=entries[i][1];
      }
      if(!t){
        for(var j=0;j<entries.length;j++){
          if(entries[j][0]==='token'||/:token$/.test(entries[j][0])){t=entries[j][1];break;}
        }
      }
      t=t
        ||(window.localStorage?localStorage.getItem('from-crmeb-admin:token'):'')
        ||(window.localStorage?localStorage.getItem('token'):'')
        ||(window.sessionStorage?sessionStorage.getItem('from-crmeb-admin:token'):'')
        ||(window.sessionStorage?sessionStorage.getItem('token'):'')
        ||'';
    }catch(e){}
    return (t||'').replace(/^"|"$/g,'');
  }
  function itemByLabel(fragment){
    var items=document.querySelectorAll('.ivu-form-item, .el-form-item');
    for(var i=0;i<items.length;i++){
      var label=items[i].querySelector('label');
      if(label&&(label.textContent||'').indexOf(fragment)>-1) return items[i];
    }
    return null;
  }
  function text(value,fallback){
    if(value===undefined||value===null||value==='') return fallback||'-';
    return String(value);
  }
  function errorMessage(error){
    if(!error) return '未知错误';
    if(error.data&&error.data.rolled_back===true){
      return (error.message||'升级失败')+'；已自动回滚到升级前版本';
    }
    return error.message||String(error);
  }
  function apiRequest(url,options){
    options=options||{};
    options.credentials='same-origin';
    options.cache='no-store';
    return fetch(url,options).then(function(response){
      return response.text().then(function(raw){
        var payload={};
        if(raw){
          try{payload=JSON.parse(raw);}catch(e){
            var malformed=new Error('服务端返回了无法解析的响应（HTTP '+response.status+'）');
            malformed.httpStatus=response.status;
            throw malformed;
          }
        }
        var ok=response.ok&&payload&&Number(payload.status)===1;
        if(!ok){
          var failure=new Error((payload&&(payload.msg||payload.message))||('HTTP '+response.status));
          failure.httpStatus=response.status;
          failure.data=payload&&payload.data?payload.data:payload;
          throw failure;
        }
        return payload.data&&typeof payload.data==='object'?payload.data:payload;
      });
    });
  }
  function makeButton(label,primary){
    var button=document.createElement('button');
    button.type='button';
    button.textContent=label;
    button.style.cssText='border:1px solid '+(primary?'#2d8cf0':'#dcdee2')+';background:'+(primary?'#2d8cf0':'#fff')+';color:'+(primary?'#fff':'#515a6e')+';border-radius:4px;padding:7px 14px;cursor:pointer;font-size:13px;line-height:1.4';
    return button;
  }
  function setButtonDisabled(button,disabled){
    button.disabled=!!disabled;
    button.style.cursor=disabled?'not-allowed':'pointer';
    button.style.opacity=disabled?'0.55':'1';
  }
  function valueRow(label){
    var row=document.createElement('div');
    row.style.cssText='min-width:160px;padding:10px 12px;background:#f8f8f9;border-radius:4px';
    var name=document.createElement('div');
    name.textContent=label;
    name.style.cssText='font-size:12px;color:#808695;margin-bottom:5px';
    var value=document.createElement('div');
    value.style.cssText='font-size:15px;color:#17233d;font-weight:600;word-break:break-word';
    row.appendChild(name);row.appendChild(value);
    return {root:row,value:value};
  }
  function formatLastUpgrade(value){
    if(!value) return '暂无升级记录';
    if(typeof value==='string') return value;
    var parts=[];
    if(value.status) parts.push(value.status==='success'?'成功':(value.status==='failed'?'失败':text(value.status)));
    if(value.to_version||value.version||value.adapter_version) parts.push('版本 '+text(value.to_version||value.version||value.adapter_version));
    if(value.message) parts.push(text(value.message));
    if(value.error) parts.push(text(value.error));
    if(value.timestamp||value.time||value.updated_at||value.finished_at) parts.push(text(value.timestamp||value.time||value.updated_at||value.finished_at));
    if(value.rolled_back===true) parts.push('已自动回滚');
    return parts.length?parts.join(' · '):'已有升级记录（服务端未提供摘要）';
  }
  function candidateVersion(candidate){
    candidate=candidate||{};
    return text(candidate.adapter_version||candidate.plugin_version||candidate.version,'未知版本');
  }
  function releaseNotes(candidate){
    candidate=candidate||{};
    var notes=candidate.release_notes||candidate.changelog||candidate.notes||'';
    if(typeof notes==='string') return notes||'升级包未提供更新说明';
    try{return JSON.stringify(notes,null,2);}catch(e){return '升级包未提供可读的更新说明';}
  }
  function summarizeCheck(value){
    if(value===undefined||value===null||value==='') return '未返回';
    if(typeof value==='string'||typeof value==='number'||typeof value==='boolean') return String(value);
    if(Array.isArray(value)){
      return value.map(function(item){
        if(typeof item==='string') return item;
        if(item&&typeof item==='object') return text(item.name||item.label||item.check,'检查项')+': '+text(item.message||item.status||(item.ok===true?'通过':(item.ok===false?'未通过':'')),'-');
        return String(item);
      }).join('\n');
    }
    var lines=[];
    Object.keys(value).forEach(function(key){
      var item=value[key];
      if(item&&typeof item==='object'){
        lines.push(key+': '+text(item.message||item.status||(item.ok===true?'通过':(item.ok===false?'未通过':'')),'已返回详细结果'));
      }else{
        lines.push(key+': '+String(item));
      }
    });
    return lines.join('\n')||'未返回';
  }
  function checksPassed(compatibility,checks){
    function failed(item){
      if(!item||typeof item!=='object') return false;
      if(item.ok===false||item.passed===false||item.compatible===false) return true;
      var status=String(item.status||'').toLowerCase();
      return status==='failed'||status==='failure'||status==='incompatible'||status==='error';
    }
    if(failed(compatibility)) return false;
    if(Array.isArray(checks)){
      for(var i=0;i<checks.length;i++) if(failed(checks[i])) return false;
    }else if(checks&&typeof checks==='object'){
      var keys=Object.keys(checks);
      for(var j=0;j<keys.length;j++) if(failed(checks[keys[j]])) return false;
    }
    return true;
  }
  function addUpgradeCard(){
    if(document.querySelector('[data-bailing-plugin-upgrade]')) return;
    var host=document.querySelector('[data-bailing-upgrade-host]');
    if(!host) return;

    var upgradeNonce='';
    var canUpgrade=null;
    var selectedFile=null;
    var stagedId='';
    var applyNonce='';
    var stagedVersion='';

    var card=document.createElement('div');
    card.setAttribute('data-bailing-plugin-upgrade','1');
    card.style.cssText='box-sizing:border-box;width:100%;margin:0 0 22px 0;padding:18px 20px;border:1px solid #e8eaec;border-radius:6px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.03);font-size:13px;line-height:1.6;color:#515a6e';
    var title=document.createElement('div');
    title.textContent='插件版本与升级';
    title.style.cssText='font-size:16px;font-weight:600;color:#17233d;margin-bottom:4px';
    var intro=document.createElement('div');
    intro.textContent='升级只更新百灵中枢 CRMEB 适配器。系统会先检查升级包，确认兼容后才允许执行。';
    intro.style.cssText='color:#808695;margin-bottom:14px';
    card.appendChild(title);card.appendChild(intro);

    var versions=document.createElement('div');
    versions.style.cssText='display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px';
    var adapterRow=valueRow('当前适配器版本');
    var specRow=valueRow('工具清单版本');
    var schemaRow=valueRow('配置结构版本');
    versions.appendChild(adapterRow.root);versions.appendChild(specRow.root);versions.appendChild(schemaRow.root);
    card.appendChild(versions);
    function renderCurrent(current){
      current=current||{};
      adapterRow.value.textContent=text(current.adapter_version,EMBEDDED_CURRENT.adapter_version);
      specRow.value.textContent=text(current.tool_spec_version,EMBEDDED_CURRENT.tool_spec_version);
      schemaRow.value.textContent=text(current.config_schema_version,EMBEDDED_CURRENT.config_schema_version);
    }
    renderCurrent(EMBEDDED_CURRENT);

    var last=document.createElement('div');
    last.style.cssText='padding:9px 11px;background:#f8f8f9;border-radius:4px;margin-bottom:12px;color:#515a6e;word-break:break-word';
    last.textContent='最近升级状态：尚未主动查询';
    card.appendChild(last);

    var fileInput=document.createElement('input');
    fileInput.type='file';fileInput.accept='.zip,application/zip';fileInput.style.display='none';
    var actions=document.createElement('div');
    actions.style.cssText='display:flex;gap:8px;align-items:center;flex-wrap:wrap';
    var refreshButton=makeButton('刷新升级状态',false);
    var chooseButton=makeButton('选择升级包',false);
    var stageButton=makeButton('检查升级包',false);
    var applyButton=makeButton('暂无可升级版本',true);
    setButtonDisabled(chooseButton,true);setButtonDisabled(stageButton,true);setButtonDisabled(applyButton,true);
    var fileName=document.createElement('span');
    fileName.textContent='尚未选择 ZIP';fileName.style.cssText='color:#808695;word-break:break-all';
    actions.appendChild(refreshButton);actions.appendChild(chooseButton);actions.appendChild(stageButton);actions.appendChild(applyButton);actions.appendChild(fileName);
    card.appendChild(fileInput);card.appendChild(actions);

    var operation=document.createElement('div');
    operation.style.cssText='margin-top:10px;color:#808695;word-break:break-word';
    operation.textContent='不会自动检查或下载新版本；只有点击按钮后才会向本站升级接口发起请求。';
    card.appendChild(operation);

    var candidateBox=document.createElement('div');
    candidateBox.style.cssText='display:none;margin-top:14px;padding:12px;border:1px solid #e8eaec;border-radius:4px;background:#fafafa';
    var candidateTitle=document.createElement('div');
    candidateTitle.style.cssText='font-weight:600;color:#17233d;margin-bottom:6px';
    var notes=document.createElement('div');
    notes.style.cssText='white-space:pre-wrap;word-break:break-word;margin-bottom:8px';
    var checks=document.createElement('div');
    checks.style.cssText='white-space:pre-wrap;word-break:break-word;color:#515a6e';
    candidateBox.appendChild(candidateTitle);candidateBox.appendChild(notes);candidateBox.appendChild(checks);card.appendChild(candidateBox);

    function tokenOrThrow(){
      var token=readToken();
      if(!token) throw new Error('未读取到 CRMEB 管理员登录凭证，请重新登录后再试');
      return token;
    }
    function fetchStatus(){
      var token;
      try{token=tokenOrThrow();}catch(e){return Promise.reject(e);}
      return apiRequest('/bailing/plugin-upgrade/status',{
        method:'GET',
        headers:{'Authori-zation':'Bearer '+token}
      }).then(function(data){
        canUpgrade=data.can_upgrade===true||data.can_upgrade===1||data.can_upgrade==='1';
        upgradeNonce=data.nonce===undefined||data.nonce===null?'':String(data.nonce);
        if(canUpgrade&&!upgradeNonce) throw new Error('服务端没有返回升级 nonce');
        renderCurrent(data.current||{});
        last.textContent='最近升级状态：'+formatLastUpgrade(data.last_upgrade);
        if(!canUpgrade){
          selectedFile=null;fileInput.value='';fileName.textContent='当前账号可查看版本；只有超级管理员可以执行升级';
          setButtonDisabled(chooseButton,true);setButtonDisabled(stageButton,true);setButtonDisabled(applyButton,true);
          applyButton.textContent='仅超级管理员可升级';
        }else{
          setButtonDisabled(chooseButton,false);
        }
        return data;
      });
    }
    refreshButton.onclick=function(){
      setButtonDisabled(refreshButton,true);operation.textContent='正在读取当前版本与最近升级状态…';operation.style.color='#808695';
      fetchStatus().then(function(){operation.textContent='升级状态已刷新。';operation.style.color='#19be6b';})
        .catch(function(e){operation.textContent='读取失败：'+errorMessage(e);operation.style.color='#ed4014';})
        .then(function(){setButtonDisabled(refreshButton,false);});
    };
    chooseButton.onclick=function(){fileInput.click();};
    fileInput.onchange=function(){
      selectedFile=fileInput.files&&fileInput.files[0]?fileInput.files[0]:null;
      stagedId='';applyNonce='';stagedVersion='';candidateBox.style.display='none';
      setButtonDisabled(applyButton,true);applyButton.textContent='暂无可升级版本';
      if(!selectedFile){fileName.textContent='尚未选择 ZIP';setButtonDisabled(stageButton,true);return;}
      if(!/\.zip$/i.test(selectedFile.name||'')){
        fileName.textContent='请选择 .zip 升级包';setButtonDisabled(stageButton,true);operation.textContent='文件格式不正确：只接受 ZIP 升级包。';operation.style.color='#ed4014';return;
      }
      fileName.textContent=selectedFile.name+'（'+Math.max(1,Math.ceil(selectedFile.size/1024))+' KB）';
      setButtonDisabled(stageButton,false);operation.textContent='升级包尚未检查。';operation.style.color='#808695';
    };
    stageButton.onclick=function(){
      if(!selectedFile) return;
      setButtonDisabled(stageButton,true);setButtonDisabled(applyButton,true);stageButton.textContent='检查中…';
      operation.textContent='正在校验升级包签名、版本、文件和运行环境…';operation.style.color='#808695';
      fetchStatus().then(function(){
        if(!canUpgrade) throw new Error('当前账号可查看版本；只有超级管理员可以执行升级');
        var token=tokenOrThrow();
        return apiRequest('/bailing/plugin-upgrade/stage',{
          method:'POST',
          headers:{
            'Authori-zation':'Bearer '+token,
            'X-Bailing-Upgrade-Nonce':upgradeNonce,
            'X-Bailing-Package-Name':encodeURIComponent(selectedFile.name||'plugin-update.zip'),
            'Content-Type':'application/zip'
          },
          body:selectedFile
        });
      }).then(function(data){
        stagedId=data.staged_id===undefined||data.staged_id===null?'':String(data.staged_id);
        applyNonce=data.apply_nonce===undefined||data.apply_nonce===null?'':String(data.apply_nonce);
        var candidate=data.candidate||{};
        stagedVersion=candidateVersion(candidate);
        candidateTitle.textContent='候选适配器版本：'+stagedVersion;
        notes.textContent='更新说明：\n'+releaseNotes(candidate);
        checks.textContent='兼容性：\n'+summarizeCheck(data.compatibility)+'\n\n检查结果：\n'+summarizeCheck(data.checks);
        candidateBox.style.display='block';
        if(!stagedId||!applyNonce) throw new Error('升级包检查响应缺少 staged_id 或 apply_nonce');
        if(!checksPassed(data.compatibility,data.checks)){
          operation.textContent='升级包检查未通过，不能执行升级。';operation.style.color='#ed4014';
          applyButton.textContent='兼容检查未通过';setButtonDisabled(applyButton,true);return;
        }
        applyButton.textContent='升级到 '+stagedVersion;setButtonDisabled(applyButton,false);
        operation.textContent='升级包检查通过。请查看更新说明，确认后再执行升级。';operation.style.color='#19be6b';
      }).catch(function(e){
        stagedId='';applyNonce='';stagedVersion='';setButtonDisabled(applyButton,true);applyButton.textContent='暂无可升级版本';
        operation.textContent='检查失败：'+errorMessage(e);operation.style.color='#ed4014';
      }).then(function(){stageButton.textContent='检查升级包';setButtonDisabled(stageButton,!selectedFile||canUpgrade===false);});
    };
    applyButton.onclick=function(){
      if(!stagedId||!applyNonce) return;
      if(!window.confirm('确认升级到 '+stagedVersion+'？升级期间请不要关闭页面或重复操作。')) return;
      var token;
      try{token=tokenOrThrow();}catch(e){operation.textContent=errorMessage(e);operation.style.color='#ed4014';return;}
      setButtonDisabled(applyButton,true);setButtonDisabled(stageButton,true);setButtonDisabled(chooseButton,true);setButtonDisabled(refreshButton,true);
      applyButton.textContent='升级中…';operation.textContent='正在备份、切换插件并执行健康检查…';operation.style.color='#808695';
      apiRequest('/bailing/plugin-upgrade/apply',{
        method:'POST',
        headers:{'Authori-zation':'Bearer '+token,'Content-Type':'application/json'},
        body:JSON.stringify({staged_id:stagedId,nonce:applyNonce})
      }).then(function(data){
        if(data.current) renderCurrent(data.current);
        if(data.success===false){
          operation.textContent=(data.message||'升级未成功')+(data.rolled_back===true?'；已自动回滚到升级前版本':'');
          operation.style.color='#ed4014';last.textContent='最近升级状态：'+operation.textContent;return;
        }
        operation.textContent=data.message||('已成功升级到 '+stagedVersion);operation.style.color='#19be6b';
        last.textContent='最近升级状态：'+operation.textContent;
      }).catch(function(e){
        operation.textContent='升级失败：'+errorMessage(e);operation.style.color='#ed4014';
        last.textContent='最近升级状态：'+operation.textContent;
      }).then(function(){
        stagedId='';applyNonce='';stagedVersion='';selectedFile=null;fileInput.value='';fileName.textContent='尚未选择 ZIP';candidateBox.style.display='none';
        applyButton.textContent='暂无可升级版本';setButtonDisabled(applyButton,true);setButtonDisabled(stageButton,true);setButtonDisabled(chooseButton,false);setButtonDisabled(refreshButton,false);
      });
    };

    card.style.margin='12px 0 0';
    host.appendChild(card);
    operation.textContent='正在确认当前账号的升级权限…';
    fetchStatus().then(function(){
      operation.textContent=canUpgrade?'升级状态已就绪。':'当前账号为只读；只有超级管理员可以升级。';
      operation.style.color=canUpgrade?'#19be6b':'#808695';
    }).catch(function(error){
      operation.textContent='读取升级状态失败：'+errorMessage(error);
      operation.style.color='#ed4014';
    });
  }
  if(typeof MutationObserver!=='undefined'){
    var timer;
    new MutationObserver(function(){clearTimeout(timer);timer=setTimeout(addUpgradeCard,300);})
      .observe(document.body,{childList:true,subtree:true});
  }
  addUpgradeCard();
})();
JS;

        return str_replace('__BAILING_PLUGIN_VERSIONS__', $versions, $template);
    }

    /**
     * 直接读取 system_config 表的配置值（已 json_decode）
     */
    protected function readConfigValue($menuName)
    {
        $row = \think\facade\Db::name('system_config')->where('menu_name', $menuName)->find();
        if (!$row) {
            return '';
        }
        $value = json_decode($row['value'], true);
        return is_string($value) ? $value : '';
    }

    /**
     * 把 JS 写入 custom_admin_js 配置项（标记块方式，幂等）
     * - 若用户已有其他自定义 JS，只替换标记块内的内容，不动其余部分
     * - 兼容旧版：若整段就是百灵注入脚本（无标记），整体替换为标记块版本
     * - 写入后清除 system_config 缓存，下一轮请求生效
     *
     * @param string $js 要注入的 JS（空字符串表示移除注入）
     */
    protected function writeCustomJsBlock($js)
    {
        try {
            $block = $js === '' ? '' : (self::WIDGET_MARK_BEGIN . "\n" . $js . "\n" . self::WIDGET_MARK_END);

            $row = \think\facade\Db::name('system_config')->where('menu_name', 'custom_admin_js')->find();
            if (!$row) {
                if ($block === '') {
                    return; // 无需注入也不存在配置项，直接返回
                }
                \think\facade\Db::name('system_config')->insert([
                    'menu_name' => 'custom_admin_js',
                    'type' => 'textarea',
                    'input_type' => 'input',
                    'config_tab_id' => $this->ensureConfigTab(),
                    'parameter' => '',
                    'upload_type' => 1,
                    'required' => '',
                    'width' => 0,
                    'high' => 0,
                    'value' => json_encode($block),
                    'info' => '自定义JS（含百灵中枢聊天组件）',
                    'desc' => '标记块内的内容由百灵中枢插件自动维护，请勿手动修改',
                    'sort' => 99,
                    'status' => 1,
                ]);
                $this->clearConfigCache('custom_admin_js');
                return;
            }

            $current = (string)json_decode($row['value'], true);

            // 已含标记块：替换块内容
            if (strpos($current, self::WIDGET_MARK_BEGIN) !== false) {
                $pattern = '/' . preg_quote(self::WIDGET_MARK_BEGIN, '/') . '.*?' . preg_quote(self::WIDGET_MARK_END, '/') . '/s';
                $new = trim(preg_replace($pattern, '', $current));
                $new = $block === '' ? $new : trim($new . "\n" . $block);
            } elseif (strpos($current, 'widget.js') !== false && strpos($current, 'bailing') !== false) {
                // 旧版整段即百灵注入脚本：整体升级为标记块版本
                $new = $block;
            } else {
                // 用户有其他自定义 JS：追加标记块
                $new = $block === '' ? $current : trim($current . "\n" . $block);
            }

            if ($new !== $current) {
                \think\facade\Db::name('system_config')->where('menu_name', 'custom_admin_js')->update([
                    'value' => json_encode($new),
                ]);
                $this->clearConfigCache('custom_admin_js');
            }
        } catch (\Throwable $e) {
            // 静默失败
        }
    }

    /**
     * 清除某个配置项的 sys_config 缓存（CRMEB 缓存键：system_config_{name}）
     */
    protected function clearConfigCache($menuName)
    {
        try {
            if (class_exists('\crmeb\services\CacheService')) {
                \crmeb\services\CacheService::delete('system_config_' . $menuName);
            }
        } catch (\Throwable $e) {
            // 缓存清除失败不致命（等缓存自然过期）
        }
    }
}
