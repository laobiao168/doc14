<?php


namespace app\api\controller;


use app\api\library\Ckey;
use app\api\library\Help;
use app\api\logic\OrderLogic;
use app\api\logic\UserLogic;
use app\api\logic\WalletLogic;
use app\common\controller\Api;
use app\common\library\Sms;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\CurrencyAction;
use app\common\model\walletuser\UserAccount;
use app\common\tron\TronBase;
use think\Cache;
use think\Config;
use think\Db;
use think\Exception;
use think\Log;
use think\Queue;

class Wallet extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];


    public function bindWallet(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->param();
        $result = $this->validate($post,'app\api\validate\Wallet.bindWallet');
        if(true !== $result){
            // 验证失败 输出错误信息
            $this->error($result);
        }
        $ret = Sms::mailcode($user['email'], $post['code']);
        if (!$ret) {
            $this->error(__('Captcha_is_incorrect'));
        }
        //风险操作
        if($user['dangerloginnum'] > 6){
            $this->error(__('account_login_failnums'));
        }
        //风险操作
        if($user['lout']){
            $this->error('logout', '', 402);
        }
        //别人是否绑定
        $hw['user_id'] = ['neq', $user['id']];
        $hw['addr'] = trim($post['addr']);
        if(substr($hw['addr'], 0, 1) != 'T'){
            $this->error(__('tronaddr_error'));
        }
        $hasother = Db::name('user_walletaddr')->where($hw)->find();
        if($hasother){
            $this->error(__('wallet_addr_hasbeused'));
        }
        $walletaddr = Db::name('user_walletaddr')->where(['user_id'=>$user['id']])->find();
        if($walletaddr){
            //$this->error(__('usdt_has_bind'));
            //修改
            Db::name('user_walletaddr')->where(['user_id'=>$user['id']])->update(['addr'=>trim($post['addr']), 'uptime'=>time()]);
        }
        $vo['user_id'] = $user['id'];
        $vo['addr'] = $post['addr'];
        $vo['addtime'] = time();
        Db::name('user_walletaddr')->insertGetId($vo);
        Sms::delCode($user['email'], $post['code']);
        $this->success(__('walletaddr_bind_success'));
    }


    /**
     * 提现页面规则接口
     */
    public function withdrawpage(){
        $user = $this->auth->getUserinfo();
        //余额
        $data['balance'] = UserAccount::getBalance($user['id']);
        $config = Db::name('configreward')->column('value', 'name');

        $data['wusdtsplit'] = $config['wusdtsplit'];
        $data['wusdtfee2'] = $config['wusdtfee2'];

        //手续费百分比type=1
        $data['widthdraw_usdt_fee'] = $config['withdrawefee'];
        $data['widthdraw_fox_fee'] = $config['foxwithdrawefee'];
        $data['widthdraw_idr_fee'] = $config['idrwithdrawefee'];
        $data['transfer_usdt_fee'] = $config['transfee'];
        $data['transfer_fox_fee'] = $config['foxtransfee'];
        //固定手续费type=2;
        $data['withdrawefee_type'] = $config['withdrawefee_type'];
        $data['foxwithdrawefee_type'] = $config['foxwithdrawefee_type'];
        $data['idrwithdrawefee_type'] = $config['idrwithdrawefee_type'];
        //获取优惠券
        $coupon = Db::name('user_coupons uc')
            ->field('uc.*')
            ->join('coupons c','c.id = uc.coupon_id', 'left')
            ->where(['uc.user_id'=>$user['id'], 'uc.status'=>1, 'c.status'=>1, 'c.position'=>3])
            ->find();
        if($coupon){
            $data['coupon'] = $coupon['amount'];
        }else{
            $data['coupon'] = 0;
        }
        $data['fox_usdt'] = $config['fox_usdt'];
        //实名认证
        $smrz = Db::name('user_smrz')->where(['user_id'=>$user['id']])->find();
        if($smrz && ($smrz['status'] == 2 || $smrz['status'] == 1)){
            $data['auth'] = 1;
        }else{
            $data['auth'] = 0;
            $data['auth_usdt_tip'] = __('smrz_tixian_usdt', [$config['smrz_tixian_usdt']]);
            $data['auth_fox_tip'] = __('smrz_txian_fox', [$config['smrz_tixian_fox']]);
        }
        $this->success('ok', $data);
    }



    public function withdraw(){
        $user = $this->auth->getUserinfo();
        $ck = 'tixian_'.$user['id'];
        if(Cache::has($ck)){
            //$this->error(__('operating_rate'));
            exit;
        }
        //风险操作
        if($user['dangerloginnum'] > 6){
            $this->error(__('account_login_failnums'));
        }
        //风险操作
        if($user['lout']){
            $this->error('logout', '', 402);
        }
        $post = $this->request->param();
        if(!isset($post['type'])){
            $post['type'] = 1; //1usdt;2fox
        }
        if(!in_array($post['type'], [1,2,3])){
            $post['type'] = 1;
        }
        //参数验证
        if(in_array($post['type'], [1,2])){
            $result = $this->validate($post,'app\api\validate\Wallet.withdraw');
            if(true !== $result){
                // 验证失败 输出错误信息
                $this->error($result);
            }
            //提现地址
            $tapi = new TronBase();
            if(!$tapi->getTron()->isAddress($post['address'])){
                $this->error(__('address_error'));
            }
        }else{
            if(!isset($post['uname']) || $post['uname']==''){
                $this->error(__('ynpay_withdraw_namerequire'));
            }
            if(!isset($post['cardNo']) || $post['cardNo']==''){
                $this->error(__('ynpay_withdraw_cardrequire'));
            }
            if(!isset($post['bankCode']) || $post['bankCode']==''){
                $this->error(__('ynpay_withdraw_bankrequire'));
            }
        }

        $transpwd = Db::name('user')->where(['id'=>$user['id']])->value('transpwd');
        if($transpwd == ''){
            $this->error(__('pleaseset_transpassword'), null, 2);
        }

        Cache::set($ck, 2, 2);
        //提现金额小于10u
        Db::startTrans();
        $res = [];
        try {
            $res = UserLogic::withdraw($user['id'], $post, $this->lang);
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            $this->error($ex->getMessage());
        }
        $this->success(__('general_success'), $res);
    }







    /**
     * 获取收益信息
     */
    public function profit(){
        $user = $this->auth->getUserinfo();
        $ck = 'user_'.$user['id'].'_wallet_profit';
        if(Cache::has($ck)){
            $this->success('1', Cache::get($ck));
        }else{
            $config = Db::name('configreward')->column('value', 'name');
            $finance = Db::name('account_finance')->where(['user_id'=>$user['id'], 'tag'=>0])->find();
            $balance = UserAccount::getBalance($user['id']);
            $investment1 = Db::name('order')->where(['user_id'=>$user['id']])->sum('real_amount');
            $investment2 = Db::name('defiorder')->where(['user_id'=>$user['id']])->sum('real_amount');
            $investment3 = Db::name('minor_order')->where(['user_id'=>$user['id']])->sum('real_amount');
            $data['total_investment'] = Help::formatUsdtFloor($investment1+$investment2+$investment3);//总投资
            $data['total_profit'] = 0;//总收益
            $data['total_assets'] = Help::formatUsdtFloor($balance[Currency::USDT] + ($balance[Currency::FOX] * $config['fox_usdt']));//可提现
            $balance[Currency::FOX.'_usdt'] = Help::formatUsdtFloor($balance[Currency::FOX] * $config['fox_usdt']);
            $data['balance'] = $balance;
            if($finance){
                $data['total_profit'] = $finance['profit_usdt'];
            }
            Cache::set($ck, $data, 30);
        }
        $this->success('1', $data);
    }


    /**
     * 转出
     */
    public function transfer(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->param();
        if(!isset($post['type'])){
            $post['type'] = 1;//1usdt;2fox
        }
        if(!in_array($post['type'], [1,2])){
            $post['type'] = 1;
        }
        $result = $this->validate($post,'app\api\validate\Wallet.transfer');
        if(true !== $result){
            // 验证失败 输出错误信息
            $this->error($result);
        }
        //验证交易密码
        $user = Db::name('user')->where(['id'=>$user['id']])->find();
        if($user['transpwd'] == ''){
            $this->error(__('pleaseset_transpassword'), null, 2);
        }
        //转账功能
        if($user['transfer_open'] == 0){
            $this->error(__('transfer_stop'));
        }
        //提现金额小于10u
        Db::startTrans();
        $res = [];
        try {
            $res = UserLogic::transfer($user['id'], $post, $this->lang);
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            $this->error($ex->getMessage());
        }
        $this->success(__('general_success'), $res);
    }


    /**
     * 兑换
     */
    public function swap(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->param();
        if(!isset($post['type'])){
            $post['type'] = 1;//1usdt->fox;  2fox->usdt
        }
        if(!in_array($post['type'], [1,2])){
            $post['type'] = 1;
        }
        $result = $this->validate($post,'app\api\validate\Wallet.swap');
        if(true !== $result){
            // 验证失败 输出错误信息
            $this->error($result);
        }
        //提现金额小于10u
        $transpwd = Db::name('user')->where(['id'=>$user['id']])->value('transpwd');
        if($transpwd == ''){
            $this->error(__('pleaseset_transpassword'), null, 2);
        }
        Db::startTrans();
        $res = [];
        try {
            $res = UserLogic::swap($user['id'], $post, $this->lang);
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            $this->error($ex->getMessage());
        }
        $this->success(__('general_success'), $res);
    }


    public function mycoupon(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        try {
            $data = OrderLogic::couponlists($this->lang, $user['id'], $post);
        }catch (Exception $ex){
            $this->error(__('general_error'));
        }
        $this->success('ok', $data);
    }

    public function analytics(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        $this->success('ok', WalletLogic::getAnalytics($user, $post));
    }

}