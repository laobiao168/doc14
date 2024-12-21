<?php


namespace app\api\controller;


use app\api\logic\OrderLogic;
use app\api\logic\ProductLogic;
use app\common\controller\Api;
use app\common\model\walletuser\UserAccount;
use think\Db;
use think\Exception;

class Product extends Api
{

    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    public function index(){
        $user = $this->auth->getUserinfo();
        $productslist = ProductLogic::getlist($user['id'],$this->lang);
        $this->success('ok', ['products'=>$productslist]);
    }

    public function info(){
        $user = $this->auth->getUserinfo();
        $id = $this->request->post('id', 0);
        $info = Db::name('product')->field('id,pic,'.$this->lang.'_name as name,day7profit,minamount,'.$this->lang.'_content as content,'.'en'.'_yxts as yxts,'.'en'.'_znzh as znzh,echartdata,timetype,runtime,minamount,topamount')
            ->where(['del'=>0, 'status'=>1, 'id'=>$id])
            ->find();
        if(!$info){
            $this->error(__('product_is_notexists'));
        }
        $info['pic'] = getImgUrl($info['pic']);
        if($info['timetype'] == 1){
            $info['timelabel'] = __('product_run_hour', [$info['runtime']]);
        }else{
            $info['timelabel'] = __('product_run_day', [$info['runtime']]);
        }
        $info['echartdata'] = json_decode($info['echartdata'], true);
        $info['day7profit'] = ($info['day7profit'] * 1).'%';
        $info['balance'] = UserAccount::getBalance($user['id']);
        $this->success('ok', $info);
    }


    public function defi(){
        $user = $this->auth->getUserinfo();
        $type = $this->request->post('type', 1);
        $productslist = ProductLogic::defilist($this->lang, $user, $type);
        $this->success('ok', ['products'=>$productslist]);
    }


    public function trader(){
        $user = $this->auth->getUserinfo();
        $productslist = ProductLogic::gettraderlist($user['id'],$this->lang);
        $this->success('ok', $productslist);
    }

    public function traderinfo(){
        $id = $this->request->param('id', 0);
        $data = ProductLogic::gettraderinfo($id,$this->lang);
        $this->success('ok', $data);
    }


    public function traderlog(){
        $post = $this->request->post();
        try {
            $data = OrderLogic::traderlog($this->lang, $post);
        }catch (Exception $ex){
            $this->error(__('general_error').$ex->getMessage());
        }
        $this->success('ok', $data);
    }

}