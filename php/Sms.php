<?php

namespace app\api\controller;

use app\api\library\Gmail;
use app\api\library\MailgunEmail;
use app\api\library\SendgridEmail;
use app\api\library\SendinblueEmail;
use app\common\controller\Api;
use app\common\library\Sms as Smslib;
use app\common\model\User;
use think\Cache;
use think\Cookie;
use think\Db;
use think\Exception;
use think\Hook;
use think\Lang;
use think\Log;

/**
 * 手机短信接口
 */
class Sms extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    /**
     * 发送验证码
     *
     * @ApiMethod (POST)
     * @param string $mobile 手机号
     * @param string $event 事件名称
     */
    public function send0099999()
    {
        $mobile = $this->request->post("mobile");
        $event = $this->request->post("event");
        $event = $event ? $event : 'register';

        if (!$mobile || !\think\Validate::regex($mobile, "^1\d{10}$")) {
            $this->error(__('手机号不正确'));
        }
        $last = Smslib::get($mobile, $event);
        if ($last && time() - $last['createtime'] < 60) {
            $this->error(__('发送频繁'));
        }
        $ipSendTotal = \app\common\model\Sms::where(['ip' => $this->request->ip()])->whereTime('createtime', '-1 hours')->count();
        if ($ipSendTotal >= 5) {
            $this->error(__('发送频繁'));
        }
        if ($event) {
            $userinfo = User::getByMobile($mobile);
            if ($event == 'register' && $userinfo) {
                //已被注册
                $this->error(__('已被注册'));
            } elseif (in_array($event, ['changemobile']) && $userinfo) {
                //被占用
                $this->error(__('已被占用'));
            } elseif (in_array($event, ['changepwd', 'resetpwd']) && !$userinfo) {
                //未注册
                $this->error(__('未注册'));
            }
        }
        if (!Hook::get('sms_send')) {
            $this->error(__('请在后台插件管理安装短信验证插件'));
        }
        $ret = Smslib::send($mobile, null, $event);
        if ($ret) {
            $this->success(__('发送成功'));
        } else {
            $this->error(__('发送失败，请检查短信配置是否正确'));
        }
    }

    /**
     * 检测验证码
     *
     * @ApiMethod (POST)
     * @param string $mobile 手机号
     * @param string $event 事件名称
     * @param string $captcha 验证码
     */
    public function check()
    {
        $mobile = $this->request->post("mobile");
        $event = $this->request->post("event");
        $event = $event ? $event : 'register';
        $captcha = $this->request->post("captcha");

        if (!$mobile || !\think\Validate::regex($mobile, "^1\d{10}$")) {
            $this->error(__('手机号不正确'));
        }
        if ($event) {
            $userinfo = User::getByMobile($mobile);
            if ($event == 'register' && $userinfo) {
                //已被注册
                $this->error(__('已被注册'));
            } elseif (in_array($event, ['changemobile']) && $userinfo) {
                //被占用
                $this->error(__('已被占用'));
            } elseif (in_array($event, ['changepwd', 'resetpwd']) && !$userinfo) {
                //未注册
                $this->error(__('未注册'));
            }
        }
        $ret = Smslib::check($mobile, $captcha, $event);
        if ($ret) {
            $this->success(__('成功'));
        } else {
            $this->error(__('验证码不正确'));
        }
    }

    public function sendmail(){
        $post = $this->request->post();
        //兼容没有邮箱的
        if(!isset($post['email']) || $post['email'] == ''){

        }
        //取登录用户的邮箱
        if($this->auth->isLogin()){
            $user = $this->auth->getUser();
            $user = Db::name('user')->find($user['id']);
            $post['email'] = $user['email'];
        }
        $result = $this->validate($post,'app\api\validate\Sms.sendmail');
        if(true !== $result){
            // 验证失败 输出错误信息
            $this->error($result);
        }
        //1分钟发送
        $has = Db::name('smscode')->where(['mail'=>$post['email']])->order('id desc')->find();
        if($has && $has['addtime'] + 50 > time()){
            $this->success(__('mail_send_success').'..', []);
        }
        //1天
        $todaycount = Db::name('smscode')->where(['mail'=>$post['email'], 'addtime'=>['gt', strtotime(date('Y-m-d'))]])->count();
        if($todaycount > 10){
            $this->success(__('mail_send_success').'....', []);
        }
        //快速点击缓存
        $ckey = 'sms_'.$post['email'];
        if(Cache::has($ckey)){
            $this->success(__('mail_send_success').'...', []);
        }else{
            Cache::set($ckey, 1, 10);
        }
        try{
            $num = rand(100012,999999);
            Db::name('smscode')->insertGetId(['mail'=>$post['email'], 'code'=>$num, 'addtime'=>time()]);
            $sub = 'FOXPAY important notice';
            $msg = file_get_contents(ROOT_PATH.'msg.html');
            $msg = str_replace('[code]', $num, $msg);
            //2.阿里云sendcloud发
            sendCloudEmail($post['email'], $sub,  $msg);

        }catch (\Exception $ex){
            $this->error($ex->getMessage());
        }
        $this->success(__('mail_send_success'), ['google_auth'=>$user['google_auth']??0]);
    }



}
