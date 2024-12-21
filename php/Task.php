<?php


namespace app\api\controller;


use app\api\logic\ActiveLogic;
use app\api\logic\TaskLogic;
use app\common\controller\Api;
use app\common\model\Tongji;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\CurrencyAction;
use app\common\model\walletuser\UserAccount;
use think\Cache;
use think\Config;
use think\Db;
use think\Exception;
use think\Log;

class Task extends Api
{

    protected $noNeedLogin = [];
    protected $noNeedRight = [];

    /**
     * 心路历程
     */
    public function index(){
        $user = $this->auth->getUserinfo();
        try{
            $dd['tw_title'] = '精彩禮券！';
            $dd['en_title'] = 'Gift certificate';
            $dd['ar_title'] = '        شهادة هدية رائعة';
            $dd['fr_title'] = 'Cadeau certifié';
            $dd['es_title'] = 'Certificado de regalo';
            $dd['ru_title'] = 'Подарочные ваучеры';
            $dd['id_title'] = 'Sertifikat hadiah biasa';
            $dd['tw_info'] = '多種交易類型優惠';
            $dd['en_info'] = 'IMultiple transaction type offers';
            $dd['es_info'] = 'Ofertas para múltiples tipos de transacciones';
            $dd['fr_info'] = 'Offres pour plusieurs types de transaction';
            $dd['ru_info'] = 'Предложения для нескольких типов транзакций';
            $dd['ar_info'] = '    عروض على أنواع المعاملات المختلفة';
            $dd['id_info'] = 'Berbagai jenis penawaran transaksi';
            $site = Config::get("site");
            $data['list'] = TaskLogic::getCouponlist($user,$this->lang);
            $data['title'] = $dd[$this->lang.'_title'];
            $data['coupon_info'] = $dd[$this->lang.'_info'];
        }catch (Exception $ex){
            Log::error($ex->getMessage().';'.$ex->getFile().';'.$ex->getLine());
            $this->error(__('general_error'));
        }
        $this->success('ok', $data);
    }


}