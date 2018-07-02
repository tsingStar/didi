<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018.5.5
 * Time: 21:16
 */

namespace app\app\controller;


use think\Controller;

class BaseDriver extends Controller
{
    protected function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub
        if(!self::checkIsLogin(input('post.driver_id'))){
            exit_json(-1, '司机不存在');
        }
    }

    public function checkIsLogin($driver_id)
    {
        $driver = model('drivers')->where('id', $driver_id)->find();
        if($driver){
            if(!defined('DRIVER_ID')){
                define('DRIVER_ID', $driver_id);
            }
            return true;
        }else{
            return false;
        }

        
    }

}