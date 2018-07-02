<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/3/23
 * Time: 13:44
 */

namespace app\app\controller;


class User extends BaseUser
{
    protected function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub
    }

    /**
     * 设置用户位置
     */
    public function setLocation()
    {
        $lat = input('lat');
        $lng = input('lng');
        model('user')->save([
            'lat' => $lat,
            'lng' => $lng
        ], ['id' => USER_ID]);
        exit_json();
    }

    /**
     * 获取周边司机
     */
    public function getCars()
    {
        $lat = input('lat');
        $lng = input('lng');
        //周边范围 单位m
        $range = input('range');
        $drivers = model('drivers')->field('rel_name, car, car_no, lat, lng')->where(['status' => 2, 'is_free' => 0])->select();
        $data = [];
        foreach ($drivers as $d) {
            $distance = GetDistance($lat, $lng, $d['lat'], $d['lng']);
            if ($distance < $range) {
                $data[] = $d;
            }
        }
        if (count($data) > 0) {
            exit_json(1, '获取成功', $data);
        } else {
            exit_json(-1, '周围暂无车辆', []);
        }
    }

    /**
     * 开始叫车
     */
    public function startCall()
    {
        $lat = input('lat');
        $lng = input('lng');
        $start_location = input('start_location');
        $end_location = input('end_location');
        $end_lat = input('end_lat');
        $end_lng = input('end_lng');
        //周边范围 单位m
        $range = input('range');
        $drivers = model('drivers')->field('id, rel_name, car, car_no, lat, lng')->where(['status' => 2, 'is_free' => 0])->select();
        $data = [];
        foreach ($drivers as $d) {
            $distance = GetDistance($lat, $lng, $d['lat'], $d['lng']);
            if ($distance < $range) {
                $data["$distance"] = $d;
            }
        }
        ksort($data);
        $driver = current($data);
        if ($driver) {
            $order_no = time() . rand(1000, 9999);
            $insertData = [
                "order_no" => $order_no,
                "createtime" => time(),
                "user_id" => USER_ID,
                "driver_id" => $driver['id'],
                "start_lat" => $lat,
                "start_lng" => $lng,
                "end_lat" => $end_lat,
                "end_lng" => $end_lng,
                "start_location" => $start_location,
                "end_location" => $end_location
            ];
            //TODO 此处添加计费方式  现在以直线距离为样板使用 速度以30KM/H为均值  车费以每分钟0.5元计算
            $juli = GetDistance($lat, $lng, $end_lat, $end_lng);
            $cost_time = intval($juli / 1000 / 30 * 60);
            $fee = $cost_time * 0.5;
            //此处添加计费方式
            //添加订单，处理司机为忙碌状态
            $order_id = db('order')->insertGetId($insertData);
            model('drivers')->save(['is_free' => 1], ['id' => $driver['id']]);
            $message = [
                'order_id' => $order_id,
                'juli' => $juli,
                'cost_time' => $cost_time,
                'fee' => $fee
            ];
            exit_json(1, '叫车成功，等待司机接单', $message);
        } else {
            exit_json(-1, '周围暂无车辆');
        }
    }

    /**
     * 已上车
     */
    public function getCar()
    {
        $order_id = input('order_id');
        $order = db('order')->where(['id' => $order_id, 'user_id' => USER_ID])->find();
        if ($order['driver_status'] == 2) {
            $update_data = [
                "starttime" => time(),
                "status" => 1,
                "user_status" => 1
            ];
        } else {
            $update_data = [
                "user_status" => 1
            ];
        }
        db('order')->where(['id' => $order_id, 'user_id' => USER_ID])->update($update_data);
        exit_json();
    }

    /**
     * 获取订单状态
     */
    public function getOrderStatus()
    {
        $lat = input('lat');
        $lng = input('lng');
        $order_id = input('order_id');
        $order = db('order')->where('id', $order_id)->find();
        if ($order['status'] == 2) {
            exit_json(1, '订单完成', ['status' => 2]);
        } else if ($order['status'] == 1) {
            $distance = GetDistance($lat, $lng, $order['end_lat'], $order['end_lng']);
            if ($distance < 100) {
                $endtime = time();
                $cost_time = ($endtime - $order['starttime']) / 60;
                $fee = round($cost_time * 0.5, 2);
                $data = [
                    "status" => 2,
                    "endtime" => $endtime,
                    "money" => $fee,
                ];
                //更新订单状态
                db('order')->where('id', $order_id)->update($data);
                //更新司机状态
                model('drivers')->save(['is_free' => 0], ['id' => $order['driver_id']]);
                exit_json(1, '订单完成', ['status' => 2]);
            } else {
                exit_json(1, '订单进行中', ['status' => 1]);
            }
        } else {
            if($order['driver_status'] == 1){
                exit_json(1, '司机已接单', ['status' => 3]);
            }else{
                exit_json(1, '等待司机接单中。。。', ['status' => 0]);
            }
        }
    }

    /**
     * 获取司机信息
     */
    public function getDriverInfo()
    {
        $order_id = input('order_id');
        $order = db('order')->where('id', $order_id)->find();
        $driver_id = $order['driver_id'];
        //司机基本信息
        $driver = model('drivers')->field('name,rel_name,telephone,car,car_no,logo')->where('id', $driver_id)->find();
        //总订单量
        $count = db('order')->where(['driver_id' => $driver_id, 'id' => ['neq', $order_id]])->count();
        //星级
        $star = db('order')->where(['driver_id' => $driver_id, 'id' => ['neq', $order_id], 'point' => ['neq', 0]])->avg('point');
        $driver['count'] = $count;
        $driver['star'] = $star > 0 ? $star : 5;
        exit_json(1, '请求成功', $driver);
    }

    /**
     * 评价订单
     */
    public function pingjia()
    {
        $order_id = input('order_id');
        $point = input('point');
        $pingjia = input('pingjia');
        $order = db("order")->where(['user_id' => USER_ID, 'id' => $order_id])->find();
        if ($order) {
            if (!$order['point']) {
                db('order')->where('id', $order_id)->update(['point' => $point, 'pingjia' => $pingjia]);
                exit_json();
            } else {
                exit_json(-1, '订单已评价过');
            }
        } else {
            exit_json(-1, '订单不存在');
        }
    }

    /**
     * 保存基本资料
     */
    public function saveInfo()
    {
        $name = input('name');
        $file = request()->file('logo');
        if ($file) {
            $info = $file->move(__UPLOAD__ . '/user', md5(microtime() . rand(1000, 9999)));
            if ($info) {
                $saveName = $info->getSaveName();
                $path = "/upload/user/" . $saveName;
            } else {
                // 上传失败获取错误信息
                exit_json(-1, $file->getError());
            }
        } else {
            exit_json(-1, '头像文件不存在');
        }
        $data = [
            'name' => $name,
            'logo' => $path
        ];
        $res = model('user')->save($data, ['id' => USER_ID]);
        if ($res) {
            exit_json();
        } else {
            exit_json(-1, '保存失败');
        }
    }

    /**
     * 获取订单列表
     */
    public function getOrderList()
    {
        $page = input('page');
        $limit = input('limit');
        $offset = ($page - 1) * $limit;
        $orderList = db('order')->where(['user_id' => USER_ID, 'status' => 2])->limit($offset, $limit)->select();
        $data = [];
        foreach ($orderList as $order) {
            $t = [];
            $t['order_id'] = $order['id'];
            $t['start_time'] = date('Y-m-d H:i:s', $order['starttime']);
            $t['end_time'] = date('Y-m-d H:i:s', $order['endtime']);
            $t['start_location'] = $order['start_location'];
            $t['end_location'] = $order['end_location'];
            $data[] = $t;
        }
        exit_json(1, '请求成功', $data);
    }

    /**
     * 查看订单详情
     */
    public function checkOrder()
    {
        $order_id = input('order_id');
        $order = db('order')->where('id', $order_id)->find();
        if (!$order) {
            exit_json(-1, '订单不存在');
        }
        $data = $this->formatOrder($order);
        exit_json(1, '请求成功', $data);


    }

    /**
     * 格式化订单信息
     * @param $order
     * @return array
     */
    private function formatOrder($order)
    {
        $data = [];
        $data['order_no'] = $order['order_no'];
        $data['start_time'] = date('Y-m-d H:i:s', $order['starttime']);
        $data['end_time'] = date('Y-m-d H:i:s', $order['endtime']);
        $data['start_location'] = $order['start_location'];
        $data['end_location'] = $order['end_location'];
        $data['cost_time'] = $order['endtime'] - $order['starttime'];
        $data['money'] = $order['money'];
        $data['point'] = $order['point'];
        $data['pingjia'] = $order['pingjia'];
        if ($order['point'] || $order['pingjia']) {
            $data['is_pingjia'] = 1;
        } else {
            $data['is_pingjia'] = 0;
        }
        $driver = model('drivers')->where('id', $order['driver_id'])->find();
        $data['driver_name'] = $driver['name'];
        $data['telephone'] = $driver['telephone'];
        return $data;
    }

    /**
     * 取消订单
     */
    public function cancelOrder()
    {
        $order_id = input('order_id');
        $order = db('order')->where('id', $order_id)->find();
        if ($order) {
            $driver_id = $order['driver_id'];
            db('order')->where('id', $order_id)->delete();
            model('drivers')->save(['is_free' => 0], ['id' => $driver_id]);
            exit_json();
        } else {
            exit_json(-1, '订单不存在');
        }


    }
}