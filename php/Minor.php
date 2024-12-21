<?php


namespace app\api\controller;


use app\api\logic\MinorLogic;
use app\api\logic\OrderLogic;
use app\common\controller\Api;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\CurrencyAction;
use app\common\model\walletuser\UserAccount;
use think\Cache;
use think\Db;
use think\Exception;

class Minor extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];


    public function index(){
        $user = $this->auth->getUserinfo();
        $config = Db::name('configreward')->column('value','name');
        $finance = Db::name('account_finance')->where(['user_id'=>$user['id'], 'tag'=>0])->find();
        $data['coin'] = Db::name('minor_log')->where(['user_id'=>$user['id'], 'order_id'=>0])->sum('fox');
        $data['coin'] = number_format($data['coin'], 4, '.', '');
        //今日剩余挖矿次数
        $userfull = Db::name('user')->find($user['id']);
        $remaining = $config['fox_minor_daytime']+$userfull['minoraddtime']-$userfull['minorusd'];
        if($remaining<0){
            $remaining = 0;
        }
        $data['remaining'] = $remaining;

        //最近挖矿
        $data['minortag'] = ($config['fox_minor_foxmax']-$config['fox_minor_foxmin'])/2 + $config['fox_minor_foxmin'];

        $data['rule'] = Db::name('page')->where(['id'=>18])->value($this->lang.'_content as en_content');
        $data['list'] = Db::name('minor m')
            ->field('m.id, m.en_name, m.en_name, m.pl, m.minutes, '.$this->lang.'_opratetime as opratetime, m.amount, ifnull(o.runstatus,0) runstatus, o.maxcount, o.mcount')
            ->join('fa_minor_order o', 'o.minor_id = m.id and o.user_id = '.$user['id'].' and o.runstatus=1', 'left')
            ->order('m.pl asc')->select();
        $botreamining = 0;
        foreach ($data['list'] as $order){
            if($order['runstatus']==1){
                $botreamining+= ($order['maxcount'] - $order['mcount']);
            }
        }
        $data['botreamining'] = $botreamining;
        if($botreamining){
            $data['latest'] = Db::name('minor_log')->field('fox as amount')->where(['user_id'=>$user['id'], 'order_id'=>['gt', 0]])->order(Db::raw('rand()'))->limit(rand(2,6))->select();
        }else{
            $data['latest'] = [];
        }
        $latestfox = Db::name('minor_log')->where(['user_id'=>$user['id']])->order('id desc')->limit(1)->value('fox');
        if($latestfox < $data['minortag']){
            $data['tag'] = rand(10,50);
        }else{
            $data['tag'] = rand(60,99);
        }
        //邀请弹框
        $yqnum = Db::name('yqjl')->where(['user_id'=>$user['id'], 'read'=>0])->count();
        $data['invite_add'] = $config['fox_minor_invitetime'] * $yqnum;
        //累计可领
        $data['received'] = Db::name('minor_log')->where(['user_id'=>$user['id']])->sum('fox');
        $this->success('ok', $data);
    }

    public function closealert(){
        $user = $this->auth->getUserinfo();
        Db::name('yqjl')->where(['user_id'=>$user['id'], 'read'=>0])->update(['read'=>1]);
        $this->success('ok');
    }

    public function clickstart(){
        $user = $this->auth->getUserinfo();
        //防止重复点击
        $ck = 'fangzhichongfu_clickstart'.$user['id'];
        if(Cache::has($ck)){
            $this->error(__('operating_rate'));
        }
        Cache::set($ck, 1, 2);
        Db::startTrans();
        try{
            $config = Db::name('configreward')->column('value','name');
            $userfull = Db::name('user')->find($user['id']);
            $data = MinorLogic::clickminor($userfull, $config);
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            $this->error($ex->getMessage());
        }
        $this->success(__('minor_act'), $data);
    }

    public function getfox(){
        $user = $this->auth->getUserinfo();
        //防止重复点击
        $ck = 'fangzhichongfu_getfox'.$user['id'];
        if(Cache::has($ck)){
            $this->error(__('operating_rate'));
        }
        Cache::set($ck, 1, 2);

        Db::startTrans();
        try{
            $fox = Db::name('minor_log')->where(['user_id'=>$user['id'], 'order_id'=>0])->sum('fox');
            if($fox){
                UserAccount::addlog($user['id'], 0, Currency::FOX, CurrencyAction::FOXIncomeByMinor, $fox, '', '手动挖矿');
            }
            Db::name('minor_log')->where(['user_id'=>$user['id'], 'order_id'=>0])->delete();
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            $this->error($ex->getMessage());
        }
        $this->success(__('get_fox_success'));
    }


    public function buy(){
        $user = $this->auth->getUserinfo();
        $id = $this->request->post('id', 0);
        $transpwd = $this->request->post('transpwd', '');
        $user = Db::name('user')->find($user['id']);
        if($user['transpwd'] == ''){
            $this->error(__('pleaseset_transpassword'), null, 2);
        }
        if($user['transpwd'] != $transpwd){
            $this->error(__('error_transpassword'));
        }
        //防止重复点击
        $ck = 'fangzhichongfuminor'.$user['id'];
        if(Cache::has($ck)){
            $this->error(__('operating_rate'));
        }
        Cache::set($ck, 1, 2);
        $info = Db::name('minor')->where(['id'=>$id])->find();
        if(!$info){
            $this->error(__('defiproduct_is_notexists'));
        }
        $hasorder = Db::name('minor_order')->where(['user_id'=>$user['id'], 'runstatus'=>1])->find();
        if($hasorder){
            $this->error(__('bot_is_exists'));
        }

        $real_amount = $total_amount = $info['amount'];//实际付款金额
        $balance = UserAccount::getBalance($user['id']);
        if($real_amount > $balance['usdt']){
            $this->error(__('balance_buzu'), [], 203);
        }

        //当天不能买
        //$hasorder = Db::name('minor_order')->where(['user_id'=>$user['id'], 'runstatus'=>2])->order('id desc')->find();
        //if($hasorder && date('Ymd',$hasorder['endtime'])==date('Ymd')){
        //    $this->error(__('bot_buy_tomorrow'));
        //}

        Db::startTrans();
        try{
            //1.下单
            $vo = [];
            $vo['real_amount'] = $real_amount;
            $vo['total_amount'] = $total_amount;
            $vo['order_sn'] = createOrderSn('defiorder', 'order_sn', 'bot', 6);
            $vo['user_id'] = $user['id'];
            $vo['minor_id'] = $info['id'];
            $vo['runstatus'] = 1;
            $vo['maxcount'] = $info['pl'];
            $vo['addtime'] = time();
            $vo['endtime'] = time() + $info['minutes'] * 60;
            $step = floor(($info['minutes'] * 60)/$info['pl']);
            if($step<=0){
                $step = 1;
            }
            $vo['step'] = $step;
            $vo['nextruntime'] = time()+$step;
            $order_id = Db::name('minor_order')->insertGetId($vo);
            //2.扣掉余额
            $res1 = UserAccount::addlog($user['id'], $order_id, Currency::USDT, CurrencyAction::USDTExpendByCreateOrder3, -$real_amount, json_encode(['sn'=>$vo['order_sn'],'ue'=>$user['email'], 'pid'=>$id]), '支付BOT产品');
            if($res1['error']==1){
                throw new Exception($res1['msg']);
            }
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            $this->error(__('general_error'));
        }
        $this->success(__('general_success'));
    }


    public function orderlist(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        try {
            $data = MinorLogic::orderlist($user['id'], $post);
        }catch (Exception $ex){
            $this->error(__('general_error'));
        }
        $this->success('ok', $data);
    }

}