<?php


namespace app\api\controller;


use app\api\logic\ActiveLogic;
use app\api\logic\IndexLogic;
use app\api\logic\OrderLogic;
use app\api\logic\UserLogic;
use app\common\controller\Api;
use app\common\model\Tongji;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\CurrencyAction;
use app\common\model\walletuser\UserAccount;
use think\Cache;
use think\Db;
use think\Exception;
use think\Log;

class Order extends Api
{

    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    /**
     * 下单购买
     */
    public function buy(){
        $user = $this->auth->getUserinfo();
        $id = $this->request->post('id', 0);
        $money = $this->request->post('money', 0);
        $transpwd = $this->request->post('transpwd', '');
        $user = Db::name('user')->find($user['id']);
        if($user['transpwd'] == ''){
            $this->error(__('pleaseset_transpassword'), null, 2);
        }
        if($user['transpwd'] != $transpwd){
            $this->error(__('error_transpassword'));
        }

        $money = abs($money);
        //防止重复点击
        $ck = 'fangzhichongfuogumai'.$user['id'];
        if(Cache::has($ck)){
            $this->error(__('operating_rate'));
        }
        Cache::set($ck, 1, 2);
        $info = Db::name('product')->where(['del'=>0, 'status'=>1, 'id'=>$id])
            ->find();
        if(!$info){
            $this->error(__('product_is_notexists'));
        }
        $config = Db::name('configreward')->column('value','name');
        if($money == 0){
            $this->error(__('investment_amount'));
        }
        //最少购买
        if($money < $info['minamount']){
            $this->error(__('min_investment_amount', [$info['minamount']]));
        }
        //同时只能购买1个
        $hasorder = Db::name('order')->where(['user_id'=>$user['id'], 'runstatus'=>1])->find();
        if($hasorder){
            $this->error(__('order_unique_buy'));
        }
        $real_amount = $total_amount = $money;//实际付款金额

        //获取优惠券
        $coupons_id = 0;
        $coupon = Db::name('user_coupons uc')
            ->field('uc.*,c.orderday')
            ->join('coupons c','c.id = uc.coupon_id', 'left')
            ->where(['uc.user_id'=>$user['id'], 'uc.status'=>1, 'c.product_id'=>['in', [$id,999]], 'c.position'=>2, 'c.product_amount'=>['elt', $money]])
            ->find();
        if($coupon){
            if($money > $coupon['amount']){
                $real_amount = $money - $coupon['amount'];
            }else{
                $real_amount = 0;
            }
            $coupons_id = $coupon['id'];
        }
        $balance = UserAccount::getBalance($user['id']);
        if($real_amount > $balance['usdt']){
            $this->error(__('balance_buzu'), [], 203);
        }
        Db::startTrans();
        try{
            //1.下单
            $vo = [];
            $vo['real_amount'] = $real_amount;
            $vo['total_amount'] = $total_amount;
            $vo['order_sn'] = createOrderSn('order', 'order_sn', '', 6);
            $vo['user_id'] = $user['id'];
            $vo['product_id'] = $info['id'];
            $vo['coupons_id'] = $coupons_id;
            $vo['coupons_amount'] = $coupon['amount']??0;
            $vo['status'] = 1;
            $vo['runstatus'] = 1;
            $vo['addtime'] = time();
            $vo['nextruntime'] = time() + 3600;
            if ($coupon && $coupon['orderday'] > 0){
                $vo['cendtime'] = time() + $coupon['orderday']*86400 + 120; //到期自动停止,多2分钟
            }
            $order_id = Db::name('order')->insertGetId($vo);
            //2.扣掉余额
            $res1 = UserAccount::addlog($user['id'], $order_id, Currency::USDT, CurrencyAction::USDTExpendByCreateOrder1, -$real_amount, json_encode(['sn'=>$vo['order_sn'],'ue'=>$user['email'], 'pid'=>$id]), '下单支付usdt');
            if($res1['error']==1){
                throw new Exception($res1['msg']);
            }
            if($coupons_id){
                $tongji['use_coupon_amount'] = $coupon['amount'];
                Db::name('user_coupons')->where(['id'=>$coupon['id']])->update(['status'=>2, 'usetime'=>time()]);
            }
            //统计
            $tongji['order_num'] = 1;
            $tongji['order_amount'] = $total_amount;
            Tongji::addlog($tongji, $user['id']);

            if($real_amount>0 && ActiveLogic::depositamount($user['id']) > 0){
                $reDespsit = Db::name('czjl')->where(['user_id'=>$user['reid'], 'read'=>0])->sum('amount');
                //推荐人送券
                if($reDespsit>0){
                    $udtag = ActiveLogic::givecoupon($user['reid'], 'product_amount', $reDespsit);
                }else{
                    $udtag = false;
                }
                //投资抽红包任务
                $myDespsit = Db::name('czjl')->where(['user_id'=>$user['id'], 'mread'=>0])->sum('amount');

                //自己买获得券
                ActiveLogic::givecouponmy($myDespsit, $user['id'], 'product');

                $hongbaolist = Db::name('hongbao')->where(['status'=>1])->select();
                foreach ($hongbaolist as $hb){
                    if($hb['type'] == 1){
                        //邀请
                        if(($hb['product_id']== 999 || $hb['product_id']==$info['id']) && $hb['ordermoney'] <= $reDespsit){
                            Db::name('tzjl')->insertGetId(['child_id'=>$user['id'], 'user_id'=>$user['reid'], 'hongbao_id'=>$hb['id'], 'type'=>1]);
                        }
                    }else{
                        //本人
                        if(($hb['product_id']== 999 || $hb['product_id']==$info['id']) && $hb['ordermoney'] <= $myDespsit){
                            Db::name('tzjl')->insertGetId(['user_id'=>$user['id'], 'hongbao_id'=>$hb['id'], 'type'=>2]);
                            Db::name('czjl')->where(['user_id'=>$user['id'], 'mread'=>0])->update(['mread'=>1]);
                        }
                    }

                }
                if($udtag){
                    Db::name('czjl')->where(['user_id'=>$user['reid'], 'read'=>0])->update(['read'=>1]);
                }
            }

            Db::name('user')->where(['id'=>$user['id']])->setInc('orderamount', $real_amount);
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            Log::error('buy失败'.$ex->getMessage().';'.$ex->getFile().';'.$ex->getLine());
            $this->error(__('order_buy_fail'));
        }
        $ck = 'product_list'.$user['id'].'_'.$this->lang;
        Cache::rm($ck);
        $this->success(__('order_buy_success'), ['order_id'=>$order_id]);
    }


    public function lists(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        try {
            $data = OrderLogic::lists($this->lang, $user['id'], $post);
        }catch (Exception $ex){
            $this->error(__('general_error'));
        }
        $this->success('ok', $data);
    }



    public function info(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        try {
            $data = OrderLogic::info($this->lang, $user['id'], $post);
        }catch (Exception $ex){
            $this->error(__('general_error'));
        }
        $this->success('ok', ['info'=>$data]);
    }


    public function stop(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        Db::startTrans();
        try {
             OrderLogic::stoporder($user, $post);
             Db::commit();

            $ck = 'product_list'.$user['id'].'_'.$this->lang;
            Cache::rm($ck);
        }catch (Exception $ex){
            Db::rollback();
            Log::error('stop失败'.$ex->getMessage().';'.$ex->getFile().';'.$ex->getLine().';订单id='.$post['id']??'');
            $this->error($ex->getMessage());
        }
        $this->success(__('jiaoyijiesu'));
    }


    public function getBonus(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        $ck = 'getBonus_'.$user['id'];
        if(Cache::has($ck)){
            exit;
        }
        Cache::set($ck, 1, 3);
        Db::startTrans();
        try {
            OrderLogic::getBonus($user, $post);
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            Log::error('getbonus失败'.$ex->getMessage().';'.$ex->getFile().';'.$ex->getLine());
            $this->error($ex->getMessage());
        }
        $this->success(__('lingquchenggong'));
    }



    public function investment(){
        $user = $this->auth->getUserinfo();
        $id = $this->request->post('id', 0);
        $money = $this->request->post('money', 0);
        $transpwd = $this->request->post('transpwd', '');
        $user = Db::name('user')->find($user['id']);
        if($user['transpwd'] == ''){
            $this->error(__('pleaseset_transpassword'), null, 2);
        }
        if($user['transpwd'] != $transpwd){
            $this->error(__('error_transpassword'));
        }
        $money = abs($money);
        //防止重复点击
        $ck = 'fangzhichongfuinvestment'.$user['id'];
        if(Cache::has($ck)){
            $this->error(__('operating_rate'));
        }
        Cache::set($ck, 1, 2);
        $info = Db::name('defi')->where(['status'=>1, 'id'=>$id])->find();
        if(!$info){
            $this->error(__('defiproduct_is_notexists'));
        }
        $config = Db::name('configreward')->column('value','name');
        if($money == 0){
            $this->error(__('investment_amount'));
        }
        //最少购买
        if($money < $info['minamount']){
            $this->error(__('min_investment_amount', [$info['minamount']]));
        }
        //最多购买
        if($money > $info['maxamount']){
            $this->error(__('max_investment_amount', [$info['maxamount']]));
        }
        //同1个只能买1个
        $hasamount = Db::name('defiorder')->where(['user_id'=>$user['id'], 'runstatus'=>1, 'product_id'=>$id])->find();
        if($hasamount){
            $this->error(__('investment_defi'));
        }
        $real_amount = $total_amount = $money;//实际付款金额

        //获取优惠券
        $coupons_id = 0;
        $coupon = Db::name('user_coupons uc')
            ->field('uc.*')
            ->join('coupons c','c.id = uc.coupon_id', 'left')
            ->where(['uc.user_id'=>$user['id'], 'uc.status'=>1, 'c.defi_id'=>['in', [$id,999]], 'c.position'=>1, 'c.defi_amount'=>['elt', $money]])
            ->find();
        if($coupon){
            if($money > $coupon['amount']){
                $real_amount = $money - $coupon['amount'];
            }else{
                $real_amount = 0;
            }
            $coupons_id = $coupon['id'];
        }

        $balance = UserAccount::getBalance($user['id']);
        if($real_amount > $balance['usdt']){
            $this->error(__('balance_buzu'), [], 203);
        }
        Db::startTrans();
        try{
            //1.下单
            $vo = [];
            $vo['real_amount'] = $real_amount;
            $vo['total_amount'] = $total_amount;
            $vo['order_sn'] = createOrderSn('defiorder', 'order_sn', '', 6);
            $vo['user_id'] = $user['id'];
            $vo['product_id'] = $info['id'];
            $vo['profit'] = $info['profit'];
            $vo['dtype'] = $info['type'];
            $vo['days'] = $info['days'];
            $vo['coupons_id'] = $coupons_id;
            $vo['coupons_amount'] = $coupon['amount']??0;
            $vo['status'] = 1;
            $vo['runstatus'] = 1;
            $vo['addtime'] = time();
            $vo['nextruntime'] = time() + $info['days']*86400;
            if($info['type']==2){
                $vo['nextruntime'] = time() + 86400;
                $action = CurrencyAction::USDTExpendByCreateOrder22;
            }else{
                $action = CurrencyAction::USDTExpendByCreateOrder2;
            }
            $order_id = Db::name('defiorder')->insertGetId($vo);
            //2.扣掉余额
            $res1 = UserAccount::addlog($user['id'], $order_id, Currency::USDT, $action, -$real_amount, json_encode(['sn'=>$vo['order_sn'],'ue'=>$user['email'], 'pid'=>$id]), '支付DEFI产品');
            if($res1['error']==1){
                throw new Exception($res1['msg']);
            }
            if($coupons_id){
                $tongji['use_coupon_amount'] = $coupon['amount'];
                Db::name('user_coupons')->where(['id'=>$coupon['id']])->update(['status'=>2, 'usetime'=>time()]);
            }
            //统计
            $tongji['defi_num'] = 1;
            $tongji['defi_amount'] = $total_amount;
            Tongji::addlog($tongji, $user['id']);
            //推荐人送券
            if($real_amount>0 && ActiveLogic::depositamount($user['id']) > 0){
                //推荐人送券
                ActiveLogic::givecoupon($user['reid'], 'defi_amount', $real_amount);

                //自己买获得券
                ActiveLogic::givecouponmy($total_amount, $user['id']);
                //投资抽红包
                $hongbaolist = Db::name('hongbao')->where(['status'=>1])->select();
                foreach ($hongbaolist as $hb){
                    if($hb['type'] == 1){
                        //邀请
                        if(($hb['defi_id']== 999 || $hb['defi_id']==$info['id']) && $hb['ordermoney'] <= $real_amount){
                            Db::name('tzjl')->insertGetId(['child_id'=>$user['id'], 'user_id'=>$user['reid'], 'hongbao_id'=>$hb['id'], 'type'=>1]);
                        }
                    }else{
                        //本人
                        if(($hb['defi_id']== 999 || $hb['defi_id']==$info['id']) && $hb['ordermoney'] <= $real_amount){
                            Db::name('tzjl')->insertGetId(['user_id'=>$user['id'], 'hongbao_id'=>$hb['id'], 'type'=>2]);
                        }
                    }

                }
            }
            Db::name('user')->where(['id'=>$user['id']])->setInc('orderamount', $real_amount);
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            Log::error('investment失败'.$ex->getMessage().';'.$ex->getFile().';'.$ex->getLine());
            $this->error(__('order_buy_fail'));
        }
        $this->success(__('order_buy_success'), ['order_id'=>$order_id]);
    }


    public function defilists(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        try {
            $data = OrderLogic::defilists($this->lang, $user['id'], $post);
        }catch (Exception $ex){
            $this->error(__('general_error'));
        }
        $this->success('ok', $data);
    }


    public function coindata(){
        $ck = 'coindata_cake';
        if(Cache::has($ck)){
            $this->success('ok', Cache::get($ck));
        }
        $data = assignOrderCoin(10, 7, false);
        $fu = 0;
        foreach ($data as &$item){
            $item['pro'] = $item['pro']*1;
            if($item['pro']>50){
                $item['pro'] = rand(20,33);
            }
            $item['abs'] = 1;
            if(rand(1,5)==1 && $fu<=2){
                $fu++;
                $item['abs'] = 0;
            }
            $item['icon'] = getImgUrl($item['icon']);
        }
        Cache::set($ck, $data, 5);
        $this->success('ok', $data);
    }



    public function copying(){
        $user = $this->auth->getUserinfo();
        $id = $this->request->post('id', 0);
        $money = $this->request->post('money', 0);
        $day = $this->request->post('day', 0);
        $transpwd = $this->request->post('transpwd', '');
        $user = Db::name('user')->find($user['id']);
        if($user['transpwd'] == ''){
            $this->error(__('pleaseset_transpassword'), null, 2);
        }
        if($user['transpwd'] != $transpwd){
            $this->error(__('error_transpassword'));
        }
        $money = abs($money);
        //防止重复点击
        $ck = 'fangzhichongcopying'.$user['id'];
        if(Cache::has($ck)){
            $this->error(__('operating_rate'));
        }
        Cache::set($ck, 1, 2);
        $info = Db::name('trader')->where(['status'=>1, 'id'=>$id])->find();
        if(!$info){
            $this->error(__('trader_is_notexists'));
        }
        $config = Db::name('configreward')->column('value','name');
        if($money == 0){
            $this->error(__('investment_amount'));
        }
        //最少购买
        if($money < $info['minamount']){
            $this->error(__('min_copying_amount', [$info['minamount']]));
        }
        //周期
        $zqarr = explode(',', $info['zhouqi']);
        if(!in_array($day, $zqarr)){
            $this->error(__('days_error'));
        }

        $real_amount = $total_amount = $money;//实际付款金额

        //获取优惠券
        $coupons_id = 0;
        /*
        $coupon = Db::name('user_coupons uc')
            ->field('uc.*')
            ->join('coupons c','c.id = uc.coupon_id', 'left')
            ->where(['uc.user_id'=>$user['id'], 'uc.status'=>1, 'c.status'=>1, 'c.defi_id'=>['in', [$id,999]], 'c.position'=>1, 'c.defi_amount'=>['elt', $money]])
            ->find();
        if($coupon){
            if($money > $coupon['amount']){
                $real_amount = $money - $coupon['amount'];
            }else{
                $real_amount = 0;
            }
            $coupons_id = $coupon['id'];
        }
        */
        $balance = UserAccount::getBalance($user['id']);
        if($real_amount > $balance['usdt']){
            $this->error(__('balance_buzu'), [], 203);
        }
        Db::startTrans();
        try{
            //1.下单
            $vo = [];
            $vo['real_amount'] = $real_amount;
            $vo['num'] = $total_amount;
            $vo['order_sn'] = createOrderSn('defiorder', 'order_sn', '', 6);
            $vo['user_id'] = $user['id'];
            $vo['trader_id'] = $info['id'];
            $vo['day'] = $day;
            $vo['coupons_id'] = $coupons_id;
            $vo['coupons_amount'] = 0;//$coupon['amount']??0;
            $vo['runstatus'] = 1;
            $vo['addtime'] = time();
            $vo['endtime'] = time() + $day * 86400;
            $vo['nextruntime'] = time() + 86400;

            $order_id = Db::name('tradingorder')->insertGetId($vo);
            //2.扣掉余额
            $res1 = UserAccount::addlog($user['id'], $order_id, Currency::USDT, CurrencyAction::USDTExpendByCopyingOrder, -$real_amount, json_encode(['sn'=>$vo['order_sn'],'ue'=>$user['email'], 'pid'=>$id]), '跟单');
            if($res1['error']==1){
                throw new Exception($res1['msg']);
            }
            if($coupons_id){
                //$tongji['use_coupon_amount'] = $coupon['amount'];
                //Db::name('user_coupons')->where(['id'=>$coupon['id']])->update(['status'=>2, 'usetime'=>time()]);
            }
            //统计
            $tongji['copying_num'] = 1;
            $tongji['copying_amount'] = $total_amount;
            Tongji::addlog($tongji, $user['id']);
            //推荐人送券

            Db::name('user')->where(['id'=>$user['id']])->setInc('orderamount', $real_amount);
            Db::commit();
        }catch (Exception $ex){
            Db::rollback();
            Log::error('copying失败'.$ex->getMessage().';'.$ex->getFile().';'.$ex->getLine());
            $this->error(__('order_buy_fail'));
        }
        //清楚个人统计缓存
        $ck = 'traderlist'.$user['id'].'_'.$this->lang;
        Cache::rm($ck);

        $this->success(__('copying_success'), ['order_id'=>$order_id]);
    }



    public function copylist(){
        $user = $this->auth->getUserinfo();
        $post = $this->request->post();
        try {
            $data = OrderLogic::copylist($this->lang, $user['id'], $post);
        }catch (Exception $ex){
            $this->error(__('general_error'));
        }
        $this->success('ok', $data);
    }
}