<?php


namespace app\api\controller;


use app\api\library\Help;
use app\api\logic\chongTiLogic;
use app\api\logic\YnpayLogic;
use app\common\controller\Api;
use app\common\model\Tongji;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\CurrencyAction;
use app\common\model\walletuser\UserAccount;
use think\Config;
use think\Db;
use think\Env;
use think\Exception;
use think\Log;
use function fast\e;

class Ynpay extends Api
{

    protected $noNeedLogin = ['depositnotify', 'txnotify'];
    protected $noNeedRight = ['*'];


    public function index(){
        $config = Db::name('configreward')->column('value', 'name');
        //充值区间
        $data['deposit_coin'] = [
            ['name'=>'IDR', 'min'=>$config['javapay_rechare_min'], 'max'=>$config['javapay_rechare_max'], 'status'=>1, 'usdtprice'=>$config['ynusdt'], 'icon'=>getImgUrl('/uploads/IDR24px.png')],
            ['name'=>'USD', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/USD-24px.png')],
            ['name'=>'TRY', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/TRY-24px.png')],
            ['name'=>'VND', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/VND-24px.png')],
            ['name'=>'INR', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/INR-24px.png')],
            ['name'=>'HKD', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/HKD-24px.png')],
            ['name'=>'GBP', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/GBP-24px.png')],
            ['name'=>'EUR', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/EUR-24px.png')],
            ['name'=>'EGP', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/EGP-24px.png')],
            ['name'=>'BRL', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/BRL-24px.png')],
            ['name'=>'AUD', 'min'=>0, 'max'=>0, 'status'=>0, 'usdtprice'=>1, 'icon'=>getImgUrl('/uploads/AUD-24px.png')],
        ];
        //提现银行
        $data['banklist'] = [
            ['code'=>'116', 'name'=>'Bank Aceh Syariah'],['code'=>'1160', 'name'=>'Bank Agris UUS'],['code'=>'945', 'name'=>'Bank IBK Indonesia'],['code'=>'494', 'name'=>'Bank Agroniaga'],['code'=>'466', 'name'=>'Bank Andara'],['code'=>'531', 'name'=>'Anglomas International Bank'],['code'=>'061', 'name'=>'Bank ANZ Indonesia'],['code'=>'020', 'name'=>'Bank Arta Niaga Kencana'],['code'=>'037', 'name'=>'Bank Artha Graha Internasional'],['code'=>'542', 'name'=>'Bank ARTOS/ Bank Jago'],['code'=>'129', 'name'=>'BPD Bali'],['code'=>'459', 'name'=>'Bank Bisnis Internasional'],['code'=>'040', 'name'=>'Bangkok Bank'],['code'=>'558', 'name'=>'BPD Banten'],['code'=>'014', 'name'=>'Bank Central Asia(BCA)'],['code'=>'536', 'name'=>'Bank Central Asia (BCA) Syariah'],['code'=>'133', 'name'=>'Bank Bengkulu'],['code'=>'110', 'name'=>'Bank Jawa Barat(BJB)'],['code'=>'425', 'name'=>'Bank BJB Syariah'],['code'=>'009', 'name'=>'Bank Negara Indonesia(BNI)'],['code'=>'427', 'name'=>'Bank BNI Syariah'],['code'=>'069', 'name'=>'BANK OF CHINA LIMITED'],['code'=>'002', 'name'=>'Bank Rakyat Indonesia(BRI)'],['code'=>'422', 'name'=>'Bank BRI Syariah'],['code'=>'1450', 'name'=>'Bank BNP Paribas'],['code'=>'4510', 'name'=>'Bank Syariah Indonesia(BSI)'],['code'=>'200', 'name'=>'Bank Tabungan Negara (BTN)'],['code'=>'2000', 'name'=>'Bank Tabungan Negara (BTN) UUS'],['code'=>'213', 'name'=>'Bank BTPN'],['code'=>'5470', 'name'=>'BTPN Syariah'],['code'=>'547', 'name'=>'Bank BTPN Syariah'],['code'=>'441', 'name'=>'Wokee/Bukopin'],['code'=>'521', 'name'=>'Bank Bukopin Syariah'],['code'=>'076', 'name'=>'Bank Bumi Arta'],['code'=>'054', 'name'=>'Bank Capital Indonesia'],['code'=>'949', 'name'=>'CTBC Indonesia'],['code'=>'559', 'name'=>'Centratama Nasional Bank(CNB)'],['code'=>'022', 'name'=>'Bank CIMB Niaga'],['code'=>'0220', 'name'=>'Bank CIMB Niaga UUS'],['code'=>'031', 'name'=>'Citibank'],['code'=>'950', 'name'=>'Bank Commonwealth'],['code'=>'112', 'name'=>'BPD DIY'],['code'=>'011', 'name'=>'Bank Danamon'],['code'=>'0110', 'name'=>'Bank Danamon UUS'],['code'=>'046', 'name'=>'Bank DBS Indonesia'],['code'=>'526', 'name'=>'Bank Dinar Indonesia'],['code'=>'111', 'name'=>'Bank DKI'],['code'=>'778', 'name'=>'Bank DKI UUS'],['code'=>'562', 'name'=>'Bank Fama International'],['code'=>'699', 'name'=>'Bank EKA'],['code'=>'161', 'name'=>'Bank Ganesha'],['code'=>'484', 'name'=>'LINE Bank/KEB Hana'],['code'=>'567', 'name'=>'Bank/Bank Harda Internasional'],['code'=>'2120', 'name'=>'Bank Himpunan Saudara 1906'],['code'=>'041', 'name'=>'HSBC'],['code'=>'164', 'name'=>'Bank ICBC Indonesia'],['code'=>'513', 'name'=>'Bank Ina Perdana'],['code'=>'555', 'name'=>'Bank Index Selindo'],['code'=>'146', 'name'=>'Bank of India Indonesia'],['code'=>'115', 'name'=>'Bank Jambi'],['code'=>'472', 'name'=>'Bank Jasa Jakarta'],['code'=>'113', 'name'=>'Bank Jateng'],['code'=>'114', 'name'=>'Bank Jatim'],['code'=>'095', 'name'=>'Bank JTrust Indonesia'],['code'=>'123', 'name'=>'BPD Kalimantan Barat/Kalbar'],['code'=>'1230', 'name'=>'BPD Kalimantan Barat UUS'],['code'=>'122', 'name'=>'BPD Kalimantan Selatan/Kalsel'],['code'=>'1220', 'name'=>'BPD Kalimantan Selatan UUS'],['code'=>'125', 'name'=>'BPD Kalimantan Tengah (Kalteng)'],['code'=>'124', 'name'=>'BPD Kalimantan Timur'],['code'=>'1240', 'name'=>'BPD Kalimantan Timur UUS'],['code'=>'535', 'name'=>'Seabank/Bank Kesejahteraan Ekonomi(BKE)'],['code'=>'121', 'name'=>'BPD Lampung'],['code'=>'131', 'name'=>'Bank Maluku'],['code'=>'008', 'name'=>'Bank Mandiri'],['code'=>'564', 'name'=>'Bank MANTAP'],['code'=>'548', 'name'=>'Bank Multi Arta Sentosa(MAS)'],['code'=>'157', 'name'=>'Bank Maspion Indonesia'],['code'=>'097', 'name'=>'Bank Mayapada'],['code'=>'016', 'name'=>'Bank Maybank'],['code'=>'947', 'name'=>'Bank Maybank Syariah Indonesia'],['code'=>'553', 'name'=>'Bank Mayora Indonesia'],['code'=>'426', 'name'=>'Bank Mega'],['code'=>'506', 'name'=>'Bank Mega Syariah'],['code'=>'151', 'name'=>'Bank Mestika Dharma'],['code'=>'485', 'name'=>'Motion/Bank MNC Internasional'],['code'=>'147', 'name'=>'Bank Muamalat Indonesia'],['code'=>'491', 'name'=>'Bank Mitra Niaga'],['code'=>'048', 'name'=>'Bank Mizuho Indonesia'],['code'=>'503', 'name'=>'Bank National Nobu'],['code'=>'128', 'name'=>'BPD Nusa Tenggara Barat(NTB)'],['code'=>'1280', 'name'=>'BPD Nusa Tenggara Barat (NTB) UUS'],['code'=>'130', 'name'=>'BPD Nusa Tenggara Timur(NTT)'],['code'=>'145', 'name'=>'Bank Nusantara Parahyangan'],['code'=>'028', 'name'=>'Bank OCBC NISP'],['code'=>'0280', 'name'=>'Bank OCBC NISP UUS'],['code'=>'019', 'name'=>'Bank Panin'],['code'=>'517', 'name'=>'Panin Dubai Syariah'],['code'=>'132', 'name'=>'Bank Papua'],['code'=>'013', 'name'=>'Bank Permata'],['code'=>'0130', 'name'=>'Bank Permata UUS'],['code'=>'520', 'name'=>'Bank Prima Master'],['code'=>'167', 'name'=>'QNB KESAWAN'],['code'=>'1670', 'name'=>'QNB Indonesia'],['code'=>'5260', 'name'=>'Bank Oke Indonesia'],['code'=>'089', 'name'=>'Rabobank International Indonesia'],['code'=>'047', 'name'=>'Bank Resona Perdania'],['code'=>'119', 'name'=>'BPD Riau Dan Kepri'],['code'=>'1190', 'name'=>'BPD Riau Dan Kepri UUS'],['code'=>'5010', 'name'=>'Blu/BCA Digital'],['code'=>'523', 'name'=>'Bank Sahabat Sampoerna'],['code'=>'498', 'name'=>'Bank SBI Indonesia'],['code'=>'152', 'name'=>'Bank Shinhan Indonesia'],['code'=>'153', 'name'=>'Bank Sinarmas'],['code'=>'050', 'name'=>'Standard Chartered Bank'],['code'=>'134', 'name'=>'Bank Sulteng'],['code'=>'135', 'name'=>'Bank Sultra'],['code'=>'126', 'name'=>'Bank Sulselbar'],['code'=>'1260', 'name'=>'Bank Sulselbar UUS'],['code'=>'127', 'name'=>'BPD Sulawesi Utara(SulutGo)'],['code'=>'118', 'name'=>'BPD Sumatera Barat'],['code'=>'1180', 'name'=>'BPD Sumatera Barat UUS'],['code'=>'120', 'name'=>'BPD Sumsel Babel'],['code'=>'1200', 'name'=>'Bank Sumsel Babel UUS'],['code'=>'117', 'name'=>'Bank Sumut'],['code'=>'1170', 'name'=>'Bank Sumut UUS'],['code'=>'1530', 'name'=>'Bank Sinarmas UUS'],['code'=>'045', 'name'=>'Bank Sumitomo Mitsui Indonesia'],['code'=>'451', 'name'=>'Bank Syariah Mandiri(BSM)'],['code'=>'042', 'name'=>'Bank of Tokyo'],['code'=>'023', 'name'=>'TMRW/Bank UOB Indonesia'],['code'=>'566', 'name'=>'Bank Victoria International'],['code'=>'405', 'name'=>'Bank Victoria Syariah'],['code'=>'212', 'name'=>'Bank Woori Saudara'],['code'=>'490', 'name'=>'Neo Commerce/Bank Yudha Bhakti'],['code'=>'1120', 'name'=>'BPD_Daerah_Istimewa_Yogyakarta_(DIY)'],['code'=>'5590', 'name'=>'Bank Centratama'],['code'=>'088', 'name'=>'CCB Indonesia'],['code'=>'067', 'name'=>'Deutsche Bank'],['code'=>'032', 'name'=>'JPMORGAN CHASE BANK'],['code'=>'5640', 'name'=>'Bank Mandiri Taspen Pos'],['code'=>'501', 'name'=>'Bank of Scotland (RBS)'],['code'=>'10001', 'name'=>'OVO'],['code'=>'10002', 'name'=>'DANA'],['code'=>'10003', 'name'=>'GOPAY'],['code'=>'10006', 'name'=>'Bank MULTICOR'],['code'=>'10008', 'name'=>'SHOPEEPAY'],['code'=>'10009', 'name'=>'LINKAJA'],['code'=>'10010', 'name'=>'Bank MUTIARA']
        ];
        foreach ($data['banklist'] as &$datum){
            $datum['lower'] = strtolower($datum['name']);
            //提现区间
            if(in_array($datum['code'], ['10001', '10002', '10003', '10008', '10009'])){
                $datum['withdrawrange'] = [$config['javapay_tixian_min']??0, $config['javapay_tixian_max']??0];
            }else{
                $datum['withdrawrange'] = [$config['javapay_widthdraw_min']??0, $config['javapay_widthdraw_max']??0];
            }
        }
        $this->success('ok', $data);
    }

    //充值
    public function pay(){
        $config = Db::name('configreward')->column('value', 'name');
        $user = $this->auth->getUserinfo();
        $channel = $this->request->post('channel', 'IDR'); //IDR.印尼通道
        if($channel == 'IDR'){
            $user['amount'] = $this->request->param('amount', 0);
            if($user['amount'] < $config['javapay_rechare_min']){
                $this->error(__('javapay_rechare_min', [$config['javapay_rechare_min'], $config['javapay_rechare_max']]));
            }
            $user['productDetail'] = $user['recode'].' Online recharge ';
            $user['orderNo'] = createOrderSn('ynpay_order', 'order_no', 'foxpay');
            $user['notifyUrl'] = url('ynpay/depositnotify', [], true, true);
            $result = YnpayLogic::addorder($user);
            if($result && $result['platRespCode'] == 'SUCCESS'){
                //插入数据库
                $config = Db::name('configreward')->column('value', 'name');
                $vo = [];
                $vo['order_no'] = $user['orderNo'];
                $vo['platOrderNum'] = $result['platOrderNum'];
                $vo['user_id'] = $user['id'];
                $vo['amount'] = $user['amount'];
                $vo['price'] = $config['ynusdt'];
                $vo['usdt'] = Help::formatUsdtFloor($vo['amount']/$config['ynusdt']);
                $vo['addtime'] = time();
                Db::name('ynpay_order')->insertGetId($vo);
                $this->success('ok', ['payurl'=>$result['url']]);
            }else{
                $this->error($result['platRespMessage']??'');
            }
        }

    }

    public function depositnotify(){
        try {
            $res = json_decode(file_get_contents('php://input'), true);
            Log::info('印尼支付通知：'.json_encode($res));

            $platSign = $res['platSign'];
            unset($res['platSign']);
            $decryptSign = public_key_decrypt($platSign, Config::get('ynpay.platPublicKey'));

            $params = $res;
            ksort($params);
            $params_str = '';
            foreach ($params as $key => $val) {
                $params_str = $params_str . $val;
            }
            //if($params_str == $decryptSign) {
            if($res['code'] == '00') {
                //获取订单
                $has = Db::name('ynpay_order')->where(['order_no'=>$params['orderNum'], 'platOrderNum'=>$params['platOrderNum'], 'status'=>1])->update(['status'=>2, 'paytime'=>time()]);
                if($has){
                    //更新订单
                    Db::name('ynpay_order')->where(['order_no'=>$params['orderNum'], 'status'=>1])->update(['status'=>2, 'paytime'=>time()]);
                    echo 'SUCCESS';
                }else{
                    Log::info('订单不存在：');
                    echo 'fail';
                }
            }
            else {
                Log::info('code!=00');
                echo 'fail';
            }
            //}
            //else {
            //    echo 'fail';
            //    Log::info('签名不一致：'.$platSign.';'.$decryptSign);
            //}
        }catch (Exception $ex){
            Log::info('印尼支付通知异常：'.$ex->getMessage().';'.$ex->getLine().';'.$ex->getFile());
            echo 'fail';
        }
    }

    public function txnotify(){
        Db::startTrans();
        try {
            $res = json_decode(file_get_contents('php://input'), true);
            Log::info('印尼提现通知：'.json_encode($res));
            $platSign = $res['platSign'];
            unset($res['platSign']);
            $decryptSign = public_key_decrypt($platSign, Config::get('ynpay.platPublicKey'));

            $params = $res;
            ksort($params);
            $params_str = '';
            foreach ($params as $key => $val) {
                $params_str = $params_str . $val;
            }
            $has = Db::name('user_withdraw')->where(['order_sn'=>$params['orderNum'], 'status'=>2, 'platOrderNum'=>$res['platOrderNum']])->find();
            if(!$has){
                throw new Exception('提现记录不存在：跳过');
            }
            if($res['statusMsg'] == 'SUCCESS'){
                if($params['status'] == 2){
                    Db::name('user_withdraw')->where(['order_sn'=>$params['orderNum'], 'status'=>2])->update(['status'=>3, 'uptime'=>time()]);

                    //统计
                    $tongji = [];
                    $tongji['withdraw_num'] = 1;
                    $tongji['withdraw_amount'] = $has['feal_amount'];
                    Tongji::addlog($tongji, $has['user_id']);

                    //统计推荐人提现数据
                    $user = Db::name('user')->find($has['user_id']);
                    chongTiLogic::upReuserWithdraw($user, $has['feal_amount']);

                }elseif($params['status'] == 4){
                    Db::name('user_withdraw')->where(['order_sn'=>$params['orderNum'], 'status'=>2])->update(['status'=>0, 'uptime'=>time(), 'reamrk'=>'代付失败'.$params['statusMsg']]);
                    UserAccount::addlog($has['user_id'], $has['id'], Currency::USDT, CurrencyAction::USDTIncomeByWithdrawFillRefund, $has['amount'], '', '提现失败退回amount');
                }
            }else{
                if($params['status'] == 4){
                    Db::name('user_withdraw')->where(['order_sn'=>$params['orderNum'], 'status'=>2])->update(['status'=>0, 'uptime'=>time(), 'reamrk'=>'代付失败'.$params['statusMsg']]);
                    UserAccount::addlog($has['user_id'], $has['id'], Currency::USDT, CurrencyAction::USDTIncomeByWithdrawFillRefund, $has['amount'], '', '提现失败退回amount');
                }
            }
            Db::commit();
            echo 'SUCCESS';
        }catch (Exception $ex){
            Db::rollback();
            Log::info('印尼提现通知异常：'.$ex->getMessage().';'.$ex->getLine().';'.$ex->getFile());
            echo 'fail';
        }
    }


}