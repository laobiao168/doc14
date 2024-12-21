<?php


namespace app\api\controller;


use app\api\logic\ActiveLogic;
use app\common\controller\Api;
use app\common\model\Tongji;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\CurrencyAction;
use app\common\model\walletuser\UserAccount;
use think\Db;
use think\Exception;

class Active extends Api
{

    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];


    public function getCoupons(){
        $coupon_id = $this->request->post('id', 0);
        $user = $this->auth->getUserinfo();
        //是否已领取
        $has = Db::name('user_coupons')->where(['user_id'=>$user['id'], 'coupon_id'=>$coupon_id, 'status'=>1])->find();
        if($has){
            $this->error(__('coupon_has_get'));
        }
        $cw['id'] = $coupon_id;
        $cw['type'] = 4;
        $cw['status'] = 1;
        $coupons = Db::name('coupons')->where($cw)->find();
        if($coupons){
            ActiveLogic::sendCoupons($user['id'], $coupons);
            //统计
            $tongji['get_coupon_num'] = 1;
            $tongji['get_coupon_amount'] = $coupons['amount'];
            Tongji::addlog($tongji, $user['id']);
        }else{
            $this->error(__('coupon_not_found'));
        }
        $this->success(__('coupon_get_success'));
    }


    public function lucky(){
        $user = $this->auth->getUserinfo();
        $data['list'] = Db::name('lucky')->where(['status'=>1])->order('sort asc')->field('id as prizeId,rewardtype,reward_val,rate as prizeWeight')->select();
        foreach ($data['list'] as &$item){
            $item['reward_val'] = $item['reward_val']*1;
            if($item['rewardtype'] == 1){
                $item['prizeName'] = 'E-coin';
            }else if($item['rewardtype'] == 2){
                $item['prizeName'] = 'USDT';
            }elseif($item['rewardtype'] == 3){
                $item['prizeName'] = 'Again';
                $item['reward_val'] = ' ';
            }elseif($item['rewardtype'] == 4){
                $item['prizeName'] = 'Thanks';
                $item['reward_val'] = ' ';
            }elseif($item['rewardtype'] == 5){
                $item['prizeName'] = 'Coupons';
                $item['reward_val'] = ' ';
            }else{
                $item['prizeName'] = 'ha ha';
                $item['reward_val'] = ' ';
            }
            $item['prizeStock'] = 999;
        }
        //规则
        $data['text'] = Db::name('page')->where(['id'=>10])->field($this->lang.'_title as title, '.$this->lang.'_content as content')->find();
        //余额
        $data['balance'] = UserAccount::getBalance($user['id']);
        $this->success('ok', $data);
    }


    public function luckydraw(){
        $user = $this->auth->getUserinfo();
        $balance = UserAccount::getBalance($user['id']);
        $hasfeetime = false;
        if($user['luckyagain']>0){
            $hasfeetime = true; //免费机会
        }else{
            $runecoin = Db::name('configreward')->where(['name'=>'luck_decute_ecoin'])->value('value');
            if($balance['ecoin'] < $runecoin){
                $this->error(__('balance_buzu'));
            }
        }
        Db::startTrans();
        try {
            //抽奖
            $return = ActiveLogic::drawlucky($user['id']);
            if($hasfeetime){
                Db::name('user')->where(['id'=>$user['id']])->setDec('luckyagain');
            }else{
                $efee = Db::name('configreward')->where(['name'=>'luck_decute_ecoin'])->value('value');
                UserAccount::addlog($user['id'], $return['log_id'], Currency::Ecoin, CurrencyAction::EcoinExpendByRunTurn, -$efee, '', '启动转盘扣除');
                Db::name('lucky_log')->where(['id'=>$return['log_id']])->update(['useecion'=>$efee]);
                //统计
                $tongji['ecoin_usenum'] = $efee;
                Tongji::addlog($tongji, $user['id']);
            }
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            $this->error(__('general_error').$ex->getMessage());
        }
        $balance = UserAccount::getBalance($user['id']);
        $this->success($return['msg'], ['balance'=>$balance, 'prizeId'=>$return['id']]);
    }


}