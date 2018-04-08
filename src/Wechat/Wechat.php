<?php
namespace App\Common\Wechat;
use Exception;
use Overtrue\Wechat\Server;
use Overtrue\Wechat\Message;
use Overtrue\Wechat\Auth;
use Overtrue\Wechat\Http;
use Overtrue\Wechat\Utils\XML;
use App\Model\Member;
use App\Common\Tools;
/**
* 微信接口基类
*/
class Wechat {

	/*
	*	//微信相关配置
	*/
	
	protected $config ;


	//初始化配置	
	function __construct($config){
		$this->config = $config;
	}

	//获取类属性方法
	public function __get($k) {
		if(array_key_exists($k, $this->config)) {
			return $this->config[$k];
		}
		throw new Exception("undefined property [$k] in class ".self::class);
	}

    public function listen($request) {

        file_put_contents(storage_path('logs').'/events.log',date('Y-m-d H:i:s').'GET:'.json_encode($request->all()),FILE_APPEND);

        $server = new Server($this->app_id, $this->token, $this->encodingAESKey);
        $ret_msg = '感谢关注！';
        $server->on('message', function($message) use ($ret_msg)  {
            //监听消息
            return Message::make('text')->content($ret_msg);
        });
        $server->on('event', function($event) {
            //监听event事件
        });
        return $server->serve();
    }

    /*
    *	微信授权登录
    */
    public function login($request) {
        $auth = new Auth($this->app_id, $this->app_key);
        // 未登录
        if (empty(session('wechat_user'))) {
          return $auth->redirect($to = $this->oauth_callback, $scope = 'snsapi_userinfo', $state = 'STATE');
    	}

    	// 已经登录过
    	$user = session('wechat_user') ;

    	$login_return = $this->login_return;

    	if('user' == $login_return) {
    		return [
                'wechat_user' => $user 
            ];
    	}else if('redirect' == $login_return) {
    		return redirect($this->login_redirect);
    	}else {
    		throw new Exception("undefined return type,please edit WECHAT_LOGIN_RETURN to 'user' or 'redirect' ");
    	}
    }

    /*
	*	微信登录授权回调，获取用户信息
	*/
    public function loginCallback() {

        $auth = new Auth($this->app_id, $this->app_key);
        
        $user = $auth->user();
        if(!$user) {
            return redirect($this->login_url);
        }

        $user = $user->toArray();

        Tools::log($user,'authorize','授权获取用户信息');
        
        $member = Member::insertOrUpdate(['openid' => $user['openid']],$user);

    	session(['wechat_user' => $member]);
        
        Tools::log(['member' => $member],'authorize','授权用户信息入库');

    	return redirect($this->login_url);
    }

    //下单
    /**
    *   调用微信支付接口
    * @param $data
    * =========================
        out_trade_no
        body
        openid
        attach
        total_fee
        notify_url

    * =========================
    */
    public function book($data) {

    }

    /**
    *   取消订单
    * @param
        appid
        mch_id
        out_trade_no
        nonce_str
        sign
    */
    public function cancel($order) {
        
    }

    //支付
    public function buy($data) {

    }


    //退款
    public function refund($refund_order) {

    }

    /**
     *  作用：产生随机字符串，不长于32位
     */
    public static function createNoncestr( $length = 32 ) 
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";  
        $str ="";
        for ( $i = 0; $i < $length; $i++ )  {  
            $str.= substr($chars, mt_rand(0, strlen($chars)-1), 1);  
        }  
        return $str;
    }

    public function getSign($data) {
        //过滤空值
        $data = array_filter($data); 
        //调整数组排序 ，key 按照 ASCII字典序排序
        ksort($data);
        //拼接字符串key=value
        $str = "";
        foreach ($data as $k => $v) {
            // echo $k.'='.$v;
            $str .= $k.'='.$v.'&';
        }

        $str .= 'key='.$this->mch_key;

        $sign = strtoupper(md5($str));

        return $sign;
    }
}