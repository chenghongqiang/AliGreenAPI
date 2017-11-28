<?php
/**
 * AliGreenAPI
 * User: kewin.cheng
 * Date: 2017/11/16
 * Time: 13:39
 */

namespace aliyuncs;

use Green\Request\V20170112 as Green;

include_once 'aliyun-php-sdk-core/Config.php';

/**
 * $aliGreentAPI = AliGreenAPI::getInstance();
 * ------------单一数据---------------
 * $aliGreentAPI->checkText("在哪里场所可以进行xingjiaoyi");
 * $aliGreentAPI->checkImg("http://nos.netease.com/yidun/2-0-0-4f903f968e6849d3930ef0f50af74fc2.jpg");
 *
 * ------------多条数据---------------
 * 文本检测
 * $textArr = array("haha", "放学了", "交易");
 * $aliGreentAPI->checkText($textArr);
 * 图片检测
 * $imgArr = array("http://dun.163.com/res/web/case/terror_danger_3.jpg?3febae60454e63d020d04c66015a65e3",
 *       "http://nos.netease.com/yidun/2-0-0-4f903f968e6849d3930ef0f50af74fc2.jpg");
 * $aliGreentAPI->checkImg($imgArr);
 *
 * Class AliGreenAPI
 * @package app\components\aliyuncs
 */

class AliGreenAPI{

    private static $_instance;

    private function __construct(){

    }

    private function __clone(){
        trigger_error("clone is not allowed", E_USER_ERROR);
    }

    /**
     * Get the acs client
     * @return \DefaultAcsClient
     */
    private function getClient(){
        date_default_timezone_set("PRC");
        $ak = parse_ini_file("aliyun.ak.ini");

        $iClientProfile = \DefaultProfile::getProfile("cn-shanghai", $ak["accessKeyId"], $ak["accessKeySecret"]);
        \DefaultProfile::addEndpoint("cn-shanghai", "cn-shanghai", "Green", "green.cn-shanghai.aliyuncs.com");
        $client = new \DefaultAcsClient($iClientProfile);

        return $client;
    }

    /**
     * create the singleton
     * @return AliGreenAPI
     */
    public static function getInstance(){

        if(empty( self::$_instance)){
            $class = get_called_class();
            self::$_instance  = new $class();
        }

        return self::$_instance;
    }

    /**
     * scene 风险场景，和传递进来的场景对应
     * suggestion 建议用户处理，取值范围：[“pass”, “review”, “block”], pass:图片正常，review：需要人工审核，block：图片违规，
     *            可以直接删除或者做限制处理
     * label 文本的分类
     * rate 浮点数，结果为该分类的概率；值越高，越趋于该分类；取值为[0.00-100.00]
     * extras map，可选，附加信息. 该值将来可能会调整，建议不要在业务上进行依赖
     *
     *  -10000  检测数据有问题
     *  10000  检测数据正常
     *  20000  检测出异常 重试三次
     * @param $request
     */
    private function processResponse($request){

        $client = $this->getClient();

        try {
            $response = $client->getAcsResponse($request);

            if(200 == $response->code){
                $taskResults = $response->data;
                $flag = true;
                foreach ($taskResults as $taskResult) {
                    if(200 == $taskResult->code){
                        $this->processSceneResult($taskResult, $flag);
                    }else{
                        $this->echoStr(-2000, 'task process fail:'.$response->code);
                    }
                }
                if($flag == false){
                    $this->echoStr(-10000, 'the scene is not normal');
                }else{
                    $this->echoStr(10000, 'the scene is normal');
                }
            }else{
                $this->echoStr(-2000, 'detect not success. code:'.$response->code);
            }
        } catch (Exception $e) {
            $this->echoStr(-2000, $e);
        }
    }

    /**
     * @param $code
     * @param $msg
     */
    private function echoStr($code, $msg){
        echo json_encode(array(
            'code' => $code,
            'msg' => $msg,
        ));
    }

    /**
     * @param $taskResult
     */
    private function processSceneResult($taskResult, &$flag){
        $sceneResults = $taskResult->results;

        foreach ($sceneResults as $sceneResult) {
            //根据scene和suggetion做相关的处理
            $suggestion = $sceneResult->suggestion;
            $rate = $sceneResult->rate;
            if($suggestion!='pass' && $rate>80){
                $flag = false;
            }
        }

    }

    /**
     * 文本垃圾检测
     * scenes字符串数组：
     *   关键词识别scene场景取值keyword
     *        分类label:正常normal 含垃圾信息spam 含广告ad 涉政politics 暴恐terrorism 色情porn 辱骂abuse
     *                  灌水flood 命中自定义customized(阿里后台自定义)
     *   垃圾检测识别场景scene取值antispam
     *        分类label:正常normal 含违规信息spam 含广告ad 涉政politics 暴恐terrorism 色情porn 违禁contraband
     *                  命中自定义customized(阿里后台自定义)
     *
     * tasks json数组 ，最多支持100个task即100段文本
     * content 待检测文本，最长4000个字符
     *
     * @param $text 支持字符串和数组
     * @return null
     */
    public function checkText($text){

        if(empty($text)){
            return null;
        }

        $request = new Green\TextScanRequest();
        $request->setMethod("POST");
        $request->setAcceptFormat("JSON");

        if(is_array($text)){

            $taskArr = [];
            foreach($text as $k => $v){
                $task = 'task'.$k;
                $$task = array('dataId' =>  md5(uniqid($task)),
                    'content' => $v,
                    'category' => 'post',
                    'time' => round(microtime(true)*1000)
                );
                array_push($taskArr, $$task);
            }
            $request->setContent(json_encode(array("tasks" => $taskArr,
                "scenes" => array("antispam"))));

        }else if(is_string($text)){
            $task1 = array('dataId' =>  md5(uniqid()),
                'content' => $text
            );
            $request->setContent(json_encode(array("tasks" => array($task1),
                "scenes" => array("antispam"))));
        }

        $this->processResponse($request);
    }

    /**
     * 图片检测
     * scenes字符串数组：
     *   图片广告识别scene场景取值ad
     *        分类label: 正常normal 含广告ad
     *   图片鉴黄识别场景scene取值porn
     *        分类label:正常normal 性感sexy 色情porn
     *   图片暴恐涉政识别场景scene取值terrorism
     *        分类label:正常normal terrorism含暴恐图片 outfit特殊装束 logo特殊标识 weapon武器 politics渉政 others	其它暴恐渉政
     *
     * tasks json数组 ，最多支持100个task即100张图片
     *
     * @param $img 支持字符串和数组
     * @return null
     */
    public function checkImg($img){

        if(empty($img)){
            return null;
        }

        $request = new Green\ImageSyncScanRequest();
        $request->setMethod("POST");
        $request->setAcceptFormat("JSON");

        if(is_array($img)){

            $taskArr = array();
            foreach($img as $k => $v){
                $task = 'task'.$k;
                $$task = array('dataId' =>  md5(uniqid($task)),
                    'url' => $v,
                    'time' => round(microtime(true)*1000)
                );
                array_push($taskArr, $$task);
            }
            $request->setContent(json_encode(array("tasks" => $taskArr,
                "scenes" => array("ad", "porn", "terrorism"))));

        }else if(is_string($img)){
            $task1 = array('dataId' =>  md5(uniqid()),
                'url' => $img,
                'time' => round(microtime(true)*1000)
            );
            $request->setContent(json_encode(array("tasks" => array($task1),
                "scenes" => array("ad", "porn", "terrorism"))));
        }

        $this->processResponse($request);
    }

}