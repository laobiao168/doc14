<?php


namespace app\api\controller;


use app\api\logic\UserLogic;
use app\common\library\MyRedis;
use app\common\model\RedisKey;
use app\common\model\Tongji;
use app\common\model\walletuser\Currency;
use app\common\model\walletuser\Tag;
use app\common\tron\TronBase;
use app\common\tronx\Api;
use app\common\udun\UdunServer;
use GuzzleHttp\Client;
use think\Controller;
use think\Db;
use think\Exception;

class Test extends Controller
{



    public function index(){
        $this->check();
    }

    private function add(){
        $host = "http://email.market.alicloudapi.com";
        $path = "/domain/add?name=sharecy.store";
        $method = "POST";
        $appcode = "fc4b352dbe8647ff93d09f8cbd6f78a7";
        $headers = array();
        array_push($headers, "Authorization:APPCODE " . $appcode);
        //根据API的要求，定义相对应的Content-Type
        array_push($headers, "Content-Type".":"."application/x-www-form-urlencoded; charset=UTF-8");
        $querys = "";
        $bodys = '';
        $url = $host . $path;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($curl, CURLOPT_HEADER, false);
        if (1 == strpos("$".$host, "https://"))
        {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_setopt($curl, CURLOPT_POSTFIELDS, $bodys);
        $result = curl_exec($curl);
        curl_close($curl);echo $result;exit;
        return json_decode($result, true);
    }

    private function check(){
        $host = "http://email.market.alicloudapi.com";
        $path = "/domain/list?name=foxpayinc.com";
        $method = "POST";
        $appcode = "fc4b352dbe8647ff93d09f8cbd6f78a7";
        $headers = array();
        array_push($headers, "Authorization:APPCODE " . $appcode);
        //根据API的要求，定义相对应的Content-Type
        array_push($headers, "Content-Type".":"."application/x-www-form-urlencoded; charset=UTF-8");
        $querys = "";
        $bodys = '';
        $url = $host . $path;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($curl, CURLOPT_HEADER, false);
        if (1 == strpos("$".$host, "https://"))
        {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_setopt($curl, CURLOPT_POSTFIELDS, $bodys);
        $result = curl_exec($curl);
        curl_close($curl);echo $result;exit;
        return json_decode($result, true);
    }

    public function update(){

        $host = "http://email.market.alicloudapi.com";
        $path = "/domain/update";
        $method = "POST";
        $appcode = "fc4b352dbe8647ff93d09f8cbd6f78a7";
        $headers = array();
        array_push($headers, "Authorization:APPCODE " . $appcode);
        $querys = "name=esharecenter.com&newName=sharecy.store";
        $bodys = "";
        $url = $host . $path . "?" . $querys;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        if (1 == strpos("$" . $host, "https://")) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }
        var_dump(curl_exec($curl));

    }

}