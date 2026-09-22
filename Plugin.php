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
        \Typecho\Plugin::factory('admin/header.php')->header = [self::class, 'injectStyle'];
        \Typecho\Plugin::factory('admin/footer.php')->begin = [self::class, 'printNotice'];
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
        $allow_ip = new Text(
            'allow_ip',
            null,
            null,
            _t('管理后台 IP 白名单'),
            _t('请输入 IP 地址，如果有多个请使用英文逗号隔开')
        );
        $form->addInput($allow_ip);

        /** 跳转链接 */
        $location_url = new Text(
            'location_url',
            null,
            'https://www.google.com/',
            _t('跳转链接'),
            _t('请输入标准的 URL 地址（包括 https:// 协议头），白名单外的 IP 访问后台将会跳转至这个 URL')
        );
        $form->addInput($location_url);
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
        $config = Helper::options()->plugin('WhiteIP');
        if (!empty($config->allow_ip)) {
            return $header;
        }

        $style = '<style>' . "\n"
            . '.whiteip-notice{box-sizing:border-box;width:100%;padding:12px 16px;background:#eafaf6;border:1px solid #1abc9c;border-radius:4px;text-align:center;line-height:1.5;}' . "\n"
            . '.whiteip-notice__text{font-size:14px;color:#1abc9c;font-weight:normal;}' . "\n"
            . '.whiteip-notice__link{font-size:14px;color:#1abc9c;text-decoration:underline;}' . "\n"
            . '.whiteip-notice__link:hover{text-decoration:none;}' . "\n"
            . '</style>';

        return $header . $style;
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

            if (empty($config->allow_ip)) {
                // 未配置白名单，标记需要显示横幅，由 printNotice() 负责构建并输出
                self::$showNotice = true;
            } else {
                // 紧急通道：插件目录下存在 skipipcheck 文件时放行所有地址
                if (file_exists(__DIR__ . '/skipipcheck')) {
                    return;
                }

                $allow_ip_arr = str_replace('，', ',', $config->allow_ip);
                $allow_ip = explode(',', $allow_ip_arr);

                $location_url = trim($config->location_url) ? trim($config->location_url) : 'https://www.google.com/ncr';
                if (!in_array('0.0.0.0', $allow_ip)) {
                    if (!in_array($real_ip, $allow_ip)) {
                        Cookie::delete('__typecho_uid');
                        Cookie::delete('__typecho_authCode');
                        @session_destroy();
                        header('Location: ' . $location_url);
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
        $config_url = rtrim($options->siteUrl, '/') . '/' . trim(__TYPECHO_ADMIN_DIR__, '/') . '/options-plugin.php?config=WhiteIP';
        $html = '<div class="whiteip-notice">'
            . '<span class="whiteip-notice__text">请先进行设置可访问后台白名单，</span>'
            . '<a href="' . $config_url . '" class="whiteip-notice__link">马上去设置</a>'
            . '</div>';

        echo '<script>document.body.insertAdjacentHTML("afterbegin", ' . json_encode($html) . ')</script>';
    }
}
