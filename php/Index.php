<?php

namespace app\api\controller;

use app\api\library\Help;
use app\api\logic\IndexLogic;
use app\common\controller\Api;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\CurrencyAction;
use app\common\model\walletuser\UserAccount;
use think\Cache;
use think\Db;
use think\Exception;
use think\Log;

/**
 * 首页接口
 */
class Index extends Api
{
    protected $noNeedLogin = ['index', 'init', 'onload', 'agreement', 'kefu', 'launchpic', 'checkapp'];
    protected $noNeedRight = ['*'];

    /**
     * 首页
     */
    public function index()
    {
        if($this->auth->isLogin()){
            $userinfo = $this->auth->getUserinfo();
            $data = IndexLogic::getHome($this->lang, $userinfo);
            Db::name('user')->where(['id'=>$userinfo['id']])->update(['logintime'=>time()]);
        }else{
            $data = IndexLogic::getHome($this->lang);
        }
        $this->success('okd', $data);
    }

    public function coins(){
        $coinlist = Db::name('coin')->order('sort asc')->select();
        $this->success('ok', $coinlist);
    }

    public function init(){
        if($this->auth->isLogin()){
            $userinfo = $this->auth->getUserinfo();
            $this->success('ok', IndexLogic::getSevice($this->lang, $userinfo));
        }else{
            $this->success('ok', IndexLogic::getSevice($this->lang));
        }
    }


    /**
     * 检查app版本
     */
    public function checkapp(){
        $app['appdown'] = getImgUrl('/uploads/apexcrypto.apk');
        $this->success('ok', $app);
    }

    public function getnews()
    {
        $post = $this->request->post();
        $user = $this->auth->getUser();
        $data = IndexLogic::getnews($this->lang, $post, $user);
        $this->success('ok', $data);
    }

    public function getnewsinfo()
    {
        $id = $this->request->post('id', 0);
        $data = Db::name('article')->where(['lang'=>$this->lang, 'id'=>$id])->find();
        $user = $this->auth->getUser();
        $has = Db::name('article_read')->where(['article_id'=>$id, 'user_id'=>$user['id']??0])->find();
        if(!$has){
            Db::name('article_read')->insertGetId(['article_id'=>$id, 'user_id'=>$user['id']??0, 'addtime'=>time()]);
        }
        $this->success('ok', $data);
    }


    public function phoneCode(){
        $data = Db::name('phonecode')->field('id,english_name,phone_code')->order(Db::raw('sort desc, phone_code*1 asc'))->select();
        $this->success('ok', $data);
    }

    public function agreement(){
        $id = $this->request->post('id', 0);
        $data['text'] = Db::name('page')->where(['id'=>$id])->field('video,'.$this->lang.'_title as title, '.$this->lang.'_content as content')->find();
        if($data['text']['video']!=''){
            $data['text']['video'] = getImgUrl($data['text']['video']);
        }
        $this->success('ok', $data);
    }

    public function launchpic(){
        $data = Db::name('launch')->order('sort asc')->select();
        $this->success('ok', $data);
    }

    public function choujiang(){
        $redpaper = 0;
        $hastag = 0;
        $jump = 1; //1.邀请页面；2defi页面；3k线图
        $user = $this->auth->getUserinfo();
        $ck = 'choujiang_click_'.$user['id'];
        if(Cache::has($ck)){
            exit;
        }
        Cache::set($ck, 1, 2);
        $hongbaolist = Db::name('hongbao')->where(['status'=>1])->limit(1)->select();
        try{
            foreach ($hongbaolist as $item){
                $redpaper = randProfit($item['hongbao1'], ($item['hongbao2']-$item['hongbao1']));
                if($item['type'] == 1){
                    //邀请
                    $yqlist = Db::name('tzjl')->where(['user_id'=>$user['id'], 'child_id'=>['gt',0], 'read'=>0, 'hongbao_id'=>$item['id']])->limit($item['yqrs'])->select();
                    if(count($yqlist) == $item['yqrs'] && $item['yqrs']>0){
                        if($redpaper>0){
                            $yqid = [];
                            foreach ($yqlist as $i=>$item){
                                $yqid[] = $item['id'];
                            }
                            UserAccount::addlog($user['id'], 0, Currency::USDT, CurrencyAction::USDTIncomeByHonbao, $redpaper, '', '邀请抽红包');
                            Db::name('tzjl')->where(['id'=>['in', $yqid], 'read'=>0])->update(['read'=>1]);
                            $hastag = 1;
                        }
                    }
                }elseif($item['type'] == 2){
                    //自己
                    $tzlist = Db::name('tzjl')->where(['user_id'=>$user['id'], 'child_id'=>0, 'read'=>0, 'hongbao_id'=>$item['id']])->select();
                    foreach ($tzlist as $tz){
                        UserAccount::addlog($user['id'], 0, Currency::USDT, CurrencyAction::USDTIncomeByHonbao, $redpaper, '', '投资抽红包');
                        Db::name('tzjl')->where(['id'=>$tz['id'], 'read'=>0])->update(['read'=>1]);
                        $hastag = 1;
                    }
                    if($item['defi_id']>0){
                        $jump = 2;
                    }else{
                        $jump = 3;
                    }
                }
                break;
            }
            Db::commit();
        }catch (Exception $ex){
            Log::error($ex->getMessage().';'.$ex->getFile().';'.$ex->getLine());
            $this->error(__('general_error'));
        }
        if($hastag==0){
            $redpaper = 0;
            $hastag = 2;
        }
        $this->success(__('get_hongbao_success', [$redpaper]), ['usdt'=>Help::formatUsd($redpaper)*1, 'has'=>$hastag, 'jump'=>$jump]);
    }


}