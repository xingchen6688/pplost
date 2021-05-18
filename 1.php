<?php
/**
 * Created by PhpStorm.
 * User: gaoxi
 * Date: 2017-05-18
 * Time: 11:33
 */
namespace Pay\Controller;


class ZYEFController extends PayController
{

    public function Pay($array)
    {
    
        $this->orderXia($array);
    }


    function orderXia  ($array)
    {   

        $checkNum = $_POST['pay_amount'];

  //       if(!is_numeric($checkNum)||strpos($checkNum,".")!==false){
		//   	$response = array('status'=> false,'msg'   => '必须是整数','error'  => 'pay_amount',);
  //           echo json_encode($response, 320);
  //           exit();
		// }
        if(floor($checkNum) != $checkNum){
            $response = array('status'=> false,'msg'   => '必须是整数','error'  => 'pay_amount',);
            echo json_encode($response, 320);
            exit();
        }
        if ($_POST['pay_amount'] < 1 || $_POST['pay_amount'] > 5000) {
            $response = array('status'=> false,'msg'   => '金额范围100-5000元','error'  => 'pay_amount',);
            echo json_encode($response, 320);
            exit();
        }

        $orderid = I("request.pay_orderid");
        $body    = I('request.pay_productname');
        
        //获取设备号
        $reSheBeiInfo = $this->getSheBeiNum($_POST['pay_memberid']);
        if ($reSheBeiInfo == 'NOT') {  $this->errorInfoR('设备未开启或不存在');  }
        $SheBeiNum  = $reSheBeiInfo['nowNumber'];
        $payUrl     = $reSheBeiInfo['url'];
            // var_dump($reSheBeiInfo);
        $parameter = array(
            'code' => 'PddAliwap', // 通道名称
            'title' => 'PddAliwap',
            'exchange' => 100, // 金额比例
            'gateway' => '',
            'orderid' => '',
            'out_trade_id' => $orderid,
            'body'=>$body,
            'channel'=>$array,
            'mashangid'=> $reSheBeiInfo['mashangid'],
            'zyef_shebeinum'=> $reSheBeiInfo['nowNumber']
        );
        // 订单号，可以为空，如果为空，由系统统一的生成
        $return = $this->orderadd($parameter);

 

        //并发临时措施
        // sleep(rand(0,2));
        
        //调整金额代为分
        $_POST['pay_amount'] = $_POST['pay_amount'] * 100;
        //检验下单金额数量
        $whereArr['userid']         = $_POST['pay_memberid'];  //用户id
        $whereArr['shebeinum']      = $SheBeiNum; //设备号
        $whereArr['tpamount']       = $_POST['pay_amount']; //金额
        $whereArr['statuess']       = '1'; //状态
        $acAmountArr = D('zyef_ding')->field(['acamount'])->order('acamount desc')->where($whereArr)->select();

            
        //生成付款金额
        $acAmount  =  0; //金额
        //搜寻出最适合的金额，防止低额化
        for ($i=0; $i < 100; $i++) { 
            $csAmount = $_POST['pay_amount'] - $i;
            if ($csAmount != $acAmountArr[$i]['acamount']) {
                $acAmount = $csAmount;
                break;
            }
        }
        //控制同设备同金额获取次数
        if ($acAmount == 0) {
            $response = array('status'=> false,'msg'   => '获取失败,同设备同金额获取过多','error'  => '4',);
            echo json_encode($response, 320);
            exit();
        }

    // var_dump($acAmountArr);
    // var_dump($acAmount);
    // exit();

        //检验付款金额数量
        $whereArr['userid']         = $_POST['pay_memberid'];  //用户id
        $whereArr['shebeinum']      = $SheBeiNum; //设备号
        $whereArr['acamount']       = $acAmount; //金额
        $whereArr['statuess']       = '1'; //状态
        $acAmountNum = D('zyef_ding')->where($whereArr)->count()[0];
        // echo "设备号：{$SheBeiNum},实际付款金额：{$acAmount},存在数量：{$acAmountNum}";
        if ($acAmountNum != 0) {
            $response = array('status'=> false,'msg'   => '获取失败','error'  => '3',);
            echo json_encode($response, 320);
            exit();
        }

        //写入定位查询表
        $svaeArr['userid']         = $_POST['pay_memberid'];  //用户id
        $svaeArr['tpamount']       = $_POST['pay_amount']; //金额
        $svaeArr['statuess']       = '1'; //状态
        $svaeArr['shebeinum']      = $SheBeiNum; //设备号
        $svaeArr['getdate']        = time(); //获取时间
        $svaeArr['getorder']       = $return['orderid']; //绑定的订单号
        $svaeArr['acamount']       = $acAmount; //实际付款金额
        $reCode = D('zyef_ding')->add($svaeArr);

        //检验支付金额数量
        $whereArr['userid']         = $_POST['pay_memberid'];  //用户id
        $whereArr['acamount']       = $acAmount; //金额
        $whereArr['statuess']       = '1'; //状态
        $whereArr['shebeinum']      = $SheBeiNum; //设备号
        $acAmountNum = D('zyef_ding')->where($whereArr)->count()[0];
        // echo "设备号：{$SheBeiNum},实际付款金额：{$acAmount},存在数量：{$acAmountNum}";
        if ($acAmountNum != 1) {
            $response = array('status'=> false,'msg'   => '获取失败','error'  => '2',);
            echo json_encode($response, 320);
            exit();
        }

        //
        $codeUrl   = urlencode($payUrl);
        $domainUrl = C('DOMAIN');

        //检验写入标记
        if  ($acAmountNum) {
            $response = array(
                'status'=> true,
                'msg'   => '获取成功',
                'data'  => array(
                	'or' => $return['orderid'],
                    'url2' => $codeUrl,
                    'url' => "http://{$domainUrl}/pay_ZYEF_showLKLPayPage?or={$return['orderid']}&money={$acAmount}&tpamount={$_POST['pay_amount']}&con=2"
                ),
            );
            echo json_encode($response,320);
        } else  {
            $response = array(
                'status'=> false,
                'msg'   => '获取失败',
                'error'  => 'a008',
            );
            echo json_encode($response, 320);
        }

    }


    function getSheBeiNum ($memberid)
    {
        //查找这个用户下面所有开启的设备
        $numArr = D('zyef_mall')->where(['memberid' => $memberid, 'status' => '1'])->select();
        // var_dump($numArr);
        $nowNumber = 0; //目前使用的设备号
        $neitNumber = 0; //下一个使用的设备号
        if  (empty($numArr)) {
            return 'NOT';
        }

        $pay_ury = 0;
        $memberid　= '';
        //遍历找出这次使用的设备号
        foreach ($numArr as $key => $value) {
            if ($value['lunxunnum'] == 1) {
                // var_dump($value);
                $nowNumber = $value['mall_id']; //目前使用的设备号
                $pay_ury   = $value['pay_url'];
                $mashangid = $value['mashangid'];
                // var_dump($nowNumber);
                $key2 = $key + 1;
                // var_dump($key);
                $keyNum = count($numArr);
                // var_dump($keyNum);
                if ($key2 > ($keyNum - 1)) {
                    $neitNumber = $numArr[0]['id'];
                } else {
                    $neitNumber = $numArr[$key2]['id'];
                }
                D('zyef_mall')->where(['id' => $value['id']])->save(['lunxunnum' => 0]);
                D('zyef_mall')->where(['id' => $neitNumber])->save(['lunxunnum' => 1]);

            } 
        }


        //查找轮询标记失败, 方案二处理
        if (empty($nowNumber)) {
            //清除所有,状态为0,轮询为1的,轮询标记
            D('zyef_mall')->where(['memberid' => $memberid, 'status' => '0' , 'lunxunnum' => 1])->save(['lunxunnum' => 0]);
            
            //重新抽取数据,写入轮询标记
            $nowNumber = $numArr[0]['mall_id'];
            $pay_ury = $numArr[0]['pay_url'];
            $mashangid = $numArr[0]['mashangid'];
            D('zyef_mall')->where(['id' => $numArr[0]['id']])->save(['lunxunnum' => 1]);
        }
   
        //检查是否抽取成功
        if (empty($nowNumber)) {    return 'NOT';   } 

        return ['url' => $pay_ury, 'nowNumber' => $nowNumber,'mashangid' => $mashangid];
    }



        //正式接口
        function callback ()
        {   
    //生成执行号
            $logBiaoHao = '操作号='. time().rand(100,999).'=';
            $logName = 'zyef3_callback';
            $this->saveLogPay($logBiaoHao.'回调操作开始A---------------------------------------------->', $logName);

    //记录数据
            $this->saveLogPay($logBiaoHao.'回调数据: '.file_get_contents('php://input'), $logName);
            $_POST = json_decode(file_get_contents('php://input'),1);
            	// var_dump($_POST);

    //签名验证
            $key   = 'GGSKOP2329304I092RKKRRK';
            $sign1 = $_POST['sign'];
            unset($_POST['sign']);
            $sign2 = md5($_POST['id'] . $_POST['amount'] . $_POST['getdate']. $key);    
            // var_dump($_POST['id'] . $_POST['amount'] . $_POST['getdate']. $key);
            if  ($sign1 != $sign2) {    
                $this->saveLogPay($logBiaoHao.'签名校验失败'.$sign1.'='.$sign2, $logName);
                $this->saveLogPay($logBiaoHao.'操作中止: ', $logName);
                exit(json_encode(['code' => '4' , 'msg' => "signError,{$sign1}ADM {$sign2}"]));
            }

    //更换金额单位
            $amountD = $_POST['amount'];
            $amountD = $amountD * 100;


    //检验时间锁
            $lklTimesuoWhere['shijian']  = $_POST['getdate'];
            $lklTimesuoWhere['shebei']   = $_POST['id'];
            $timeSuo = D('lkl_timesuo')->where($lklTimesuoWhere)->count();
            // if ($timeSuo != 0) {
            //     $this->saveLogPay("该设备,这个时间点的订单已经被处理过{$_POST['id']},{$_POST['getdate']}");
            //     $this->saveLogPay($logBiaoHao.'操作中止: ', $logName);
            //     exit(json_encode(['code' => '4' , 'msg' => "该设备,这个时间点的订单已经被处理过{$_POST['getdate']},{$_POST['id']}" ]));  
            // }

    //校对时间
            $timeCha = time() - $_POST['getdate']; 
            if ($timeCha > 5000) {
                    $this->saveLogPay($logBiaoHao.'校对服务器与设备时间差'.$timeCha.'秒', $logName);
                    $this->saveLogPay($logBiaoHao.'操作中止: ', $logName);
                    exit(json_encode(['code' => '4' , 'msg' => '传输时间大于50秒']));
          
            } 
            $this->saveLogPay($logBiaoHao.'校对服务器与设备时间差'.$timeCha.'秒', $logName);

    //获取数据,检验定位数据数量
            $whereArr['acamount']       = (string)$amountD; //金额
            $whereArr['shebeinum']      = $_POST['id']; //设备号
            $whereArr['statuess']       = '1'; //状态
            $whereArr['successtime']    = '0'; //状态
            $ecData = D('zyef_ding')->where($whereArr)->select();
            if (count($ecData) != 1) { 
                $this->saveLogPay($logBiaoHao.'定位数据不存在,定位数: '.count($ecData), $logName);
                $this->saveLogPay($logBiaoHao.'操作中止: ', $logName);
                exit(json_encode(['code' => '4' , 'msg' => '定位不存在,定位数: '.count($ecData) ]));
            }
            $ecData = $ecData[0];

            // exit();
    //检验下单时间和回调时间差
            $timeOrderChar = $_POST['getdate'] - $ecData['getdate'];
            if ($timeOrderChar > 300) {
                $this->saveLogPay($logBiaoHao.'数据超时: '.$timeOrderChar, $logName);
                $this->saveLogPay($logBiaoHao.'操作中止: ', $logName);
                exit(json_encode(['code' => '4' , 'msg' => "该笔订单超时{$timeOrderChar}"]));
            }
            $this->saveLogPay($logBiaoHao.'付款使用时间: '.$timeOrderChar, $logName);


    //检验订单数据
            $orderInfo = D('order')->where(['pay_orderid' => $ecData['getorder']])->select();
            $payAmount = $orderInfo[0]['pay_amount'] * 100;
            if ($payAmount != $ecData['tpamount']) {
                $this->saveLogPay($logBiaoHao.'操作中止: '."金额校验失败{$payAmount}={$ecData['tpamount']}", $logName);
                exit(json_encode(['code' => '4' , 'msg' => "金额校验失败{$payAmount}={$ecData['tpamount']}"]));
            }

    //执行回调处理
            $this->EditMoney($ecData['getorder'], '', 0, "", "");

    //写入成功定位时间
            $reCode = D('zyef_ding')->where(['id' => $ecData['id'] ])->save(['successtime' => time()]);
            $this->saveLogPay($logBiaoHao.'成功完成: ', $logName);
            M("order")->where(['pay_orderid' => $ecData['getorder']])->setField("pdd_msg", "PAYOK");
    //写入时间锁, 这个时间点的订单不再处理
            D('lkl_timesuo')->add(['shijian' => $_POST['getdate'], 'shebei' => $_POST['id'],  'amount' => $_POST['amount']]);
            echo json_encode(['code' => '1111' , 'msg' => 'success']);

        }



        //定时处理过期订单
        function dingshi ()
        {
        	// echo 123;
            $time = time() - 360;
            // $aa = D('zyef_ding')->where(" getdate < {$time}")->select();
            // var_dump($aa);
            $aa = D('zyef_ding')->where(" getdate < {$time} and successtime = 0")->delete();

            $this->saveLogPay("删除无效定位数量:{$aa}",'zyef_dingshi');
            echo "删除无效定位数量$aa";     


            $time2 = time() - 10;
            $aa = D('zyef_ding')->where(" successtime < {$time2} and  successtime > 1  ")->delete();
            $this->saveLogPay("删除成功定位数量:{$aa}",'zyef_dingshi');
            echo "删除成功定位数量$aa"; 

        }




        function saveLogPay ( $text, $name = 'TEST000')
        {
                $dir = C('MULVU').'LOG/'.date('Y-m-d');
                if(!is_dir($dir)){
                    mkdir($dir,0777,true);
                }
                $BIDAO =  file_put_contents($dir.'/'.$name.'.txt', "\r\n". date('Y-m-d:H:i:s',time())."-> ".$text, FILE_APPEND);
        }

          
       





    function showLKLPayPage ()
    {
        header('content-type:text/html;charset=utf-8');
        $orderInfo = D('order')->where(['pay_orderid' => $_GET['or']])->select()[0];
        if (empty($orderInfo)) { exit('不存在该订单!'); }
        
        $getTime  = $orderInfo['pay_applydate'] + 180;   //最后支付时间
        $getTime2 = $getTime - time();                   //剩下支付时间
        $this->assign('timeCode', $getTime2);
        $this->assign('memUrl', $orderInfo['pay_turnyurl']);
        $this->assign('status', $orderInfo['pay_status']);
        $this->assign('url', urldecode($_GET['code']) );
        $this->display();
    }


    function getPayInfo(){
        $where['out_trade_id'] = $_POST['order'];
        // var_dump($where);
        $result = M('order')->where($where)->find();
        // var_dump($result);
        $status = $result['pay_status'];
        $url    = $result['pay_turnyurl'];
        if(!empty($result)){
            echo json_encode(['code'=>$status,'url2'=>$url]);
        }else{
            echo json_encode(['code'=>0]);
        }
    }



    function check () 
    {
        $num = '8585';
        if ($_POST['code'] == $num) {
            echo json_encode(['code' => '1111', 'msg' => 'success']);
        } else  {
            echo json_encode(['code' => '4444', 'msg' => 'error']);
        }

        
    }

        //获取截取任务
        function getNullEcCode ()
        {
            $whereSql['userid']    = $_POST['memberid'];
            $whereSql['statuess']  = '1';
            $whereSql['shebeinum'] = $_POST['shebeinum']; //设备号
            $whereSql['eccode']    = '0';
            // var_dump($whereSql);
            $key   = '9U9U9U9I98IU78UI98II99';
            $sign1 = $_POST['sign'];
            $sign2 = md5( $_POST['memberid'].$_POST['shebeinum'].$key );
            // var_dump( $_POST['memberid'].$_POST['shebeinum'].$key );
            // var_dump($sign2);exit();
            // if ($sign1 != $sign2 ) { $this->errorInfoR('签名不正确'); }
                // var_dump($whereSql);
            $RE_goooData = D('zyef_ding')->where($whereSql)->find();
            // var_dump($RE_goooData);
            if (empty($RE_goooData)) { $this->errorInfoR('无任务'); } 
            $RE_goooData['acamount'] = $RE_goooData['acamount'] / 100; 
            echo json_encode(['data' => $RE_goooData, 'code' => 1111, 'msg' => '获取成功']);
        }


        function errorInfoR ($msg='错误',$code=4444)
        {
            exit(json_encode(['code' => $code, 'msg' => $msg]));
        }


        //接收推送的二维码
        function  sendEcCode () 
        {
            $logBiaoHao = '操作号='. time().rand(100,999).'=';
            $logName = 'ZYEF_sendEcCode';
            $this->saveLogPay($logBiaoHao.'回调操作开始---------------------------------------------->', $logName);
            $this->saveLogPay($logBiaoHao.'回调数据: '.file_get_contents('php://input'), $logName);
  
            //检验签名
            $key   = '9U9U9U9I98IU78UI98II99';
            $sign1 = $_POST['sign'];
            $sign2 = md5( $_POST['memberid'].$_POST['shebeinum'].$key );
            if ($sign1 != $sign2 ) { 
            	$aa =  $_POST['memberid'].$_POST['shebeinum'].$key;
            	$this->saveLogPay($logBiaoHao.'前面前参数'.$aa);
            	$this->saveLogPay($logBiaoHao.'签名不正确'.$sign1.'='.$sign2, $logName);

            	$this->errorInfoR('签名不正确'); 
            }

            //检验链接是否存在
            $isEcNum = D('zyef_ding')->where(['eccode' =>  $_POST['eccode']])->count();
            if ($isEcNum > 0) { $this->errorInfoR('付款链接已存在'); }

            //准备条件
            $whereSql['userid']    = $_POST['memberid'];
            $whereSql['statuess']  = '1';
            $whereSql['shebeinum'] = $_POST['shebeinum']; //设备号
            $whereSql['eccode']    = '0';
            // $whereSql['acamount']  = $_POST['acamount'] * 100;
            // $whereSql['acamount']  = (int)$whereSql['acamount'] ;
            $whereSql['getorder']  = $_POST['getorder'];
            $RE_goooData = D('zyef_ding')->where($whereSql)->select();
    		if (count($RE_goooData) != 1) { $this->errorInfoR('信息异常'); }
            $RE_goooData = $RE_goooData[0];
            if (empty($RE_goooData)) {$this->errorInfoR('信息不存在或已过期订单');}


            //写入
            $aa = D('zyef_ding')->where($whereSql)->save(['eccode' => $_POST['eccode']]);
            if ($aa) {
                $this->errorInfoR('success', 1111);
            } else {
                $this->errorInfoR('error', 1111);
            }
        }

        //获取付款链接
        function getPayEcCode ()
        {
            $getorder = $_POST['order'];
            if (empty($getorder)) {
                $this->errorInfoR('error');
            }
            $reData = D('zyef_ding')->where([ 'getorder' => $getorder ])->select();
            if (count($reData) != 1) {
                $this->errorInfoR('信息不正确');
            } else {
                $reData = $reData[0];
                if (empty($reData['eccode'])) {
                     $this->errorInfoR('链接不存在');
                } else {
                    $this->errorInfoR($reData['eccode'], 1111);
                }
                
            }
        }


      	function testOrderGet ()
        {
                // echo 123;
            // $this->URL  = 'http://dotnetpay.net:83/pay_index'; //分流入口
            $this->URL  = 'http://'.C('DOMAIN').'/pay_index?biao='.$_GET['biao'];
            $amount     = $_POST['amount'];
            if($_POST['code'] == '3'){
                $id = '48';
                $_POST['pay_memberid'] = '10048';
                if($_POST['paycode'] == '1'){
                    $bankCode = '624';
                }elseif($_POST['paycode'] == '2'){
                    $bankCode = '643';
                }elseif($_POST['paycode'] == '3'){
                    $bankCode = '644';
                    exit('通道维护中');
                }
                if(!$_POST['amount']){
                    $amount = '1000';
                }else{
                    $amount = $_POST['amount'];
                }
                if($amount > 50000){
                    exit('测试金额不能超过50000');
                }
                // var_dump($_POST);exit;
            }else{
                $_POST = $_GET;
                $bankCode = $_POST['pay_bankcode'];
                $amount     = $_POST['amount'];
                $pay_memberid = $_POST['pay_memberid'];
                $id = $pay_memberid - 10000;
            }
            // echo "<pre>";
            // var_dump($_POST);
            $key = D('member')->field(['apikey'])->where(['id' => $id])->select()[0]['apikey'];
      
            $this->FUNC_ORDERID             =  'TS'.time().rand(1000,9999);
            $sendData['pay_memberid']       =   $_POST['pay_memberid'];
            $sendData['pay_applydate']      =   date('Y-m-d H:i:s');
            $sendData['pay_orderid']        =   $this->FUNC_ORDERID;
            $sendData['pay_amount']         =   $amount;
            $sendData['pay_notifyurl']      =   "http://".C('DOMAIN')."/pay_YSF2_callBackfun";
            $sendData['pay_callbackurl']    =   "http://".C('DOMAIN')."/PaySuccess.html";
            $sendData['pay_bankcode']       =   $bankCode;
            $sendData['pay_md5sign']        =   $this->createSignZYEF($key, $sendData);
            $sendData['pay_productname']    =   'TS88';
     
            $reInfo  = $this->postData2($this->URL, $sendData);    
            $dataArr = json_decode($reInfo, true);
            // echo "<pre>";
            // var_dump($sendData);exit();

            

            $tdToText['628'] = '中原'; 
            $tdToText['630'] = '支付宝网关'; 
            $tdToText['623'] = '农信'; 
            // $tdToText['628'] = '中原'; 
            // 
            // echo '<pre>';
            // var_dump($dataArr);
            if (!$dataArr['status']) {
                echo "<H1>获取失败, 请再次刷新页面</H1>";
                echo '<pre>';
                // var_dump($sendData);
                // var_dump($reInfo);
                var_dump($dataArr);
                exit();
            }

            if($_POST['code'] == '3'){
                $pay_url = $dataArr['data']['url'];
                Header("Location: $pay_url");exit();
            }

	        $this->assign('amount', $amount);	
	        $this->assign('or', $this->FUNC_ORDERID);
	        $this->assign('memberid', $_POST['pay_memberid'] );
	        $this->assign('tongdaoid', $tdToText[$bankCode]   );
        	$this->assign('url', $dataArr['data']['url'] );
        	$this->assign('url2',  $dataArr['data']['url2'] );
        	$this->display();

        }




// 发送地址=http://39.101.209.230:83/pay_DaiFuLS_index.html
// pay_memberid=商户号
// realname=户主姓名
// bankname=银行名称
// pay_account=银行账号
// pay_notifyurl=接收通知地址
// pay_amount=提款金额
// pay_orderid=订单ID
// pay_applydate=提交时间
// pay_md5sign =签名


        function testOrder ()
        {


            $bankCode = $_POST['pay_bankcode'];
             
            $this->URL  = 'http://39.101.209.230:83/pay_index'; //分流入口
            // $this->URL  = 'http://39.101.209.230:83/pay_DaiFuLS_index.html'; //分流入口
            // $this->URL  = $_POST['sendcurl'];
            $amount     = $_POST['amount'];
            $pay_memberid = $_POST['pay_memberid'];
            $id = $pay_memberid - 10000;
  
            $key = D('member')->field(['apikey'])->where(['id' => $id])->select()[0]['apikey'];
     
      
            $this->FUNC_ORDERID             =  'PING'.time();
            $sendData['pay_memberid']       =   $_POST['pay_memberid'];
            $sendData['pay_applydate']      =   date('Y-m-d H:i:s');
            $sendData['pay_orderid']        =   $this->FUNC_ORDERID;
            $sendData['pay_amount']         =   $amount;
            $sendData['pay_notifyurl']      =   "http://".C('DOMAIN')."/pay_PING_callBack";

            // $sendData['realname']           =   "AA";
            // $sendData['bankname']           =   '中国银行';
            // $sendData['pay_account']        =   '12312341231231';
            $sendData['pay_bankcode']       = $bankCode;
      

            // var_dump($key);exit();
            $sendData['pay_md5sign']        =  $this->createSignZYEF($key, $sendData);

            
            $sendData['pay_productname']    = "AA22";
     
            $reInfo  = $this->postData2($this->URL, $sendData);    
            $dataArr = json_decode($reInfo, true);

            var_dump($sendData);
            var_dump($reInfo);
            var_dump($dataArr);


        }

                        
        function testDFOrder ()
        {
        
             
            // $this->URL  = 'http://api.kan9.site:99/Orderddf/daifu'; //分流入口
            $this->URL  = 'http://39.101.209.230:83/pay_DaiFuLSM_index.html'; //分流入口
            // $this->URL  = $_POST['sendcurl'];
            $amount     = '120';
            $pay_memberid = '10047';
            $id = $pay_memberid - 10000;
  
            $key = D('member')->field(['apikey'])->where(['id' => $id])->select()[0]['apikey'];
            // $key = 'OFTceiLNao3LYEAmtmXvFVttQi0jRqBu';
      
            $this->FUNC_ORDERID             =  'PING'.time();
            $sendData['pay_memberid']       =   $pay_memberid;
            $sendData['pay_applydate']      =   date('Y-m-d H:i:s');
            // $sendData['pay_orderid']        =   $this->FUNC_ORDERID;
             $sendData['pay_orderid']        =   'DF'.time();
            $sendData['pay_amount']         =   '1';
            // $sendData['pay_notifyurl']      =   "http://".C('DOMAIN')."/pay_PING_callBack";
            $sendData['pay_notifyurl']      =   "http://www.baidu.com";
            $sendData['realname']           =   "测试1号";
            $sendData['bankname']           =   'BCOM';
            $sendData['pay_account']        =   '6217003270002216869';
            // $sendData['pay_bankcode']       =   $bankCode;
        

    

            $sendData['pay_md5sign']        =  $this->createSignZYEF($key, $sendData);
            echo  '<pre>';
            var_dump($sendData);
            
            $sendData['pay_productname']    = "AA22";

            $reInfo  = $this->postData2($this->URL, $sendData);    
            // $dataArr = json_decode($reInfo, true);

            // var_dump($sendData);
            var_dump($reInfo);
            // var_dump($dataArr);


        }

        function userAddDFOrder ()
        {
            

            $bankCode = $_POST['pay_bankcode'];
             
            $this->URL  = 'http://api.kan9.site:99/Orderddf/daifu'; //分流入口
            // $this->URL  = 'http://39.101.209.230:83/pay_DaiFuLS_index.html'; //分流入口
            // $this->URL  = $_POST['sendcurl'];
            $amount     = $_POST['pay_amount'];
            $pay_memberid = $_POST['pay_memberid'];
            $id = $pay_memberid - 10000;
  
            // $key = D('member')->field(['apikey'])->where(['id' => $id])->select()[0]['apikey'];
            $key = 'OFTceiLNao3LYEAmtmXvFVttQi0jRqBu';
      
            $this->FUNC_ORDERID             =  'PING'.time();
            $sendData['pay_memberid']       =   $_POST['pay_memberid'];
            $sendData['pay_applydate']      =   date('Y-m-d H:i:s');
            $sendData['pay_orderid']        =   $this->FUNC_ORDERID;
            $sendData['pay_amount']         =   $amount;
            $sendData['pay_notifyurl']      =   "http://".C('DOMAIN')."/pay_PING_callBack";
            $sendData['realname']           =   "冯水群";
            $sendData['bankname']           =   '5';
            $sendData['pay_account']        =   '6217003230041779426'.time();
            $sendData['pay_bankcode']       =    $bankCode;
      

            // var_dump($sendData);exit();

            $sendData['pay_md5sign']        =  $this->createSignZYEF($key, $sendData);

            
            $sendData['pay_productname']    = "AA22";
            // $sendData = 'pay_md5sign=E0862EE178086A53B048DAB57885E12D&pay_amount=16297.0&pay_memberid=1014592&bankname=8&pay_orderid=2019110115470684&pay_notifyurl=http%3A%2F%2Fwww.baidu.com&pay_applydate=2019-11-01+12%3A44%3A36&realname=%E7%8E%8B%E5%9F%B9%E6%B7%9E&pay_account=6212262201043847450';
            $reInfo  = $this->postData2($this->URL, $sendData);    
            $dataArr = json_decode($reInfo, true);

            // var_dump($sendData);
            var_dump($reInfo);
            // var_dump($dataArr);


        }




        //本系统签名方式
        protected function createSignZYEF($Md5key, $list)
        {
            ksort($list);
            $md5str = "";
            foreach ($list as $key => $val) {
                if(!empty($val)){
                    $md5str = $md5str . $key . "=" . $val . "&";
                }
            }
            return strtoupper(md5($md5str . "key=" . $Md5key));
        }

        public function postData2 ($url, $data)
        {     
            $ch = curl_init();     
            $timeout = 60;
            //需要转换的商户列表  
            curl_setopt($ch, CURLOPT_URL, $url);    
            curl_setopt($ch, CURLOPT_REFERER, "http://localhost");  //站点  
            curl_setopt($ch, CURLOPT_POST, true);     
            // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);     //设置是否检查SSL
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);            //设置发送内容   
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);            //拒绝自动输出返回内容
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);            //设置超时时间  
            // curl_setopt($ch, CURLOPT_TIMEOUT, 5);                //设置cURL允许执行的最长秒数。     
            $handles = curl_exec($ch);                              //执行
            curl_close($ch);                                        //关闭资源
            return $handles;     
        } 

        //保存手机错误信息
        function saveIphoneErrLog ()
        {
        	// echo 123;
        	$logBiaoHao = '操作号='. time().rand(100,999).'=';
            $logName    = 'ZYEF_IphoneErrLog';
            // var_dump(file_get_contents('php://input'));
           
            // var_dump($_POST);
            // var_dump($_GET);

            $saveInfo  = json_decode(file_get_contents('php://input'),1);
            $saveInfo  = http_build_query($saveInfo);

            $this->saveLogPay22($logBiaoHao.'操作开始---------------------------------------------->', $logName);
            $this->saveLogPay22($logBiaoHao.$saveInfo, $logName);	
            echo json_encode(['code' => 1, 'msg' => 'success']);
        }
        //临时保存方法
        function saveLogPay22 ($text)
        {
    	    $dir = C('MULVU').'LOG/';
    	    $name = 'Iphone';
            $BIDAO =  file_put_contents($dir.'/'.$name.'.txt', "\r\n". date('Y-m-d:H:i:s',time())."-> ".$text, FILE_APPEND);

        }


        //现实手机错误信息
        function showIphoneErrLog ()
        {
    	 	$dir = C('MULVU').'LOG/';
    	    $name = 'Iphone';
            $BIDAO =  file_get_contents($dir.'/'.$name.'.txt', "\r\n". date('Y-m-d:H:i:s',time())."-> ".$text);
            var_dump($BIDAO);
        }

        function  qing ()
        {
        	D('zyef_ding')->where(['shebeinum' => '47abc'])->save(['eccode' => 0]);
        }


        function test2(){
            $this->display();
        }



    function testcallback ()
    {

        $D = $this->avToArr(file_get_contents('php://input'),1);
        var_dump($D);
        $id  =  $D['memberid'] -10000;

        $key = D('member')->field(['apikey'])->where(['id' => $id])->select()[0]['apikey'];
        $SI1 = $D['sign'];
        unset($D['sign']);
        $SI2 = $this->creSI($key, $D);
        var_dump($id);
        var_dump($key);
        var_dump($SI1);
        var_dump($SI2);
        echo 123;
    }
// amount=301.00&datetime=20200402204327&memberid=10201&orderid=P15858312044886529&returncode=00&transaction_id=20200402204004529952&key=5blewa3cjpwh83wnh0n4cs17j5e4e1pw
        protected function creSI($Md5key, $list)
        {
            ksort($list);
            $md5str = "";
            foreach ($list as $key => $val) {
                if(!empty($val)){
                    $md5str = $md5str . $key . "=" . $val . "&";
                }
            }
            var_dump($md5str . "key=" . $Md5key);
            return strtoupper(md5($md5str . "key=" . $Md5key));
        }


    function  avToArr ($str)
    {
        $data = explode('&', $str);
        $D = [];
        foreach ($data as $key => $value) {
            $C = explode('=', $value);
            $D[$C[0]] = $C[1];
        }
        return $D;
    }


        // function dxdfcs ()
        // {


        //     $MEM_ID   = '200401589';
        //     $ORDER_ID = $_GET['orderid'].time();
        //     $URL      = 'http://juhecai.site/Payment_Dfpay_add.html'; //网关
        //     $KEY      = '1hqtasncroe7nz1qt9kht5eujei2vxi9';

        //     $D['mchid']          =  $MEM_ID ; //   商户号 是   是   平台分配商户号
        //     $D['out_trade_no']   =  $ORDER_ID ; //    订单号 是   是   保证唯一值
        //     $D['money']          =  '100' ; //   金额  是   是   单位：元
        //     $D['bankname']       =  '中国银行' ; //    开户行名称   是   是   
        //     $D['subbranch']      =  '中国银行' ; //   支行名称    是   是   
        //     $D['accountname']    =  '测试1号' ; // 开户名 是   是   
        //     $D['cardnumber']     =  '12312312312332132' ; //  银行卡号    是   是   
        //     $D['province']       =  '山东' ; //    省份  是   是   
        //     $D['city']           =  '沈阳' ; //    城市  是   是   
        //     // $D['extends']        =  '' ; // 附加字段    否   是   格式：数组，具体需要哪些字段以及字段的含义，对接时请咨询上级站点，如果不需要扩展字段不传


        //     ksort($D);
        //     $md5str = "";
        //     foreach ($D as $key => $val) {
        //         $md5str = $md5str . $key . "=" . $val . "&";
        //     }
        //     // exit();
        //     $sign = strtoupper(md5($md5str . "key=" . $KEY));
        //     $D['pay_md5sign']    =  $sign ; // 签名  否   否   

        //     echo '<pre>';
        //     var_dump($D);

        //     $info = $this->postData2($URL, $D);
        //     var_dump($info);

        // }



        // function daichen ()
        // {
        //     $URL      = 'http://juhecai.site/Payment_Dfpay_query.html'; //网关
        //     $KEY      = '1hqtasncroe7nz1qt9kht5eujei2vxi9';

        //     $D['out_trade_no']      = '123123213' ; //    商户订单号   是
        //     $D['mchid']             = '200401589' ; //   商户号 是
        //     ksort($D);
        //     $md5str = "";
        //     foreach ($D as $key => $val) {
        //         $md5str = $md5str . $key . "=" . $val . "&";
        //     }
        //     // exit();
        //         $sign = strtoupper(md5($md5str . "key=" . $KEY));
        //     $D['pay_md5sign']    =  $sign ; // 签名  否   否   

        //     echo '<pre>';
        //     var_dump($D);

        //     $info = $this->postData2($URL, $D);
        //     $infoArr  = json_decode($info , 1);

            
        //     var_dump($infoArr);


        // }


    //清除标记
    function clearDdlx(){
        $c_time = strtotime(date("Y-m-d 00:00:00",strtotime("-2 day")));
        $e_time = strtotime(date("Y-m-d 23:59:59",strtotime("-2 day")));
        $where['pay_applydate'] = array('between',"$c_time,$e_time");
        $data['ddlx'] = '1';
        $result = M('order')->where($where)->save($data);
        // $result = M('order')->field('id,FROM_UNIXTIME(pay_applydate) time,ddlx')->where($where)->select();
        // echo "<pre>";
        // var_dump($result);
        if($result){
            $response = array('status'=> true,'msg'   => '清除随机数标记：'.$result);
        }else{
            $response = array('status'=> false,'msg'   => '没有可清除订单');
        }
        $this->saveLogPay($response['msg'], 'ZYEF_clearDDLX');
        echo json_encode($response, 320);
    }











}
