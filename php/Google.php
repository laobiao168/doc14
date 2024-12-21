<?php


namespace app\api\controller;


use app\api\logic\UserLogic;
use app\common\controller\Api;
use think\Db;

class Google extends Api
{

    protected $noNeedLogin = [];
    protected $noNeedRight = [];

    public function getQr(){
        $user = $this->auth->getUserinfo();
        require_once EXTEND_PATH.'GoogleAuthenticator/PHPGangsta/GoogleAuthenticator.php';
        $ga = new \PHPGangsta_GoogleAuthenticator();
        //密钥
        $secret = Db::name('user')->where(['id'=>$user['id']])->value('google_secret');
        if($secret==''){
            $secret = $ga->createSecret();
            Db::name('user')->where(['id'=>$user['id']])->update(['google_secret'=>$secret]);
        }
        //生成二维码
        $qrCodeUrl = $ga->getQRCodeGoogleUrl($user['username'], $secret);
        $this->success('ok', ['qrurl'=>$qrCodeUrl, 'secret'=>$secret]);
    }


    public function auth(){
        $user = $this->auth->getUserinfo();
        $userCode = $this->request->post('code', '');
        //风险操作
        if($user['dangerloginnum'] > 6){
            $this->error(__('account_login_failnums'));
        }
        //风险操作
        if($user['lout']){
            $this->error('logout', '', 402);
        }
        require_once EXTEND_PATH.'GoogleAuthenticator/PHPGangsta/GoogleAuthenticator.php';
        $ga = new \PHPGangsta_GoogleAuthenticator();
        //获取密钥
        $secret = Db::name('user')->where(['id'=>$user['id']])->value('google_secret');
        $sysCode = $ga->getCode($secret);
        $checkResult = $ga->verifyCode($secret, $sysCode, 2);
        if($checkResult){
            if($userCode!=$sysCode){
                $this->error(__('google_auth_error'));
            }else{
                Db::name('user')->where(['id'=>$user['id']])->update(['google_auth'=>1]);
                UserLogic::clearCache($user['id']);
                $this->success(__('google_auth_success'));
            }
        }else{
            $this->error(__('google_auth_error'));
        }
    }

    public function close(){
        $user = $this->auth->getUserinfo();
        $googlecode = $this->request->post('googlecode', '');
        require_once EXTEND_PATH.'GoogleAuthenticator/PHPGangsta/GoogleAuthenticator.php';
        $user = Db::name('user')->where(['id'=>$user['id']])->find();

        $ga = new \PHPGangsta_GoogleAuthenticator();
        $sysCode = $ga->getCode($user['google_secret']);
        $checkResult = $ga->verifyCode($user['google_secret'], $sysCode, 2);
        if($checkResult){
            if($googlecode!=$sysCode){
                $this->error(__('google_auth_error'));
            }
        }else{
            $this->error(__('google_auth_error'));
        }
        Db::name('user')->where(['id'=>$user['id']])->update(['google_auth'=>0]);
        UserLogic::clearCache($user['id']);

        $this->success(__('general_success'));
    }
}