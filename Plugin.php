<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 管理后台 IP 白名单
 * 
 * @package WhiteIP
 * @author Vex
 * @version 1.1.0
 * @link https://github.com/vndroid/WhiteIP
 */
class WhiteIP_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 插件版本号
     * @var string
     */
    const _VERSION = '1.1.0';
    
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('admin/common.php')->begin = array('WhiteIP_Plugin', 'check');
        Typecho_Plugin::factory('Widget_Login')->loginSucceed = array('WhiteIP_Plugin', 'check');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate(){}
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        /** 允许登陆后台的ip */
        $allow_ip = new Typecho_Widget_Helper_Form_Element_Text('allow_ip', NULL, NULL, _t('管理后台 IP 白名单'),'请输入 IP 地址，如果有多个请使用英文逗号隔开');
        $form->addInput($allow_ip);
        /** 跳转链接 */
        $location_url = new Typecho_Widget_Helper_Form_Element_Text('location_url', NULL, 'https://www.google.com/', _t('跳转链接'),'请输入标准的 URL 地址（包括 http://），白名单外的 IP 访问后台将会跳转至这个 URL');
        $form->addInput($location_url);
    }
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}
    
    /**
     * 检测 IP 白名单
     * 
     * @access public
     * @return void
     */
    public static function check()
    {
        static $real_ip = NULL;
        // 判断服务器是否允许 $_SERVER ，不允许则使用 getenv 获取
        $real_ip = isset($_SERVER) ? $_SERVER['REMOTE_ADDR'] : getenv("REMOTE_ADDR");

        if($real_ip !== NULL){
            // 兼容 Typecho 1.2 和 1.3+ 版本
            $plugin_config = Helper::options()->plugin('WhiteIP');
            if (is_string($plugin_config)) {
                // Typecho 1.2 及以前：返回序列化字符串
                $config = json_decode(json_encode(unserialize($plugin_config)));
            } else {
                // Typecho 1.3+：返回已反序列化的对象
                $config = (object)$plugin_config;
            }
            //var_dump($config);
            if(empty($config->allow_ip)) {
                $options = Typecho_Widget::widget('Widget_Options');
                $config_url = trim($options->siteUrl,'/').'/'.trim(__TYPECHO_ADMIN_DIR__,'/').'/options-plugin.php?config=WhiteIP';
                echo '<span style="text-align: center;display: block;margin: auto;font-size: 1.5em;color:#1abc9c">请先进行设置可访问后台白名单，<a href="'.$config_url.'">马上去设置</a></span>';
            } else {
                $allow_ip_arr = str_replace('，',',',$config->allow_ip);
                $allow_ip = explode(',', $allow_ip_arr);
                
                // 误操作紧急通道，去掉下行注释即可放行全部地址
                //$allow_ip[] = '0.0.0.0';
                
                $location_url = trim($config->location_url) ? trim($config->location_url) : 'https://www.google.com/';
                if(!in_array('0.0.0.0', $allow_ip)) {
                    if(!in_array($real_ip, $allow_ip)) {
                        Typecho_Cookie::delete('__typecho_uid');
                        Typecho_Cookie::delete('__typecho_authCode');
                        @session_destroy();
                        header('Location: '.$location_url);
                        exit;
                    }
                }
            }
        }
    }
}
