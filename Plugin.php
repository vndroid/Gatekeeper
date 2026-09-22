<?php

namespace TypechoPlugin\WhiteIP;

use Typecho\Cookie;
use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 后台访问管控插件 for Typecho
 *
 * @package WhiteIP
 * @author Vex
 * @version 1.2.0
 * @link https://github.com/vndroid/WhiteIP
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
        $config = Options::alloc()->plugin('WhiteIP');
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
     * 检测 IP 白名单
     *
     * @access public
     * @return void
     * @throws Exception
     */
    public static function check(): void
    {
        // 判断服务器是否允许 $_SERVER，不允许则使用 getenv 获取
        $real_ip = isset($_SERVER) ? $_SERVER['REMOTE_ADDR'] : getenv('REMOTE_ADDR');

        if ($real_ip !== null) {
            $config = Helper::options()->plugin('WhiteIP');

            if (empty($config->allowPool)) {
                // 未配置白名单，标记需要显示横幅，由 printNotice() 负责构建并输出
                self::$showNotice = true;
            } else {
                // 紧急通道：插件目录下存在 skipipcheck 文件时放行所有地址
                if (file_exists(__DIR__ . '/skipipcheck')) {
                    return;
                }

                $allowPoolArray = str_replace('，', ',', $config->allowPool);
                $allowPool = explode(',', $allowPoolArray);

                $rewriteUrl = trim($config->rewriteUrl) ? trim($config->rewriteUrl) : 'https://www.google.com/ncr';
                if (!in_array('0.0.0.0', $allowPool)) {
                    if (!in_array($real_ip, $allowPool)) {
                        Cookie::delete('__typecho_uid');
                        Cookie::delete('__typecho_authCode');
                        @session_destroy();
                        header('Location: ' . $rewriteUrl);
                        exit;
                    }
                }
            }
        }
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
