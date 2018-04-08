<?php
namespace App\Common\Wechat;

use Overtrue\Wechat\Http;
use Overtrue\Wechat\Utils\XML;
use Overtrue\Wechat\Utils\SignGenerator;
use App\Model\Payment;
use App\Model\Refund;
use App\Common\Tools;

/**
 *
 */
class WFTWechat extends Wechat {
  protected $pay_url;

  function __construct($config) {
    parent::__construct($config);
    $this->pay_url = "https://pay.swiftpass.cn/pay/gateway";
  }


  //下单

  /**
   *  调用微信支付接口
   */
  public function book($unifiedorder) {
    $obj = (object)$unifiedorder;
    $order = $obj->order;
    $time_start = $order->time_start ? $order->time_start : date('Y-m-d H:i:s', time());
    $time_expire = $order->time_expire ? $order->time_expire : date('Y-m-d H:i:s', time() + config('order.pay_expire'));
    $_data = [
      'body' => $obj->productInfo->title,
      'mch_create_ip' => $_SERVER['REMOTE_ADDR'],
      'mch_id' => $this->mch_id,
      'service' => 'pay.weixin.jspay',
      'out_trade_no' => $order->id,
      // 'sub_openid' => $obj->wechat_user->openid,
      'attach' => $obj->productInfo->id,
      'total_fee' => intval($order->price * 100),
      'notify_url' => $this->notify_url,
      'callback_url' => $this->pay_callback, //前端跳转地址
      'time_start' => date('YmdHis', strtotime($time_start)),
      'time_expire' => date('YmdHis', strtotime($time_expire))
    ];
    return $this->send($_data);
  }

  public function cancel($order) {
    return;
  }

  //支付
  public function buy($order) {
    $prepay_conf = $order->prepay_conf;
    $conf = json_decode($prepay_conf);
    if(!property_exists($conf, 'token_id')) {
      return [
        'success' => false,
        'message' => '支付失败。'
      ];
    }
    $token_id = $conf->token_id;
    if(!$token_id) {
      return [
        'success' => false,
        'message' => '支付失败。'
      ];
    }
    $url = "https://pay.swiftpass.cn/pay/jspay";
    $url .= "?token_id=" . $token_id;
    $url .= "&showwxtitle=1";
    return [
      'success' => true,
      'order' => $order,
      'pay_url' => $url
    ];
  }

  public function refund($order) {
    $orderId = $order->id;
    $refund_order = Refund::where('out_trade_no', '=', $orderId)->first();
    if(!$refund_order) {
      $total_fee = intval($order->price * 100);
      $refund_fee = $total_fee;
      //初始化退款订单
      $_refund_data = [
        'appid' => $this->app_id,
        'mch_id' => $this->mch_id,
        'openid' => $order->openid,
        'out_trade_no' => $orderId,
        'out_refund_no' => 'refund_' . $orderId,
        'refund_channel' => 'ORIGINAL',
        'refund_fee' => $refund_fee,
        'total_fee' => $total_fee,
        'refund_time' => date('Y-m-d H:i:s'),
        'refund_status' => 'WAITING_REFUND',
        'auto_refund' => 1,
        'op_user_id' => $this->mch_id
      ];
      $refund_order = Refund::renew(['out_trade_no' => $orderId], $_refund_data);
      Tools::log(json_encode($refund_order), '退款信息初始化');
    }
    $_data = array();
    $_data['mch_id'] = $refund_order->mch_id;
    $_data['out_trade_no'] = $refund_order->out_trade_no;
    $_data['transaction_id'] = $refund_order->transaction_id;
    $_data['out_refund_no'] = $refund_order->out_refund_no;
    $_data['total_fee'] = $refund_order->total_fee;
    $_data['refund_fee'] = $refund_order->refund_fee;
    $_data['op_user_id'] = $refund_order->op_user_id;
    $_data['service'] = 'unified.trade.refund';
    $res = $this->send($_data);
    $res['refund_order'] = $refund_order;
    return $res;
  }

  /*
  *   订单较正
  */
  public function correctOrder($order) {
    if(!$order) {
      return [
        'success' => false,
        'message' => '订单不存在。'
      ];
    }
    $orderId = $order->id;
    //查询支付信息
    $pay_info = Payment::where('out_trade_no', '=', $orderId)->orderBy('created_at', 'desc')->first();
    //退款信息
    $refund_info = Refund::where('out_trade_no', '=', $orderId)->orderBy('created_at', 'desc')->first();
    $data = [
      'service' => 'unified.trade.query',
      'mch_id' => $this->mch_id,
      'out_trade_no' => $orderId,
    ];
    $wpay = $this->send($data);
    if(!$wpay['success']) {
      return [
        'success' => false,
        'message' => '无订单信息。'
      ];
    }
    $order_status = $order->status;
    //订单校验
    //记录异常订单
    $trade_state = $wpay['result']['trade_state'];
    switch($trade_state) {
      case 'SUCCESS':
        $status = [2, 3, - 3];
        if(!in_array($order_status, $status)) {
          //
          $order->status = 2;
          $order->save();
          //检测支付信息是否存在
          if(!$pay_info) {
            //添加支付信息
            $payment = Payment::renew($wpay['result']);
          }
        }
        //检查异常订单
        if($trade_state == - 3) {
          //系统配置为自动退款，则退款
          if(!$refund_info) {
            //初始化退款信息
            // $refund_info = '';
          }
          if($this->auto_refund) {
            $this->refund($refund_info);
          }
          return '订单' . $orderId . '为异常订单，未退款';
        }
        break;
      case 'REFUND':
        # code...
        break;
      case 'NOTPAY':
        if($order_status != 1 && $order_status != - 3) {
          $order->status = 1;
          $order->save();
        }
        break;
      case 'CLOSED': //取消订单
        if($order_status != - 1) {
          $order->status = - 1;
          $order->save();
          if($pay_info) {
            $pay_info->delete();
          }
        }
        break;
      case 'REVOKED':
        # code...
        break;
      case 'USERPAYING':
        # code...
        break;
      case 'PAYERROR':
        # code...
        break;
      default:
        return 'undefined state';
    }
    return $wpay;
  }

  public function send($data = array()) {
    if(!is_array($data)) {
      return [
        'success' => false,
        'message' => '参数错误'
      ];
    }
    $data['nonce_str'] = self::createNoncestr();
    $data['sign'] = self::getSign($data);
    $data = XML::build($data);
    $http = new Http();
    $res = $http->request($this->pay_url, Http::POST, $data);
    if(!$res) {
      return [
        'success' => false,
        'message' => '请求失败。'
      ];
    }
    $res = XML::parse($res);
    if($res['status'] != 0) {
      $ret = [
        'success' => false,
        'code' => $res['status'],
        'message' => $res['message']
      ];
    }else if($res['result_code'] != 0) {
      $code = $res['err_code'];
      if('SYSTEMERROR' == $code) {
        $ret = [
          'success' => false,
          'code' => $res['status'],
          'message' => "系统异常，请稍候重试。"
        ];
      }else if('ORDERNOTEXIST' == $code) {
        $ret = [
          'success' => false,
          'code' => $res['status'],
          'message' => '此交易订单不存在。'
        ];
      }else if('OUT_TRADE_NO_USED' == $code) {
        $ret = [
          'success' => false,
          'code' => $res['status'],
          'message' => '商户订单号重复。'
        ];
      }else {
        $ret = [
          'success' => false,
          'code' => $res['status'],
          'message' => '未知错误。' . $res['err_msg']
        ];
      }
    }else {
      $ret = [
        'success' => true,
        'code' => 0
      ];
    }
    $ret['result'] = $res;
    return $ret;
  }
}