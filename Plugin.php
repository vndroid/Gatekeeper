<?php

namespace TypechoPlugin\Gatekeeper;

use Typecho\Cookie;
use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 控制台 ACL 插件 for Typecho
 *
 * @package Gatekeeper
 * @author Vex
 * @version 1.0.0
 * @link https://github.com/vndroid/Gatekeeper
 */
class Plugin implements PluginInterface
{
    /**
     * 标记是否需要显示横幅，避免在 check() 中构建 HTML
     */
    private static bool $showNotice = false;
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     */
    public static function activate(): void
    {
        \Typecho\Plugin::factory('admin/common.php')->begin = [self::class, 'check'];
        \Typecho\Plugin::factory('admin/footer.php')->begin = [self::class, 'printNotice'];
        \Typecho\Plugin::factory('admin/header.php')->header = [self::class, 'injectStyle'];
        \Typecho\Plugin::factory('admin/menu.php')->navBar = [self::class, 'addAdminPageBar'];

        /**
         * admin/common.php 只在直接访问 /admin/*.php 时被 include，覆盖不到
         * /index.php/action/*（登录、XML-RPC、发文、传附件、改选项等真正的认证/写入接口）。
         * 额外挂在 index.php:begin（Router::dispatch() 之前），由 checkFront() 按请求目标判断是否拦截，
         * 避免让白名单外的 IP 靠重放已登录 cookie 或直接打 XML-RPC 绕过整个 ACL。
         */
        \Typecho\Plugin::factory('index.php')->begin = [self::class, 'checkFront'];
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @access public
     * @return void
     */
    public static function deactivate()
    {
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Form $form 配置面板
     * @return void
     */
    public static function config(Form $form): void
    {
        /** 允许登陆后台的ip */
        $allowPool = new Text(
            'allowPool',
            null,
            null,
            _t('管理后台访问白名单'),
            _t('请输入 IP 地址，多个请使用英文逗号分隔')
        );
        $form->addInput($allowPool);

        /** 跳转链接 */
        $rewriteUrl = new Text(
            'rewriteUrl',
            null,
            'https://www.google.com/',
            _t('跳转链接'),
            _t('请输入标准的 URL 地址（包括 https:// 协议头），白名单外的 IP 访问后台将会跳转至这个 URL')
        );
        $form->addInput($rewriteUrl);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 在后台导航栏插件状态显示
     * @throws Exception
     */
    public static function addAdminPageBar(): void
    {
        $config = Options::alloc()->plugin('Gatekeeper');
        if ($config->allowPool != '') {
            echo '<span class="message success">' . htmlspecialchars('ACL 已启用') . '</span>';
        } else {
            echo '<span class="message error">' . htmlspecialchars('ACL 未启用') . '</span>';
        }
    }

    /**
     * 向后台 <head> 注入插件独立样式
     *
     * @access public
     * @param string $header 当前 header 字符串
     * @return string
     * @throws Exception
     */
    public static function injectStyle(string $header): string
    {
        // 已配置白名单时因横幅不会显示也无需注入样式
        $config = Options::alloc()->plugin(basename(__DIR__));
        if (!empty($config->allowPool)) {
            return $header;
        } else {
            $cssFile = __DIR__ . '/inject.css';
            $cssContent = file_exists($cssFile) ? file_get_contents($cssFile) : '';
            $cssContent = preg_replace('/\/\*.*?\*\//s', '', $cssContent);
            $cssContent = preg_replace('/\s+/', ' ', $cssContent);
            $cssContent = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $cssContent);
            $cssContent = str_replace(';}', '}', $cssContent);
            return $header . '<style>' . $cssContent . '</style>';
        }
    }

    /**
     * 判断某个 IP 是否在白名单放行范围内。
     * 调用前需保证 $config->allowPool 非空。
     *
     * @access private
     * @param string $realIp
     * @param object $config
     * @return bool
     */
    private static function isIpAllowed(string $realIp, $config): bool
    {
        // 紧急通道：插件目录下存在 skipipcheck 文件时放行所有地址
        if (file_exists(__DIR__ . '/skipipcheck')) {
            return true;
        }

        $allowPoolArray = str_replace('，', ',', $config->allowPool);
        $allowPool = explode(',', $allowPoolArray);

        if (in_array('0.0.0.0', $allowPool)) {
            return true;
        }

        return in_array($realIp, $allowPool);
    }

    /**
     * 清空登录态并跳转到配置的 rewriteUrl，随后终止请求。
     *
     * @access private
     * @param object $config
     * @return void
     */
    private static function blockAndExit($config): void
    {
        $rewriteUrl = trim($config->rewriteUrl) ? trim($config->rewriteUrl) : 'https://www.google.com/ncr';
        Cookie::delete('__typecho_uid');
        Cookie::delete('__typecho_authCode');
        @session_destroy();
        header('Location: ' . $rewriteUrl);
        exit;
    }

    /**
     * 取当前请求的真实来源 IP，取不到时返回 null。
     *
     * @access private
     * @return string|null
     */
    private static function getRealIp(): ?string
    {
        // 判断服务器是否允许 $_SERVER，不允许则使用 getenv 获取
        return isset($_SERVER) ? $_SERVER['REMOTE_ADDR'] : getenv('REMOTE_ADDR');
    }

    /**
     * 检测 IP 白名单（挂在 admin/common.php:begin，覆盖直接访问 /admin/*.php 的请求）
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public static function check(): void
    {
        $real_ip = self::getRealIp();

        if ($real_ip !== null) {
            $config = Helper::options()->plugin('Gatekeeper');

            if (empty($config->allowPool)) {
                // 未配置白名单，标记需要显示横幅，由 printNotice() 负责构建并输出
                self::$showNotice = true;
            } elseif (!self::isIpAllowed($real_ip, $config)) {
                self::blockAndExit($config);
            }
        }
    }

    /**
     * P0 修复：挂在 index.php:begin（Router::dispatch() 之前），
     * 覆盖 admin/common.php 覆盖不到的 /index.php/action/*（登录、XML-RPC、发文、传附件、
     * 改选项、改用户等所有写入/认证接口）。
     *
     * 只在两种情况下拦截，其余请求（匿名访客提交评论、trackback、RSS 等）完全不受影响：
     *   1) 已登录的管理员/编辑会话，从非白名单 IP 发起请求——视为被窃取/重放的登录态；
     *   2) 命中 login / xmlrpc 这类"仅凭用户名密码即可现场获得管理员权限"的入口——
     *      即使当前请求没有登录 cookie，也按白名单拦截，避免绕开 cookie 直接用密码走
     *      XML-RPC（metaWeblog.* 等）拿到完整管理员权限。
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public static function checkFront(): void
    {
        $real_ip = self::getRealIp();
        if ($real_ip === null) {
            return;
        }

        $config = Helper::options()->plugin('Gatekeeper');
        if (empty($config->allowPool) || self::isIpAllowed($real_ip, $config)) {
            return;
        }

        if (User::alloc()->hasLogin() || self::isAuthEntryPoint()) {
            self::blockAndExit($config);
        }
    }

    /**
     * 判断当前请求是否命中 login / xmlrpc 这类可现场认证获得管理员权限的入口。
     * 此时路由尚未分发（本方法运行于 index.php:begin），Widget\Action 还没有把
     * action 参数解析出来，因此这里需要自行从 PATH_INFO / 请求参数里识别目标 action。
     *
     * @access private
     * @return bool
     */
    private static function isAuthEntryPoint(): bool
    {
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if (preg_match('#^/action/(login|xmlrpc)(?:/|$|\?)#', $pathInfo)) {
            return true;
        }

        $action = $_REQUEST['action'] ?? null;
        return in_array($action, ['login', 'xmlrpc'], true);
    }

    /**
     * 在 footer begin 钩子（位于 </body> 之前，即 <body> 内部）输出横幅
     * 通过 JS insertAdjacentHTML 将横幅插入到 <body> 最顶部，保证 HTML 结构合法
     *
     * @access public
     * @return void
     */
    public static function printNotice(): void
    {
        if (!self::$showNotice) {
            return;
        }

        $options = Options::alloc();
        $config_url = rtrim($options->siteUrl, '/') . '/' . trim(__TYPECHO_ADMIN_DIR__, '/') . '/options-plugin.php?config=' . basename(__DIR__);
        $html = '<div class="white-ip-plugin-notice">'
            . '<span class="white-ip-plugin-notice__text">请先进行设置可访问后台白名单，</span>'
            . '<a href="' . $config_url . '" class="white-ip-plugin-notice__link">马上去设置</a>'
            . '</div>';
        $template = '<script>document.body.insertAdjacentHTML("afterbegin", ' . json_encode($html) . ')</script>';

        echo $template;
    }
}
