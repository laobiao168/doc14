<?php

namespace app\api\controller;

use app\api\library\Ckey;
use app\api\library\Gmail;
use app\api\logic\UserUsdtLogic;
use app\api\logic\UserUsdtLogic2;
use app\common\controller\Api;
use app\common\library\MyRedis;
use app\common\model\walletuser\UserAccount;
use app\common\tron\TronBase;
use app\common\udun\UdunServer;
use think\Cache;
use think\Db;
use think\Env;
use think\Exception;
use think\Log;
use think\Queue;
use function Matrix\add;

class Deposit extends Api
{

    protected $noNeedLogin = [''];
    protected $noNeedRight = ['*'];

    /**
     * 获取充值地址
     * 如果报错guzzhttp,，把tron-smartwallet/vendor/guzzhttp复制 覆盖到 根目录vendor
     */
    public function index(){
        $user = $this->auth->getUserinfo();
        $config = Db::name('configreward')->column('value', 'name');
        //获取充值地址
        $addr = Db::name('tron_useraddr')->where(['user_id'=>$user['id']])->find();
        if(!$addr){
            //生成
            try {
                $tron = new TronBase(Env::get('app.tron', ''));
                $tronaddr = $tron->createAddress();
                if($tronaddr){
                    $newaddr['user_id'] = $user['id'];
                    $newaddr['address'] = $tronaddr['address_base58'];
                    $newaddr['privateKey'] = $tronaddr['private_key'];
                    $newaddr['hexAddress'] = $tronaddr['address_hex'];
                    $newaddr['addtime'] = time();
                    $newaddr['uptime'] = time();
                    $newaddr['status'] = 1;
                    Db::name('tron_useraddr')->insertGetId($newaddr);
                    $addr = Db::name('tron_useraddr')->where(['user_id'=>$user['id']])->find();
                }else{
                    $this->error(__('general_error'));
                }
            }catch (Exception $ex){
                $this->error($ex->getMessage());
            }
        }else{
            //更新时间
            Db::name('tron_useraddr')->where(['user_id'=>$user['id']])->update(['uptime'=>time(), 'status'=>1]);
        }
        $data['address'] = $addr['address'];
        //实名认证
        $smrz = Db::name('user_smrz')->where(['user_id'=>$user['id']])->find();
        if($smrz && ($smrz['status'] == 2 || $smrz['status'] == 1)){
            $data['auth'] = 1;
        }else{
            $data['auth'] = 1;//0; 隐藏这段话
            $data['auth_usdt_tip'] = __('smrz_tixian_usdt', [$config['smrz_tixian_usdt']]);
            $data['auth_fox_tip'] = __('smrz_txian_fox', [$config['smrz_tixian_fox']]);
        }

        $ck_deposit = Ckey::depositQueueUkey($user['id']);
        $tron_useraddr = Db::name('tron_useraddr')->where(['user_id'=>$user['id']])->find();
        if(!Cache::has($ck_deposit) && $tron_useraddr){
            //加入到队列-检测充值
            $jobHandlerClassName  = 'app\api\queue\CheckDepositQueue';
            $jobQueueName  	  = "job2";
            $isPushed = Queue::push($jobHandlerClassName , ['user_id'=>$user['id']], $jobQueueName);
            if( $isPushed !== false ){
                //更新缓存
                Log::info('加入检测充值队列'.$user['id'].';成功');
                Cache::set($ck_deposit, $user['id']);
            }else{
                Log::info('加入检测充值队列'.$user['id'].';失败');
            }
        }

        $this->success('ok', $data);
    }






}