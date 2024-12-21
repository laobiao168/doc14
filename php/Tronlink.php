<?php


namespace app\api\controller;


use app\api\library\Ckey;
use app\common\controller\Api;
use app\common\library\MyRedis;
use app\common\tron\TronBase;
use fast\Random;
use think\Cache;
use think\Db;
use think\Exception;
use think\Log;

class Tronlink extends Api
{

    protected $noNeedLogin = ['callback_connect', 'callback_pay'];
    protected $noNeedRight = ['*'];

    public function callback_connect(){
        $str = file_get_contents("php://input");
        Log::info('Tronlink登录回调'.$str);
        $data = json_decode($str, true);
        if($data && isset($data['address'])){
            Cache::set($data['actionId'], $data['address'], 86400*30);
        }
        $this->success('ok2',$_REQUEST);
    }

    public function getAddress(){
        $actionid = $this->request->param('actionId', '');
        if(Cache::has($actionid)){
            $this->success('ok',['status'=>1,'address'=>Cache::get($actionid)]);
        }else{
            $this->success('ok',['status'=>0]);
        }
    }

    public function loginConfig(){
        $data['callbackUrl'] = getImgUrl('/api/tronlink/callback_connect');
        $data['dappIcon'] = 'avatar.png';
        $data['dappName'] = 'FoxPay';
        $data['protocol'] = 'TronLink';
        $data['version'] = '1.0';
        $data['chainId'] = '0x2b6653dc';
        $data['action'] = 'login';
        $data['actionId'] = Random::uuid();
        $this->success('ok', $data);
    }


    public function payConfig(){
        $user = $this->auth->getUserinfo();
        $data['callbackUrl'] = getImgUrl('/api/tronlink/callback_pay');
        $data['dappIcon'] = 'avatar.png';
        $data['dappName'] = 'FoxPay';
        $data['protocol'] = 'TronLink';
        $data['version'] = '1.0';
        $data['chainId'] = '0x2b6653dc';
        $data['memo'] = 'pay';
        $addr = Db::name('tron_useraddr')->where(['user_id'=>$user['id']])->find();
        $data['to'] = $addr['address']??'';
        $tron = new TronBase();
        $data['contract'] = $tron->getUSDTContract();
        $data['action'] = 'transfer';
        $data['actionId'] = Random::uuid();
        $this->success('ok', $data);
    }


    public function callback_pay(){
        $str = file_get_contents("php://input");
        Log::info('Tronlink付款回调'.$str);
        try{
            $data = json_decode($str, true);
            if($data && isset($data['transactionHash']) && isset($data['message']) && $data['message'] == 'success'){
                $tronapi = new TronBase();
                $event = $tronapi->getEventTransInfo($data['transactionHash']);
                if ($event && is_array($event)) {
                    MyRedis::_initialize();
                    $ck_hex = Ckey::tron_set_address_hex58();
                    if (isset($event['toAddress']) && isset($event['tokenTransferInfo']) && isset($event['contractRet']) && $event['contractRet'] == 'SUCCESS') {
                        if ($event['toAddress'] == $tronapi->getUSDTContract() || $event['toAddress'] == $tronapi->getFOXContract()) {
                            $amount = $tronapi->decimalValue($event['tokenTransferInfo']['amount_str']);
                            $owner_address = $event['tokenTransferInfo']['from_address'];
                            $to_address = $event['tokenTransferInfo']['to_address'];
                            //判断地址是否需要监听
                            if (MyRedis::sismember($ck_hex, $owner_address) || MyRedis::sismember($ck_hex, $to_address)) {
                                $vo = [];
                                $vo['txid'] = $data['transactionHash'];
                                $vo['from'] = $owner_address;
                                $vo['to'] = $to_address;
                                $vo['value'] = $amount;
                                $vo['contract'] = $event['toAddress'];
                                $vo['symbol'] = $event['tokenTransferInfo']['symbol'];
                                $vo['timestamp'] = time();
                                Db::name('tron_transactions')->insertGetId($vo);
                                Log::info("TronLink支付:" . $vo['symbol'] . ';记录=' . $data['transactionHash'] . "\r\n");
                            } else {
                                Log::info("TronLink支付，不在监听范围..." . $data['transactionHash'] . "\r\n");
                            }
                        }
                    }
                }
            }
        }catch (Exception $ex){
            Log::error('发生错误'.$ex->getMessage().';'.$ex->getLine().';'.$ex->getFile()."\r\n");
        }
    }
}