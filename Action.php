<?php
/**
 * WeChatDraft Plugin
 * 发布文章同步提交微信公众号草稿
 *
 * @copyright  Copyright (c) 2023 心灵导师安德烈 (https://www.xvkes.cn)
 * @license    MIT License
 */
class WeChatDraft_Action extends Typecho_Widget implements Widget_Interface_Do
{

    /**
     * 重置mediaId
     */
    public function resetMediaId()
    {
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->pass('administrator')) {
            die('未登录用户!');
        }
        $file = dirname(__FILE__) . '/cache/mediaId';
        unlink($file);
        print('缓存清除成功!');
    }

    public function action()
    {

    }
}