<?php
/**
 * Created by PhpStorm.
 * Pay: greatsir
 * Date: 2018/1/26
 * Time: 下午4:22
 */
namespace app\pay\service;

use app\common\model\PayOrders;
use app\common\service\BaseService;
use app\message\service\MessageService;
use greatsir\RedisClient;
use Overtrue\Pinyin\Pinyin;
use think\Db;
use think\Loader;
use Payment\Common\PayException;
use Payment\Client\Charge;
use Payment\Config;
use Payment\Client\Notify;
use Payment\Notify\PayNotifyInterface;
use app\common\service\TestNotify;
use app\users\service\UserService;
use think\Log;

class PayService extends BaseService
{
    /**
     * 微信支付创建
     */
    public static function pay_creat($uid,$data)
    {
        date_default_timezone_set('Asia/Shanghai');
        $payModel = new PayOrders();

//        验证post值
        if(!is_numeric($data['pay_money'])){
            self::setError([
                'status_code'=> '500',
                'message'    => 'The money is not a number',
            ]);
            return false;
        }

        // 判断是否存在超时订单
        $overtime_data = $payModel->where(['user_id'=>$uid,'is_pay'=>0])->where('end_time','<',time())->field('red_id,order_sn')->select();

        if (!empty($overtime_data)) {
            foreach ($overtime_data as $k=>$v) {
                $payModel->where(['red_id'=> $v['red_id'],'order_sn' => $v['order_sn']])->update(['is_pay' => -1]);
            }
        }

        $order_sn ='WJBS'.date('YmdHis').rand(1000,9999);

        //文字转拼音
        $pinyin = new Pinyin('Overtrue\Pinyin\MemoryFileDictLoader');
        $content_pinyin = implode(',',$pinyin->convert($data['content']));

        //数据准备
        $save_data = [
            'user_id'   => $uid,
            'type'      => $data['type'],
            'se_money'  => $data['pay_money'],
            'se_number' => $data['send_number'],
            'voice'     => $data['voice_url']??'',
            'content'   => $data['content'],
            'content_pinyin' => $content_pinyin,
            'pay_money' => $data['pay_money'],
            'trade_no'  => '',
            'receive'   => 0,
            'create_time' => time(),
            'end_time'  => time()+600,
            'order_sn'  => $order_sn,
            'balance'   => 0,
        ];

        //储存数据
        $list = $payModel::savePayOrder($save_data);
        if (!is_numeric($list['data'])) {
            self::setError([
                'status_code'=> '500',
                'message'    => 'Add data failure'
            ]);
            return false;
        }

        //生成二维码并保存路径
       // $born_url_model = new UserService();

        $born_url = UserService::QR_code($list['data']);

        if ($born_url != false) {
            $up_url = Db::name('send')->where('red_id',$list['data'])->setField('qr_url',$born_url);
            if (!$up_url) {
                self::setError([
                    'status_code'=> '500',
                    'message'    => 'update data failure'
                ]);
                return false;
            }
        }

        //生成随机红包并加入redis
        $total=$data['pay_money'];//红包总金额
        $num=$data['send_number'];// 红包个数
        $min=0.01;//每个人最少能收到0.01元
        $money_arr=array(); //存入随机红包金额结果

        for ($i=1;$i<$num;$i++)
        {
            $safe_total=($total-($num-$i)*$min)/($num-$i);//随机安全上限
            $money= mt_rand($min*100,$safe_total*100)/100;
            $total=$total-$money;
            $money_arr[]= $money;
        }
        $money_arr[] = round($total,2);

        //遍历$money_arr，把数组每一项加入队列，
        $redis = RedisClient::getHandle(0);
        foreach ($money_arr as $k=>$v) {
            $redis->pushList('red_money:'.$list['data'],$v);
        }

        //获取用户信息
        $user_data = Db::name('users')->where('user_id',$uid)->field('user_openid')->find();
        $openid = $user_data['user_openid'];

//        //查看余额,余额足够则不唤起支付
//        if ($user_data['user_balance'] > 0) {
//            $s_money = $user_data['user_balance'] - $data['pay_money'];
//            if ($s_money >= 0) {
//                // 启动事务
//                Db::startTrans();
//                try{
//                    Db::name('send')->where(['user_id'=>$uid,'order_sn'=>$order_sn])->update(['is_success'=>1,'balance'=>$s_money]);
//                    Db::name('user')->where('user_id',$uid)->setDec('user_balance', $data['pay_money']);
//                    // 提交事务
//                    Db::commit();
//                    return true;
//                } catch (\Exception $e) {
//                    // 回滚事务
//                    Db::rollback();
//                    return false;
//                }
//            }
//        }elseif($s_money < 0){
//            Db::startTrans();
//            try{
//                Db::name('send')->where(['user_id'=>$uid,'order_sn'=>$order_sn])->update(['is_success'=>1,'balance'=>$user_data['user_balance']]);
//                Db::name('user')->where('user_id',$uid)->update(['user_balance'=> 0]);
//                // 提交事务
//                Db::commit();
//                $money = abs($s_money);
//            } catch (\Exception $e) {
//                // 回滚事务
//                Db::rollback();
//                return false;
//            }
//        }

        //是否开启测试模式
        $proprotion = Db::name('backstage')->where('id',8)->find();


        if ($proprotion['item']) {
            Log::write('真实模式:'.$proprotion['item']);
            $total = $data['pay_money'];
        }else{
            Log::write('测试模式:'.$proprotion['item']);
            $total = 0.01;
        }

        //统一下单
        $wxConfig = config('wxpay');
        $payData = [
            'body'    => '拜年智力',
            'subject'    => '微聚',
            'order_no'    => $order_sn,
            'timeout_express' => time() + 600,// 表示必须 600s 内付款
            'amount'    =>$total,// 微信沙箱模式，需要金额固定为3.01,$money||$data['pay_money']
            'return_param' => '123',
            'client_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1',// 客户地址
            'openid' => $openid,
            'product_id' => '123',
        ];

        try {
            $ret = Charge::run(Config::WX_CHANNEL_LITE, $wxConfig, $payData);
            /*$info['red_id'] =$list['data'];
            Log::write('package内容：'.$ret['package']);
            parse_str($ret['package'],$prepay_id);
            Log::write('prepay内容:'.json_encode($prepay_id));
            $info['form_id']= $prepay_id['prepay_id'];
            $info['openid']  = $openid;
            Log::write('模板消息参数:'.json_encode($info));
            $res = MessageService::template($info);*/
            $ret['red_id'] = $list['data'];
            return $ret;


        } catch (PayException $e) {
            echo $e->errorMessage();
            exit;
        }



    }



}