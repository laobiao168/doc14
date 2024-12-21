<?php


namespace app\api\controller;


use app\api\library\Gmail;
use app\api\logic\chongTiLogic;
use app\common\controller\Api;
use app\common\model\Tongji;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\CurrencyAction;
use app\common\model\walletuser\UserAccount;
use app\common\udun\UdunServer;
use think\Db;
use think\Exception;
use think\Log;
use Udun\Dispatch\UdunDispatchException;
use function Matrix\add;

class Upaynotify extends Api
{

    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];

    private function signature($body,$time,$nonce,$udun)
    {
        return md5($body.$udun->udun['api_key'].$nonce.$time);
    }

    public function index(){
        $udun = new UdunServer();
        $content = file_get_contents('php://input');
        $body =  $_POST['body'];
        $nonce = $_POST['nonce'];
        $timestamp = $_POST['timestamp'];
        $sign = $_POST['sign'];
        Log::notice('回调通知参数:'.  $body);
        //验证签名
        $signCheck = $this->signature($body,$timestamp,$nonce,$udun);
        if ($sign != $signCheck) {
            Log::notice('签名失败:'.  $signCheck);
            return "fail";
        }
        $body = json_decode($body, true);
        if($body){
            Db::startTrans();
            try {
                if($body['tradeType']==1) {
                    $this->recharge($body);
                }else{
                    //提币
                    $this->withdraw($body);
                }
                Db::commit();
            }catch (Exception $e){
                Db::rollback();
                Log::notice('回调通知异常；'.$e->getMessage());
            }
        }
    }

    /**
     * 充值回调
     */
    private function recharge($params){
        Log::notice('充币');
        $address = Db::name('recharge_usdt')->where(['addr'=>$params['address']])->find();
        if($address){
            $log['remark'] = $params['memo'];
            //异常处理
            if($address['status'] == 0 || $address['user_id'] == 0){
                (new Gmail())->sysErrorNotice('充值异常回调', '没找到充值用户'.json_encode($params,JSON_UNESCAPED_UNICODE));
                $log['remark'] = $params['memo'].'未找到充值用户';
            }
            $log['recharge_usdt_id'] = $address['id'];
            $log['user_id'] = $address['user_id']??0;
            $log['amount'] = $params['amount'] / pow(10, $params['decimals']);
            $log['status'] = $params['status'];
            $log['tradeType'] = $params['tradeType'];
            $log['fee'] = $params['fee'];
            $log['trx'] = $params['txId'];
            $log['order_sn'] = $address['order_sn'];
            $log['tradeId'] = $params['tradeId'];
            $log['addtime'] = time();
            $log['coinType'] = $params['coinType'];
            $log['realusdt'] = $log['amount'];
            $rlog_id = Db::name('recharge_usdt_log')->insertGetId($log);

            //充值
            $rechargeNum = $log['amount'];//充币数量
            //1.更新余额
            if($address['user_id'] > 0 && $params['status']==3){
                $user = Db::name('user')->find($address['user_id']);
                if($params['coinType'] == 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'){
                    //充值usdt
                    UserAccount::addlog($address['user_id'], $rlog_id, Currency::USDT, CurrencyAction::USDTIncomeByRechargeOnline, $rechargeNum, $params['txId'], '充值USDT', time(), $address['order_sn']??'');
                }elseif($params['coinType'] == '195'){
                    //充值TRX
                    $trx_price = hangqing();
                    Log::info('充值TRX行情='.$trx_price);
                    $rechargeNum = $trx_price*$log['amount']; //USDT
                    if($rechargeNum>0){
                        Db::name('recharge_usdt_log')->where(['id'=>$rlog_id])->update(['realusdt'=>$rechargeNum]);
                        UserAccount::addlog($address['user_id'], $rlog_id, Currency::USDT, CurrencyAction::USDTIncomeByRechargeOnline, $rechargeNum, $params['txId'], '充值TRX:'.$log['amount'].'自动充值USDT,行情:'.$trx_price, time(), $address['order_sn']??'');
                    }
                }else{
                    //其他币
                    $rechargeNum = 0;
                }
                //3.统计
                if($rechargeNum > 0){
                    $logcount = Db::name('recharge_usdt_log')->where(['user_id'=>$address['user_id']])->count();
                    if($logcount > 1){
                        //复存
                        $tongji['re_rechargenum'] = 1;
                    }else{
                        $tongji['first_rechargenum'] = 1;
                    }
                    Db::name('user')->where(['id'=>$user['id']])->update(['recharge_amount'=>Db::raw('recharge_amount+'.$rechargeNum)]);
                    //4.推荐人统计
                    chongTiLogic::upReuserRecharge($user, $rechargeNum);
                }
            }else{
                $rechargeNum = 0;
                Log::info('其他情况不统计=');
            }
            $tongji['recharge_num'] = 1;
            $tongji['recharge_amount'] = $rechargeNum;
            if($rechargeNum>0){
                Tongji::addlog($tongji, $address['user_id']);
            }
            //2.释放地址状态
            $upaddr['status'] = 0;
            $upaddr['expiretime'] = 0;
            $upaddr['user_id'] = Db::raw('NULL');
            $upaddr['amount'] = Db::raw('amount+'.$rechargeNum);
            Db::name('recharge_usdt')->where(['id'=>$address['id']])->update($upaddr);

            Log::notice('回调通知，success，充'.$rechargeNum);
        }else{
            Log::notice('回调通知，地址不存在');
        }
    }

    private function withdraw($params){
        Log::notice('提币');
        $row = Db::name('user_withdraw')->where(['order_sn'=>$params['businessId']])->find();
        if($row){
            if($params['status'] == 3){
                $user = Db::name('user')->where(['id'=>$row['user_id']])->find();
                //成功
                Db::name('user_withdraw')->where(['id'=>$row['id']])->update(['status'=>3, 'uptime'=>time(), 'reamrk'=>$params['memo']??'', 'trx'=>$params['txId']??'']);
                //统计
                $tongji = [];
                $tongji['withdraw_num'] = 1;
                $tongji['withdraw_amount'] = $row['feal_amount'];
                Tongji::addlog($tongji, $row['user_id']);

                //统计推荐人提现数据
                chongTiLogic::upReuserWithdraw($user, $row['amount']);

            }elseif(in_array($params['status'], [2,4])){
                Db::name('user_withdraw')->where(['id'=>$row['id']])->update(['status'=>0, 'uptime'=>time(), 'reamrk'=>$params['memo']??'']);
                //退回给用户
                UserAccount::addlog($row['user_id'], $row['id'], Currency::USDT, CurrencyAction::USDTIncomeByWithdrawFillRefund, $row['amount'], '', '提现失败退回amount');
            }
        }
    }

}