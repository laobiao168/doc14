<?php

namespace app\api\controller;

use app\api\logic\IndexLogic;
use app\api\logic\UserLogic;
use app\common\controller\Api;
use app\common\library\Ems;
use app\common\library\Sms;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\UserAccount;
use app\common\tron\TronBase;
use fast\Random;
use IEXBase\TronAPI\Tron;
use think\Cache;
use think\Config;
use think\Db;
use think\Exception;
use think\Validate;

/**
 * 会员接口
 */
class User extends Api
{
    protected $noNeedLogin = ['login', 'mobilelogin', 'register', 'changeemail', 'changemobile', 'third', 'forgotPassword'];
    protected $noNeedRight = '*';

    public function _initialize()
    {
        parent::_initialize();

        if (!Config::get('fastadmin.usercenter')) {
            $this->error(__('User center already closed'));
        }

    }

    /**
     * 会员中心
     */
    public function index()
    {
        $this->success('', ['welcome' => $this->auth->nickname]);
    }

    public function info()
    {
        $userinfo = $this->auth->getUser();
        $ck = 'user_info'.$userinfo['id'].'_'.$this->lang;
        if(Cache::has($ck)){
            $this->success('', Cache::get($ck));
        }
        $user = Db::name('user')->where(['id'=>$userinfo->id])->field('id,nickname,avatar,recode,email,walletaddr,transpwd,google_auth,lout')->find();
        //风险操作
        if($user['lout']){
            $this->error('logout', '', 402);
        }
        $user['avatar'] = getImgUrl($user['avatar']);
        if(strpos($user['email'], '@')!==false){
            $user['email'] = substr($user['email'], 0, 5).'***'.substr($user['email'],strpos($user['email'], '@'));
        }
        if($user['transpwd']!=''){
            $user['transset'] = 1;
        }else{
            $user['transset'] = 0;
        }
        unset($user['transpwd']);
        $user['appdown'] = getImgUrl('/uploads/fox.apk');
        $user['about'] = 'https://www.foxpayinc.com';
        //实名认证
        $smrz = Db::name('user_smrz')->where(['user_id'=>$user['id']])->find();
        $user['authstatus'] = $smrz['status']??-1;
        //红包提示语
        $hongbao = Db::name('hongbao')->field($this->lang.'_name as name')->where(['status'=>1])->find();
        $user['gifttip'] = $hongbao['name']??'';
        $user['apkurl'] = getImgUrl('/foxpay1.0.apk');
        Cache::set($ck, $user, 60);
        $this->success('', $user);
    }



    /**
     * 会员登录
     *
     * @ApiMethod (POST)
     * @param string $account  账号
     * @param string $password 密码
     */
    public function login()
    {
        $account = $this->request->post('email');
        $password = $this->request->post('password');
        if (!$account || !$password) {
            $this->error(__('Invalid parameters'));
        }
        $ret = $this->auth->login($account, $password);
        if ($ret) {
            $data = ['userinfo' => $this->auth->getUserinfo()];
            $this->success(__('Logged in successful'), $data);
        } else {
            $this->error($this->auth->getError());
        }
    }

    /**
     * 注册会员
     *
     * @ApiMethod (POST)
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $email    邮箱
     * @param string $mobile   手机号
     * @param string $code     验证码
     */
    public function register()
    {
        $recode = $this->request->post('number', '');
        $password = $this->request->post('password', '');
        $nickname = $this->request->post('nickname', '');
        $email = $this->request->post('email');
        $code = $this->request->post('code', '');
        if (!$nickname) {
            $this->error(__('reg_need_nickname'));
        }
        if (!Validate::is($email, "email")) {
            $this->error(__('email_geshi'));
        }
        if (!$password) {
            $this->error(__('reg_need_password'));
        }
        if (strlen($password) < 6) {
            $this->error(__('reg_need_password_len'));
        }
        if ($code=='') {
            $this->error(__('code_require'));
        }
        if ($recode=='') {
            //$recode = '100000';
            $this->error(__('invitation_code_require'));
        }
        $ret = Sms::mailcode($email, $code);
        if (!$ret) {
            $this->error(__('Captcha_is_incorrect'));
        }
        $ret = $this->auth->register($nickname, $password, $email, $recode);
        if ($ret) {
            $data = ['userinfo' => $this->auth->getUserinfo()];
            Sms::delCode($email, $code);
            $this->success(__('Sign up successful'), $data);
        } else {
            $this->error($this->auth->getError());
        }
    }

    /**
     * 退出登录
     * @ApiMethod (POST)
     */
    public function logout()
    {
        $this->auth->logout();
        $this->success(__('Logout_successful'));
    }

    /**
     * 修改会员个人信息
     *
     * @ApiMethod (POST)
     * @param string $avatar   头像地址
     * @param string $username 用户名
     * @param string $nickname 昵称
     * @param string $bio      个人简介
     */
    public function profile()
    {
        $user = $this->auth->getUser();
        $avatar = $this->request->post('avatar', '', 'trim,strip_tags,htmlspecialchars');
        $vo = [];
        if($avatar!=''){
            $vo['avatar'] = $avatar;
        }
        if($vo){
            Db::name('user')->where(['id'=>$user->id])->update($vo);
            UserLogic::clearCache($user->id);
        }
        $this->success(__('save_success'));
    }

    /**
     * 修改邮箱
     *
     * @ApiMethod (POST)
     * @param string $email   邮箱
     * @param string $captcha 验证码
     */
    public function changeemail()
    {
        $user = $this->auth->getUser();
        $email = $this->request->post('email');
        $captcha = $this->request->post('captcha');
        if (!$email || !$captcha) {
            $this->error(__('Invalid parameters'));
        }
        if (!Validate::is($email, "email")) {
            $this->error(__('Email is incorrect'));
        }
        if (\app\common\model\User::where('email', $email)->where('id', '<>', $user->id)->find()) {
            $this->error(__('Email already exists'));
        }
        $result = Ems::check($email, $captcha, 'changeemail');
        if (!$result) {
            $this->error(__('Captcha_is_incorrect'));
        }
        $verification = $user->verification;
        $verification->email = 1;
        $user->verification = $verification;
        $user->email = $email;
        $user->save();

        Ems::flush($email, 'changeemail');
        $this->success();
    }


    /**
     * 第三方登录
     *
     * @ApiMethod (POST)
     * @param string $platform 平台名称
     * @param string $code     Code码
     */
    public function third()
    {
        $url = url('user/index');
        $platform = $this->request->post("platform");
        $code = $this->request->post("code");
        $config = get_addon_config('third');
        if (!$config || !isset($config[$platform])) {
            $this->error(__('Invalid parameters'));
        }
        $app = new \addons\third\library\Application($config);
        //通过code换access_token和绑定会员
        $result = $app->{$platform}->getUserInfo(['code' => $code]);
        if ($result) {
            $loginret = \addons\third\library\Service::connect($platform, $result);
            if ($loginret) {
                $data = [
                    'userinfo'  => $this->auth->getUserinfo(),
                    'thirdinfo' => $result
                ];
                $this->success(__('Logged in successful'), $data);
            }
        }
        $this->error(__('Operation failed'), $url);
    }

    /**
     * 重置密码
     *
     * @ApiMethod (POST)
     * @param string $mobile      手机号
     * @param string $newpassword 新密码
     * @param string $captcha     验证码
     */
    public function resetpwd()
    {
        $post = $this->request->post();
        $user = $this->auth->getUser();
        $googlecode = $this->request->post('googlecode', '');
        $result = $this->validate($post,'app\api\validate\User.resetpwd');
        if(true !== $result){
            // 验证失败 输出错误信息
            $this->error($result);
        }
        $ret = Sms::mailcode($user->email, $post['code']??'');
        if (!$ret) {
            $this->error(__('Captcha_is_incorrect'));
        }
        $user = Db::name('user')->where(['id'=>$user['id']])->find();
        //风险操作
        if($user['dangerloginnum'] > 6){
            $this->error(__('account_login_failnums'));
        }
        //风险操作
        if($user['lout']){
            $this->error('logout', '', 402);
        }
        //谷歌验证
        if($user['google_auth']==1){
            require_once EXTEND_PATH.'GoogleAuthenticator/PHPGangsta/GoogleAuthenticator.php';
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
        }
        //模拟一次登录
        $this->auth->direct($user['id']);
        $ret = $this->auth->changepwd($post['newpassword'], '', true);
        if ($ret) {
            Sms::delCode($user['email'], $post['code']??'');
            $this->success(__('Reset_password_successful'));
        } else {
            $this->error($this->auth->getError());
        }
    }


    /**
     * 资金明细
     */
    public function moneylist(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        try {
            $data = IndexLogic::bonuslist($this->lang, $user['id'], $post);
        }catch (Exception $ex){
            $this->error(__('general_error'));
        }
        $this->success('ok', $data);
    }

    public function team(){
        $user = $this->auth->getUserinfo();
        $this->success('ok', UserLogic::myteam($user['id'], $this->lang));
    }


    public function teamlist(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        $this->success('ok', UserLogic::teamlist($user['id'], $post, $this->lang));
    }

    /**
     * 实时激活人数
     */
    public function teamlistactive(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        $this->success('ok', UserLogic::teamlistactive($user['id'], $post));
    }

    public function teambonuslist(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post(); //2.充；3提
        try{
            $data = UserLogic::myteamBonuslist($this->lang, $user, $post);
        }catch (Exception $ex){
            $this->error(__('general_error'));
        }
        $this->success('ok', $data);
    }


    public function forgotPassword()
    {
        $email = $this->request->post('email', '');
        $password = $this->request->post('password');
        $code = $this->request->post('code');
        $googlecode = $this->request->post('googlecode', '');
        if (!Validate::is($email, "email")) {
            $this->error(__('email_geshi'));
        }
        if (!$password) {
            $this->error(__('reg_need_password'));
        }
        if ($code=='') {
            $this->error(__('code_require'));
        }
        $ret = Sms::mailcode($email, $code);
        if (!$ret) {
            $this->error(__('Captcha_is_incorrect'));
        }
        $user = Db::name('user')->where(['email'=>$email])->find();
        if(!$user){
            $this->error(__('emailuser_not_found'));
        }
        //风险操作
        if($user['dangerloginnum'] > 6){
            $this->error(__('account_login_failnums'));
        }
        if($user['dangertime'] + 86400 > time()){
            $this->error(__('general_error'));
        }
        $salt = Random::alnum();
        $newpassword = $this->auth->getEncryptPassword($password, $salt);
        $data = ['forgetpwd' => 0, 'password' => $newpassword, 'salt' => $salt, 'loginpwd'=>time()];
        Db::name('user')->where(['email'=>$email])->update($data);
        Sms::delCode($email, $code);
        $this->success(__('general_success'));
    }



    public function settranspwd(){
        $post = $this->request->post();
        $googlecode = $this->request->post('googlecode', '');
        $user = $this->auth->getUser();
        $result = $this->validate($post,'app\api\validate\User.settranspwd');
        if(true !== $result){
            // 验证失败 输出错误信息
            $this->error($result);
        }
        $ret = Sms::mailcode($user->email, $post['code']??'');
        if (!$ret) {
            $this->error(__('Captcha_is_incorrect'));
        }
        $user = Db::name('user')->where(['id'=>$user['id']])->find();
        //风险操作
        if($user['dangerloginnum'] > 6){
            $this->error(__('account_login_failnums'));
        }
        //风险操作
        if($user['lout']){
            $this->error('logout', '', 402);
        }
        //谷歌验证
        if($user['google_auth']==1){
            require_once EXTEND_PATH.'GoogleAuthenticator/PHPGangsta/GoogleAuthenticator.php';
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
        }

        $ret = Db::name('user')->where(['id'=>$user['id']])->update(['transpwd'=>$post['transpwd'], 'transpwdtime'=>time(), 'transpwdcount'=>1]);
        if ($ret!==false) {
            UserLogic::clearCache($user['id']);

            Sms::delCode($user['email'], $post['code']??'');
            $this->success(__('Reset_password_successful'));
        } else {
            $this->error($this->auth->getError());
        }
    }

    public function resettranspwd()
    {
        $post = $this->request->post();
        $googlecode = $this->request->post('googlecode', '');
        $user = $this->auth->getUser();
        $result = $this->validate($post,'app\api\validate\User.resettranspwd');
        if(true !== $result){
            // 验证失败 输出错误信息
            $this->error($result);
        }
        $ret = Sms::mailcode($user->email, $post['code']??'');
        if (!$ret) {
            $this->error(__('Captcha_is_incorrect'));
        }
        $user = Db::name('user')->where(['id'=>$user['id']])->find();
        //风险操作
        if($user['dangerloginnum'] > 6){
            $this->error(__('account_login_failnums'));
        }
        //风险操作
        if($user['lout']){
            $this->error('logout', '', 402);
        }
        //谷歌验证
        if($user['google_auth']==1){
            require_once EXTEND_PATH.'GoogleAuthenticator/PHPGangsta/GoogleAuthenticator.php';
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
        }

        $ret = Db::name('user')->where(['id'=>$user['id']])->update(['transpwd'=>$post['transpwd'], 'transpwdtime'=>time(), 'transpwdcount'=>2]);
        if ($ret!==false) {
            Sms::delCode($user['email'], $post['code']??'');
            $this->success(__('Reset_password_successful'));
        } else {
            $this->error($this->auth->getError());
        }
    }


    public function getuserinfo(){
        $uid = $this->request->param('uid', 0);
        $user = Db::name('user')->field('id,recode,username,avatar,createtime')->where(['id'=>$uid])->find();
        if(!$user){
            $this->error(__('general_error'));
        }
        $user['avatar'] = getImgUrl($user['avatar']);
        $finance = Db::name('account_finance')->where(['user_id'=>$user['id'], 'tag'=>0])->find();
        $investment1 = Db::name('order')->where(['user_id'=>$user['id']])->sum('real_amount');
        $investment2 = Db::name('defiorder')->where(['user_id'=>$user['id']])->sum('real_amount');
        $user['total_investment'] = $investment1+$investment2;//总投资
        $user['total_profit'] = 0;
        if($finance){
            $user['total_profit'] = $finance['profit_usdt'];
        }
        $this->success('1', $user);
    }

    /**
     * 实名认证
     */
    public function verified(){
        $post = $this->request->post();
        $user = $this->auth->getUserinfo();
        $result = $this->validate($post,'app\api\validate\User.smrz');
        if(true !== $result){
            // 验证失败 输出错误信息
            $this->error($result);
        }
        $data['type'] = $post['type'];
        $data['name'] = $post['name'];
        $data['idno'] = $post['idno'];
        $data['card1'] = $post['card1'];
        $data['card2'] = $post['card2'];
        $data['zipai'] = $post['takephoto']??'';
        $has0 = Db::name('user_smrz')->where(['idno'=>$data['idno']])->find();
        if($has0){
            if($has0['user_id'] != $user['id']){
                $this->error(__('idno_used'));
            }
        }
        //是否认证
        $has = Db::name('user_smrz')->where(['user_id'=>$user['id']])->find();
        if($has){
            if($has['status'] == 0){
                $data['status'] = 1;
                $data['addtime'] = time();
                Db::name('user_smrz')->where(['user_id'=>$user['id']])->update($data);
            }
        }else{
            $data['status'] = 1;
            $data['addtime'] = time();
            $data['user_id'] = $user['id'];
            Db::name('user_smrz')->insertGetId($data);
        }
        $this->success(__('general_success'));

    }

    public function walletinfo(){
        $tag = $this->request->post('tag', 1);
        $user = $this->auth->getUserinfo();
        $where['user_id'] = $user['id'];
        if($tag == 1){
            //按月
            $starttime = date('Ym', strtotime('-12 month'));
            $endtime = date('Ym');
            $where['tag'] = ['between', [$starttime, $endtime]];
        }else{
            //按天
            $starttime = date('Ymd', strtotime('-30 day'));
            $endtime = date('Ymd');
            $where['tag'] = ['between', [$starttime, $endtime]];
        }
        $list = Db::name('account_finance')->where($where)->order('tag asc')->column('tag, income_usdt, expend_usdt, tag', 'tag');
        $idx2 = 30;
        $idx1 = 12;
        $data['label'] = [];
        $data['income'] = [];
        $data['expend'] = [];
        while (1){
            if($tag==1){
                $key = date('Ym', strtotime('-'.$idx1.' month'));
                $idx1--;
                $data['label'][] = substr($key,0,4).'-'.substr($key,4,2);
            }else{
                $key = date('Ymd', strtotime('-'.$idx2.' day'));
                $idx2--;
                $data['label'][] = substr($key,0,4).'-'.substr($key,4,2).'-'.substr($key,6,2);
            }
            if(isset($list[$key])){
                $data['income'][] = abs($list[$key]['income_usdt'])*1;
                $data['expend'][] = abs($list[$key]['expend_usdt'])*1;
            }else{
                $data['income'][] = 0;
                $data['expend'][] = 0;
            }
            if($idx1<0){
                break;
            }
            if($idx2<0){
                break;
            }
        }
        $this->success('ok', $data);
    }

    //获取团队用户的当你订单
    public function getuserrecord(){
        $user = $this->auth->getUserinfo();
        $uid = $this->request->param('uid', 0);
        $child = Db::name('user')->field('id,recode,username,avatar,createtime')->where(['id'=>$uid, 'reid'=>$user['id']])->find();
        if(!$child){
            //$this->error(__('general_error'));
        }
        $child['avatar'] = getImgUrl($child['avatar']);
        $lang = 'en';
        $list1 = Db::name('order o')
            ->field('o.id, o.order_sn, o.total_amount, o.runstatus, o.addtime, p.'.$lang.'_name as pname, o.huishoutime')
            ->join('product p', 'p.id = o.product_id', 'left')
            ->where(['o.user_id'=>$child['id']])
            ->order('o.runstatus asc, o.id desc')->select();
        $list2 = Db::name('defiorder o')
            ->field('o.id, o.order_sn, o.total_amount, o.runstatus, o.addtime, o.dtype, d.en_name, d.days, o.huishoutime')
            ->join('defi d', 'd.id = o.product_id', 'left')
            ->where(['o.user_id'=>$child['id']])
            ->order('o.runstatus asc, o.id desc')->select();
        $list3 = Db::name('minor_order o')
            ->field('o.id, o.order_sn, o.total_amount, o.runstatus, o.addtime, d.en_name, d.pl, o.endtime')
            ->join('minor d', 'd.id = o.minor_id', 'left')
            ->where(['o.user_id'=>$child['id']])
            ->order('o.runstatus asc, o.id desc')->select();
        $list4 = Db::name('tradingorder o')
            ->field('o.id, o.order_sn, o.num total_amount, o.runstatus, o.addtime, d.name, o.day, o.endtime')
            ->join('fa_trader d', 'd.id = o.trader_id', 'left')
            ->where(['o.user_id'=>$child['id']])
            ->order('o.runstatus asc, o.id desc')->select();
        $return = [];
        foreach ($list1 as $item){
            $vo = [];
            $vo['name'] = 'Ai Trading';
            $vo['typology'] = $item['pname'];
            $vo['amount'] = '$'.($item['total_amount']*1);
            $vo['runstatus'] = $item['runstatus'];
            $vo['endtime'] = $item['huishoutime'];
            $return[] = $vo;
        }
        foreach ($list2 as $item){
            $vo = [];
            $vo['name'] = 'DeFi';
            $vo['typology'] = __('coupon_djs', [$item['days']]);
            $vo['amount'] = '$'.($item['total_amount']*1);
            $vo['runstatus'] = $item['runstatus'];
            $vo['endtime'] = $item['huishoutime'];
            $return[] = $vo;
        }
        foreach ($list3 as $item){
            $vo = [];
            $vo['name'] = 'Airdrop';
            $vo['typology'] = $item['en_name'];
            $vo['amount'] = '$'.($item['total_amount']*1);
            $vo['runstatus'] = $item['runstatus'];
            $vo['endtime'] = $item['endtime'];
            $return[] = $vo;
        }
        foreach ($list4 as $item){
            $vo = [];
            $vo['name'] = 'Copy trading';
            $vo['typology'] = $item['name'];
            $vo['amount'] = '$'.($item['total_amount']*1);
            $vo['runstatus'] = $item['runstatus'];
            $vo['endtime'] = $item['endtime'];
            $return[] = $vo;
        }
        $datalist = [];
        foreach ($return as $item){
            if($item['runstatus'] == 1){
                array_unshift($datalist, $item);
            }else{
                array_push($datalist, $item);
            }
        }
        $child['data'] = $datalist;
        $this->success('1', $child);
    }


}
