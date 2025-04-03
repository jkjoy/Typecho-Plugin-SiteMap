<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * SiteMap
 * 
 * @package SiteMap
 * @author 老孙
 * @version 1.0.1
 * @link https://www.imsun.org
 */
class SiteMap_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        //挂载发布文章和页面的接口
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('SiteMap_Plugin', 'cache');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('SiteMap_Plugin', 'cache');
        self::cache();
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
     * 插件实现方法
     * 
     * @access public
     * @return void
     */
    public static function cache()
    {
        register_shutdown_function(function () {
            file_put_contents(__TYPECHO_ROOT_DIR__ . '/sitemap.xml', self::getContents());
        });
    }

    private static function getContents()
    {
        /** 初始化数据库 */
        $db = Typecho_Db::get();
        $archives = $db->fetchAll($db
            ->select('cid', 'slug', 'modified', 'type', 'created')
            ->from('table.contents')
            ->where('table.contents.status = ?', 'publish')
            ->where('table.contents.type = ?', 'post')
            ->order('table.contents.created', Typecho_Db::SORT_DESC)
        );
        $archives = self::generate($archives, 'weekly', 0.8);
        
        $pages = $db->fetchAll($db
            ->select('cid', 'slug', 'modified', 'type', 'created')
            ->from('table.contents')
            ->where('table.contents.status = ?', 'publish')
            ->where('table.contents.type = ?', 'page')
            ->order('table.contents.created', Typecho_Db::SORT_DESC)
        );
        $pages = self::generate($pages, 'monthly', 0.5);
        return self::generateXml(self::getUrls($archives) . self::getUrls($pages));
    }

    private static function generateXml($urls)
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">
' . $urls . '   
</urlset>';
    }

    private static function generate(array $data, $changefreq, $priority)
    {
        $options = Helper::options();

        foreach ($data as &$row) {
            //loc
            $type = $row['type'];
            
            if ($type == 'post') {
                // 构建自定义格式的 URL
                $path = str_replace(
                    array('{year}', '{month}', '{day}', '{cid}'),
                    array(
                        date('Y', $row['created']),
                        date('m', $row['created']),
                        date('d', $row['created']),
                        $row['cid']
                    ),
                    '/{year}{month}{day}{cid}/'
                );
                
                $row['pathinfo'] = $path;
            } else {
                // 对于页面，保持原有的路由方式
                $routeExists = (NULL != Typecho_Router::get($type));
                $row['pathinfo'] = $routeExists ? Typecho_Router::url($type, $row) : '#';
            }
            
            $row['loc'] = Typecho_Common::url($row['pathinfo'], $options->index);
            //lastmod
            $row['lastmod'] = date('Y-m-d\TH:i:sP', $row['modified']);
            //changefreq
            $row['changefreq'] = $changefreq;
            //priority
            $row['priority'] = $priority;
        }
        unset($row);
        return $data;
    }

    private static function getUrls(array $archives)
    {
        $urls = '';
        foreach ($archives as $archive) {
            $urls .= self::getUrl($archive);
        }
        return $urls;
    }

    private static function getUrl(array $content)
    {
        return "
    <url>
        <loc>{$content['loc']}</loc>
        <lastmod>{$content['lastmod']}</lastmod>
        <changefreq>{$content['changefreq']}</changefreq>
        <priority>{$content['priority']}</priority>
    </url>
        ";
    }
}