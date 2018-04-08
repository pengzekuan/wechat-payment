<?php
namespace App\Common\Wechat;
use App\Common\Wechat\Wechat;

use Overtrue\Wechat\Payment;
use Overtrue\Wechat\Payment\Order;
use Overtrue\Wechat\Payment\Business;
use Overtrue\Wechat\Payment\UnifiedOrder;
use Overtrue\Wechat\Utils\XML;
/**
* 
*/
class NativeWechat extends Wechat {
	
	function __construct($config){
		parent::__construct($config);
	}

    //下单
    public function book($unifiedorder) {

        $obj = (object)$unifiedorder;
        $wechat_user = $obj->wechat_user;
        $order = $obj->order;
        $productInfo = $obj->productInfo;

        //调用微信下单接口

        /**
         * 第 1 步：定义商户
         */
        $business = new Business( $this->app_id, $this->app_key, $this->mch_id, $this->mch_key);

        /*
        *   定义订单
        */

        $time_start = $order->created_at ? $order->created_at : date('Y-m-d H:i:s',time());
        $time_start  = date('YmdHis',strtotime($time_start));

        $worder = new Order();
        $worder->body = $productInfo->title; //商品名称
        $worder->detail = $productInfo->description; //商品详情
        $worder->attach = $productInfo->attach; //商品附件
        $worder->out_trade_no = $order->id; //内部订单ID
        $worder->total_fee = intval($order->price * 100);    // 单位为 “分”, 字符串类型
        $worder->openid = $wechat_user->openid;
        $worder->time_start = $time_start;
        $worder->trade_type = 'JSAPI';
        $worder->notify_url = $this->notify_url;

        /**
         * 第 3 步：统一下单
         */
        $unifiedOrder = new UnifiedOrder($business, $worder);

        /**
         * 第 4 步：生成支付配置文件
         */
        $payment = new Payment($unifiedOrder);

        $options = $payment->getConfig();

        if(!$options) {
            return [
                'success' => false,
                'retMessage' => '下单失败,请重新下单',
            ];
        }

        return [
            'success' => true,
            'result' => $options
        ]; 
    }

    public function cancel($order) {
        if(!$order) {
            return [
                'success' => false,
                'message' => '您要取消的订单不存在。'
            ];
        }
        //调用微信关闭订单接口
        $url = "https://api.mch.weixin.qq.com/pay/closeorder";
        $_data = [
            'appid' => $this->app_id,
            'mch_id' => $this->mch_id,
            'out_trade_no' => $order->id,
            'nonce_str' => self::createNoncestr()
        ];

        $_data['sign'] = self::getSign($_data);
        
        $data = XML::build($_data);

        $http = new Http();
        $res = $http->request($url,Http::POST,$data);
        $res = XML::parse($res);
        return $res;
    }

    //支付
    public function buy($order) {
        $url = url('/wechat/pay?token_id='.$order->id);
        return [
            'success' => true,
            'pay_url' => $url
        ];
    }

    public function notify() {
        /*
{"appid":"wxdcc813c475561bab","attach":"代理商1","bank_type":"CFT","cash_fee":"1","fee_type":"CNY","is_subscribe":"Y","mch_id":"1317483901","nonce_str":"b1d516876b5def38889e05ddfeb7461c","openid":"oZNEdwEEJBye1nIi_e8a95jB3goQ","out_trade_no":"add8a11d06fc70afe167e75ac642d496","result_code":"SUCCESS","return_code":"SUCCESS","time_end":"20160318161124","total_fee":"1","trade_type":"JSAPI","transaction_id":"1009010366201603184081889549"}
        */
        $notify = new Notify($this->app_id, $this->app_key, $this->mch_id, $this->mch_key);
        
        $transaction = $notify->verify();
        if (!$transaction) {
            $notify->reply('FAIL', 'verify transaction error');
        }else {
    file_put_contents(storage_path('logs').DIRECTORY_SEPARATOR.'notify.log', '['.date('Y-m-d H:i:s').']'.$transaction->toJson()."\r\n", FILE_APPEND);

            //process payment data $transaction->openid ...
            $res = $transaction->toArray();

            $order = Order::lockForUpdate()->find($updData['out_trade_no']);

            if(!$order) {
                $notify->reply('FAIL', 'order is not find.');
            }

            //过滤不需要的参数
            $fillable = [
                'appid',
                'mch_id',
                'openid',
                'out_trade_no',
                'transaction_id',
                'bank_type',
                'cash_fee',
                'fee_type',
                'is_subscribe',
                'time_end',
                'total_fee',
                'trade_type'
            ];

            $updData = array();
            foreach ($fillable as $k) {
                if(array_key_exists($k, $res)) {
                    $updData[$k] = $res[$k];
                }
            }

            if(array_key_exists('time_end',$updData)) {
                $updData['time_end'] = date('Y-m-d H:i:s',strtotime($updData['time_end']));
            }

            $res = $this->payorder->replace(['openid' => $res['openid']],$updData);
            
            DB::beginTransaction();
            //更新订单状态
            $order->status = 2;

            //添加支付信息
            $payment_model = new PaymentModel($updData);
            $res2 = $payment_model->save();
            //检测异常（超时支付）
            //检测该订单是否失效
            $time_start = strtotime($order->time_start);
            $time_now = time();
            $overdue = ( ( $time_now - $time_start - 2*60*60) > 0 ); //是否过期
            if($overdue) { //异常
                //自动退款

                //记录异常订单退款信息

                //更新订单为异常
                $order->status = -3; //标记异常

            }

            $res1 = $order->save();

            if($res1 && $res2) {
                DB::commit();
                echo $notify->reply(); 
            }else {
                DB::rollback();
                $notify->reply('FAIL', 'sotre order failed');
            }
        }
    }

    public function refund() {
        $business = new Business($this->app_id, $this->app_key, $this->mch_id, $this->mch_key);
        $business->setClientCert(config('wechat.app_cert'));
        $business->setClientKey(config('wechat.app_cert_key'));
        $refund =new Refund($business);
        $refund->out_refund_no= md5(uniqid(microtime()));//退单单号
        $refund->total_fee=1; //订单金额
        $refund->refund_fee=1;//退款金额
        $refund->out_trade_no=$order_id;//原商户订单号
        var_dump($refund->getResponse());
        
    }
}