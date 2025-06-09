<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * SiteMap 插件,开箱即用,在Typecho根目录生成 sitemap.xml
 * 避免每次请求都生成 sitemap.xml
 * 确保根目录有写入权限
 * @package SiteMap
 * @author 老孙
 * @version 1.0.3
 * @link https://www.imsun.org
 */
class SiteMap_Plugin implements Typecho_Plugin_Interface
{
    // 缓存文件路径
    const CACHE_FILE = '/sitemap.xml';
    const CACHE_TIME = 900; // 15分钟缓存,单位秒

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
        self::cache(true); // 强制首次激活生成
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
     * 生成缓存
     * 
     * @param bool $force 是否强制刷新缓存
     */
    public static function cache($force = false)
    {
        register_shutdown_function(function () use ($force) {
            $cacheFile = __TYPECHO_ROOT_DIR__ . self::CACHE_FILE;
            if ($force || !file_exists($cacheFile) || (time() - filemtime($cacheFile)) > self::CACHE_TIME) {
                file_put_contents($cacheFile, self::getContents());
            }
        });
    }

    /**
     * sitemap 入口, 可在路由处理里调用
     */
    public static function output()
    {
        $cacheFile = __TYPECHO_ROOT_DIR__ . self::CACHE_FILE;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TIME) {
            header('Content-Type: application/xml');
            readfile($cacheFile);
            exit;
        } else {
            $contents = self::getContents();
            file_put_contents($cacheFile, $contents);
            header('Content-Type: application/xml');
            echo $contents;
            exit;
        }
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
            $type = $row['type'];
            
            if ($type == 'post') {
                // 获取正确的永久链接（包含分类处理）
                $row['loc'] = self::getPostUrlWithCategory($row, $options);
            } else {
                // 对于页面
                $row['loc'] = Typecho_Router::url('page', $row, $options->index);
            }

            // 最终验证
            if (strpos($row['loc'], '[') !== false || strpos($row['loc'], '{') !== false) {
                $row['loc'] = $options->siteUrl . 'archives/' . $row['cid'] . '/';
            }

            $row['loc'] = htmlspecialchars($row['loc']);
            $row['lastmod'] = date('Y-m-d\TH:i:sP', $row['modified']);
            $row['changefreq'] = $changefreq;
            $row['priority'] = $priority;
        }
        unset($row);
        return $data;
    }

    private static function getPostUrlWithCategory($post, $options)
    {
        $db = Typecho_Db::get();
        
        // 1. 获取当前路由规则
        $route = $options->routingTable['post']['url'] ?? 'archives/[cid]/';
        
        // 2. 处理分类占位符
        if (strpos($route, '[category]') !== false || strpos($route, '{category}') !== false) {
            // 查询文章的第一个分类
            $category = $db->fetchRow($db->select('slug')
                ->from('table.metas')
                ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                ->where('table.relationships.cid = ?', $post['cid'])
                ->where('table.metas.type = ?', 'category')
                ->limit(1));
            
            $categorySlug = $category ? $category['slug'] : 'uncategorized';
            
            // 替换占位符
            $route = str_replace(
                ['[category]', '{category}'],
                $categorySlug,
                $route
            );
        }
        
        // 3. 替换其他占位符
        $url = str_replace(
            ['[cid]', '{cid}', '[slug]', '{slug}', 
             '[year]', '{year}', '[month]', '{month}', 
             '[day]', '{day}'],
            [
                $post['cid'], $post['cid'],
                $post['slug'], $post['slug'],
                date('Y', $post['created']), date('Y', $post['created']),
                date('m', $post['created']), date('m', $post['created']),
                date('d', $post['created']), date('d', $post['created'])
            ],
            $route
        );
        
        return rtrim($options->siteUrl, '/') . '/' . ltrim($url, '/');
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