<?php  
namespace App\Common\Wechat;
use InvalidArgumentException;

/**
* 
*/
class WechatFactory {
	
	public static function getInstance($config) {
		if(array_key_exists('api', $config)) {
			$api = $config['api'];
			switch ($api) {
				case 'native':
					return new NativeWechat($config);

				case 'wft':
					return new WFTWechat($config);
			}
		}
		throw new InvalidArgumentException("invalid wechat api");
		
	}
}