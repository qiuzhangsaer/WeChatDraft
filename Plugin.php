<?php

/**
 * 发布文章同步提交微信公众号草稿
 *
 * @package WeChatDraft
 * @author 心灵导师安德烈
 * @version 1.0.0
 * @link https://www.xvkes.cn
 */
class WeChatDraft_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    /* 激活插件方法 */
    public static function activate(){
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('WeChatDraft_Plugin', 'render');
        Helper::addRoute('reset_mediaid', '/reset_mediaid', 'WeChatDraft_Action', 'resetMediaId');
    }

    /* 禁用插件方法 */
    public static function deactivate(){
        Helper::removeRoute('reset_mediaid');
        $dirPath = dirname(__FILE__) . '/cache';
        $files = glob($dirPath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file); // 删除文件
            }
        }
    }

    /* 插件配置方法 */
    public static function config(Typecho_Widget_Helper_Form $form){
        // 添加App ID字段
        $appid = new Typecho_Widget_Helper_Form_Element_Text('appid', NULL, '', _t('APPID'), _t('请填写微信公众号的APPID'));
        $form->addInput($appid);

        // 添加Secret字段
        $secret = new Typecho_Widget_Helper_Form_Element_Text('secret', NULL, '', _t('Secret'), _t('请填写微信公众号的Secret'));
        $form->addInput($secret);

        // 添加Author字段
        $author = new Typecho_Widget_Helper_Form_Element_Text('author', NULL, '', _t('作者'), _t('请填写文章作者，默认使用个人资料中的昵称，长度不得超过8个汉字</br> 如要更改封面图片，请在公众平台上传图片后点击<a href="' . Helper::options()->index . '/reset_mediaid">重置封面缓存</a>，后续使用时会自动获取新的图片'));
        $form->addInput($author);
    }

    /* 个人用户的配置方法 */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /* 获取微信access_token的方法 */
    public static function getAccessToken()
    {
        // 检查缓存中是否存在access_token
        $file = dirname(__FILE__) . '/cache/accessToken';
        $accessToken = file_exists($file) ? unserialize(file_get_contents($file)) : '';
        if (empty($accessToken) || self::isAccessTokenExpired($accessToken)) {
            // 如果缓存中不存在或已过期，重新请求获取access_token
            $newAccessToken = self::requestAccessToken();

            // 将新的access_token存储到缓存中
            file_put_contents($file, serialize($newAccessToken));

            return $newAccessToken->access_token;
        }

        return $accessToken->access_token;
    }

    /* 判断access_token是否过期的方法 */
    public static function isAccessTokenExpired($accessToken)
    {
        $time = time();
        if ($time > ($accessToken->expires_time)) {
            return true;
        }
        return false; // 假设access_token未过期
    }

    /* 请求获取新的微信access_token的方法 */
    public static function requestAccessToken()
    {
        $appid = Helper::options()->plugin('WeChatDraft')->appid;
        $secret = Helper::options()->plugin('WeChatDraft')->secret;
        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.$appid.'&secret='.$secret;

        $newAccessToken = self::curl($url);
        $newAccessToken->expires_time = time()+$newAccessToken->expires_in;

        return $newAccessToken;
    }

    /* 获取mediaid的方法 */
    public static function getMediaId(){
        $file = dirname(__FILE__) . '/cache/mediaId';
        $mediaId = file_exists($file) ? unserialize(file_get_contents($file)) : '';
        if (empty($mediaId)) {
            $accessToken = self::getAccessToken();
            $url = 'https://api.weixin.qq.com/cgi-bin/material/batchget_material?access_token='.$accessToken;
            // 获取图片素材列表中的图片作为图文消息的封面
            $array = [
                "type"=>"image",
                "offset"=>0,
                "count"=>20
            ];
            $mediaList = (self::curl($url,json_encode($array),true))->item;
            // return $mediaList;
            $matching = null;
            foreach ($mediaList as $media) {
                if ($media->name == "typecho.jpg") {
                    $matching = $entry;
                    break;
                }
            }
            if ($matching != null) {
                $media_id = $matching->media_id;
            } else {
                // 如果不存在匹配的条目，获取数组的第一个条目的media_id
                $media_id = $mediaList[0]->media_id;
            }
            file_put_contents($file, $media_id);
            return $media_id;
        }
        return $mediaId;

    }
    /* 上传图片到素材库 */
    public static function uploadImageToWeChat($html){
        $accessToken = self::getAccessToken();
        $url = 'https://api.weixin.qq.com/cgi-bin/media/uploadimg?access_token='.$accessToken;

        // 匹配所有的 <img> 标签
        preg_match_all('/<img[^>]+>/i', $html, $matches);
        $images = $matches[0];

        foreach ($images as $image) {
            // 提取 <img> 标签中的 src 属性值
            preg_match('/src="([^"]+)"/i', $image, $srcMatches);
            $src = $srcMatches[1];

            // 上传图片文件
            $res = self::curl($url,'',true,$src);

            // 获取上传后的图片 URL
            $wxImageUrl = $res->url;

            // 替换 HTML 中的图片标签中的 src 属性为上传后的图片 URL
            $html = str_replace($src, $wxImageUrl, $html);
            // 修复bug，替换HTML中的#为\#
            $html = str_replace('#', '\#', $html);
        }

        return $html;
    }

    /**
     * Curl 请求
     * @param $url
     */
    public static function curl($url,$jsonData = '',$ispost = false,$imagePath ='')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 忽略 SSL 证书验证
        if ($ispost) {
            // POST 请求
            curl_setopt($ch, CURLOPT_POST, true);

            if (empty($imagePath)) {
                $postData = $jsonData;
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                ));
            } else {
                $postData = array(
                    'media' => new CURLFile($imagePath)
                );
            }
            // 设置请求体数据为 JSON 字符串
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

            // 设置请求头为 application/json

        }

        $response = curl_exec($ch);
        curl_close($ch);

        $responseData = json_decode($response);
        if (!isset($responseData->errmsg)) {
            return $responseData;
        } else {
            // 请求失败，处理错误信息
            throw new Exception($responseData->errmsg);
        }
    }

    /* 插件实现方法 */
    public static function render($post,$obj){
        $setting = Helper::options()->plugin('WeChatDraft');
        if (empty($post['password']) && strlen($post['text']) > 100 && $setting->appid && $setting->secret) {
            $accessToken = self::getAccessToken();
            $url = 'https://api.weixin.qq.com/cgi-bin/draft/add?access_token='.$accessToken;
            if ($setting->author) {
                $author = $setting->author;
            } else {
                $user= Typecho_Widget::widget('Widget_User');
                $author = $user->screenName;
            }
            $mediaId = self::getMediaId();
            $html = self::uploadImageToWeChat($obj->content);
            $array = [
                "articles"=>[
                    [
                        "title"=>$obj->title,
                        "author"=>mb_strlen($author, 'UTF-8') > 8 ?mb_substr($author, 0, 8, 'UTF-8') :$author,
                        "content"=>$html,
                        "content_source_url"=>$obj->url,
                        "thumb_media_id"=>$mediaId
                    ]
                ]
            ];
            self::curl($url,json_encode($array, JSON_UNESCAPED_UNICODE),true);
        }
    }
}
