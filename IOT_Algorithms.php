<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Helpers\Helper;
use App\Data\Models\User;
use App\Data\Models\Role;
use App\Data\Models\Task;
use App\Data\Models\Device;
use LaravelFCM\Message\OptionsBuilder;
use App\Data\Models\DeviceToken;
use App\Data\Models\IotErrorLogs;
use App\Data\Models\IotDeviceLogs;
use LaravelFCM\Message\PayloadDataBuilder;
use App\Data\Models\CustomerProduct;
use Illuminate\Support\Facades\Notification;
use LaravelFCM\Message\PayloadNotificationBuilder;
use App\Notifications\IotDeviceNotifications;

use FCM;
use Mail;

class IOT_Algorithms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'IOT:Algorithms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected $now_time = null;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        while (true) {
            $devices = Device::get();
            $this->now_time = Carbon::now();
        
            if (!$devices->isEmpty()) {
                foreach ($devices as $device) {
                    $device_logs = IotDeviceLogs::where('device_id', '=', (string)$device->device_id)->latest()->first();

                    //for compressorDamage, incorrectRefrigerant, refrigerantLeakOrFanDamage, tooHighAmbient
                    if (!empty($device_logs) && $device_logs->compressor_state == 1 && $device_logs->defroster_state == 0) {
                        $records_to_read_time = 4;
                        $oldest_reading = 10;
                        $algo_refresh_rate = 30;

                        $last_compr_off_reading = IotDeviceLogs::where('device_id', '=', (string)$device->device_id)
                            ->where('compressor_state', '=', 0)
                            ->latest()->first();

                        $records_to_read = intval(floor($records_to_read_time / ($algo_refresh_rate / 60)));

                        if (!empty($last_compr_off_reading)) {
                            $array_x_last_readings = IotDeviceLogs::where('device_id', '=', (string)$device->device_id)
                                ->where('created_at', '>', (string)$last_compr_off_reading->created_at)
                                ->orderby('created_at', 'desc')->limit($records_to_read)->get();
                        } else {
                            $filter_time = $this->now_time->subMinute($oldest_reading)->toDateTimeString();

                            $array_x_last_readings = IotDeviceLogs::where('device_id', '=', (string)$device->device_id)
                                ->where('created_at', '>', (string)$filter_time)
                                ->orderby('created_at', 'desc')->limit($records_to_read)->get();
                        }

                        if ($array_x_last_readings->count() >= $records_to_read) {

                            //compressorDamage
                            $this->compressorDamage($device, $device_logs, $array_x_last_readings);

                            //incorrectRefrigerant
                            $this->incorrectRefrigerant($device, $device_logs, $array_x_last_readings);

                            //refrigerantLeakOrFanDamage
                            $this->refrigerantLeakOrFanDamage($device, $device_logs, $array_x_last_readings);

                            //tooHighAmbient
                            $this->tooHighAmbient($device, $device_logs, $array_x_last_readings);
                        }
                    }


                    //checkDefrostingNew
                    $this->checkDefrostingNew($device);

                    //sensorDamage
                    $this->sensorDamage($device);

                }
            }
        }
    }



    public function compressorDamage($device, $device_logs, $array_x_last_readings){
        $p3_max2 = $device->p3_max2;
        $update_device_temp_logs = [];
        $updating_device = [];

        if($this->evalComprFail($p3_max2 ,$array_x_last_readings) == True){

            $customer_product = CustomerProduct::where('iot_device_no', '=', $device->device_id)->whereNull('deleted_at')->orderby('id', 'desc')->first();
            if(!empty($customer_product) && isset($customer_product->customer_id)){

                $user_email = User::where('customer_id', '=', $customer_product->customer_id)->join('user_roles','users.id','=','user_roles.user_id')->whereNull('user_roles.deleted_at')->where('user_roles.role_id', '=', Role::CUSTOMER)->select('users.*')->first();
                if(!empty($user_email) && $device->alg_ref3_stat != 2){

                    $device->customer_product_id = $customer_product->id;
                    $customer_product->device_error = "MESSAGE_CREATE_JOB";

                    //Create Job notification
                    $this->fireNotifications($user_email, $device, 'messages.message_5A', 'messages.error_5A', "create_job");

                    unset($device->customer_product_id);

                    // Update Device Values
                    $updating_device['last_email_sent_compressor_damage'] = $this->now_time->toDateTimeString();
                    $updating_device['alg_ref3_stat'] = 2;

                    Device::where('id', '=', $device->id)->update($updating_device);

                    //create Error Logs
                    IotErrorLogs::insert(array(
                        'device_id' => (string) $device->device_id,
                        'message' => trans('messages.error_5A'),
                        'error_code' => '5A',
                        'temp_log_id' => $device_logs->_id,
                        'created_at' => (string)$this->now_time->toDateTimeString(),
                        'debugging' => array('last_compressor_start_time' => $device->last_compressor_start_time)
                    ));

                    // Update Temp_log Values
                    $update_device_temp_logs['refrigeration_system'] = 1;
                    $update_device_temp_logs['updated_at'] = (string)$this->now_time->toDateTimeString();
                    IotDeviceLogs::where('_id', $device_logs->_id)->update($update_device_temp_logs);
                }


            }
        }else{
            if($device->alg_ref3_stat != 0){
                $updating_device['alg_ref3_stat'] = 0;
                Device::where('id', '=', $device->id)->update($updating_device);
            }
        }


    }

    public function incorrectRefrigerant($device, $device_logs, $array_x_last_readings){

        $p3_max = $device->p3_max;
        $p3_max2 =  $device->p3_max2;
        $updating_device = [];
        $update_device_temp_logs = [];

        if ($this->evalRefrFail($p3_max2, $p3_max, $array_x_last_readings) == True) {

            $customer_product = CustomerProduct::where('iot_device_no', '=', $device->device_id)->whereNull('deleted_at')->orderby('id', 'desc')->first();
            if(!empty($customer_product) && isset($customer_product->customer_id)){

                $user_email = User::where('customer_id', '=', $customer_product->customer_id)->join('user_roles','users.id','=','user_roles.user_id')->whereNull('user_roles.deleted_at')->where('user_roles.role_id', '=', Role::CUSTOMER)->select('users.*')->first();
                if(!empty($user_email) && $device->alg_ref2_stat != 2){

                    $device->customer_product_id = $customer_product->id;
                    $customer_product->device_error = "MESSAGE_CREATE_JOB";

                    //Create Job notification
                    $this->fireNotifications($user_email, $device, 'messages.message_4A', 'messages.error_4A', "create_job");

                    unset($device->customer_product_id);

                    // Update Device Values
                    $updating_device['last_email_sent_incorrect_refrigerant'] = $this->now_time->toDateTimeString();
                    $updating_device['alg_ref2_stat'] = 2;
                    Device::where('id', '=', $device->id)->update($updating_device);

                    //create Error Logs
                    IotErrorLogs::insert(array(
                        'device_id' => (string) $device->device_id,
                        'message' => trans('messages.error_4A'),
                        'error_code' => '4A',
                        'temp_log_id' => $device_logs->_id,
                        'created_at' => (string)$this->now_time->toDateTimeString(),
                        'debugging' => array('last_compressor_start_time' => $device->last_compressor_start_time)
                    ));

                    // Update Temp_log Values
                    $update_device_temp_logs['refrigeration_system'] = 1;
                    $update_device_temp_logs['updated_at'] = (string)$this->now_time->toDateTimeString();
                    IotDeviceLogs::where('_id', $device_logs->_id)->update($update_device_temp_logs);
                }

            }

        }else{

            // Update Device Values
            if($device->alg_ref2_stat != 0){
                $updating_device['alg_ref2_stat'] = 0;
                Device::where('id', '=', $device->id)->update($updating_device);
            }
        }
    }

    public function refrigerantLeakOrFanDamage($device, $device_logs, $array_x_last_readings){

        $p3_min = $device->p3_min;
        $update_device_temp_logs = [];
        $updating_device = [];
        $enable_defroster = array(
            "name"=>"enable_defroster",
            "device_id" => (string) $device->device_id,
            "is_done"=>(int) 0,
            "data"=>"RefrigerantLeakOrFanDamage",
            "created_at" => (string)$this->now_time->toDateTimeString()
        );

        if($array_x_last_readings->count() > 3){
            $array_x_last_readings = $array_x_last_readings->slice(0,3);
        }
        
        if ($this->evalEvapFail($p3_min, $array_x_last_readings) == True) {

            if($device->alg_ref1_stat == 0){

                $tasks = Task::where('name', '=', 'enable_defroster')->latest()->first();

                // If last task was created before 5 minz and is_done = 1
                if(empty($tasks) || ($this->now_time->diffInMinutes($tasks->created_at) > 5 && $tasks->is_done == 1)){
                    Task::insert($enable_defroster);
                    $device->defrost_count = $device->defrost_count + 1;
                }

                //If last email was sent before 5 minz
                //if($this->now_time->diffInHours($device->last_email_sent_refrigerant_leak) > config('app.email_interval') ) {

                    $customer_product = CustomerProduct::where('iot_device_no', '=', $device->device_id)->whereNull('deleted_at')->orderby('id', 'desc')->first();
                    if(!empty($customer_product) && isset($customer_product->customer_id)){

                        $user_email = User::where('customer_id', '=', $customer_product->customer_id)->join('user_roles','users.id','=','user_roles.user_id')->whereNull('user_roles.deleted_at')->where('user_roles.role_id', '=', Role::CUSTOMER)->select('users.*')->first();
                        if(!empty($user_email) && $device->alg_ref1_stat != 1){

                            //Send Mobile notification to Device Owner
                            //$this->fireNotifications($user_email, $device, 'messages.message_3A', 'messages.error_3A');

                            //create Error Logs
                            IotErrorLogs::insert(array(
                                'device_id' => (string) $device->device_id,
                                'message' => trans('messages.error_3A'),
                                'error_code' => '3A',
                                'temp_log_id' => $device_logs->_id,
                                'created_at' => (string)$this->now_time->toDateTimeString(),
                                'debugging' => array('last_compressor_start_time' => $device->last_compressor_start_time)
                            ));

                            // Update Device Values
                            $updating_device['last_email_sent_refrigerant_leak'] = $this->now_time->toDateTimeString();
                            $updating_device['alg_ref1_stat'] = 1;
                            Device::where('id', '=', $device->id)->update($updating_device);


                            // Update Temp_log Values
                            $update_device_temp_logs['refrigeration_system'] = 1;
                            $update_device_temp_logs['updated_at'] = (string)$this->now_time->toDateTimeString();
                            IotDeviceLogs::where('_id', $device_logs->_id)->update($update_device_temp_logs);
                        }
                    }
                //}

            }else{

                //If last email was sent before 5 minz
                if($this->now_time->diffInMinutes($device->last_email_sent_refrigerant_leak) > config('app.email_interval')) {
                    $customer_product = CustomerProduct::where('iot_device_no', '=', $device->device_id)->whereNull('deleted_at')->orderby('id', 'desc')->first();
                    if(!empty($customer_product) && isset($customer_product->customer_id)){

                        $user_email = User::where('customer_id', '=', $customer_product->customer_id)->join('user_roles','users.id','=','user_roles.user_id')->whereNull('user_roles.deleted_at')->where('user_roles.role_id', '=', Role::CUSTOMER)->select('users.*')->first();
                        if(!empty($user_email) && $device->alg_ref1_stat != 2){

                            $device->customer_product_id = $customer_product->id;
                            $customer_product->device_error = "MESSAGE_CREATE_JOB";

                            //Create Job notification
                            $this->fireNotifications($user_email, $device, 'messages.message_3B', 'messages.error_3B', "create_job");

                            unset($device->customer_product_id);

                            //create Error Logs
                            IotErrorLogs::insert(array(
                                'device_id' => (string) $device->device_id,
                                'message' => trans('messages.error_3B'),
                                'error_code' => '3B',
                                'temp_log_id' => $device_logs->_id,
                                'created_at' => (string)$this->now_time->toDateTimeString()
                            ));

                            // Update Device Values
                            $updating_device['last_email_sent_refrigerant_leak'] = $this->now_time->toDateTimeString();
                            $updating_device['alg_ref1_stat'] = 2;
                            Device::where('id', '=', $device->id)->update($updating_device);

                            // Update Temp_log Values
                            $update_device_temp_logs['refrigeration_system'] = 1;
                            $update_device_temp_logs['updated_at'] = (string)$this->now_time->toDateTimeString();
                            IotDeviceLogs::where('_id', $device_logs->_id)->update($update_device_temp_logs);
                        }
                    }
                }
            }
        }else{

            // Update Device Values
            if($device->alg_ref1_stat != 0){
                $updating_device['alg_ref1_stat'] = 0;
                Device::where('id', '=', $device->id)->update($updating_device);
            }
        }

    }

    public function tooHighAmbient($device, $device_logs, $array_x_last_readings){

        $p4_max = $device->p4_max;
        $updating_device = [];

        if($this->evalAmbientFail($p4_max ,$array_x_last_readings) == True){

            if($this->now_time->diffInMinutes($device->last_email_sent_too_high_ambient) > config('app.email_interval')) {

                $customer_product = CustomerProduct::where('iot_device_no', '=', $device->device_id)->whereNull('deleted_at')->orderby('id', 'desc')->first();
                if(!empty($customer_product) && isset($customer_product->customer_id)){

                    $user_email = User::where('customer_id', '=', $customer_product->customer_id)->join('user_roles','users.id','=','user_roles.user_id')->whereNull('user_roles.deleted_at')->where('user_roles.role_id', '=', Role::CUSTOMER)->select('users.*')->first();
                    if(!empty($user_email) && $device->alg_amb_stat != 2){

                        $device->customer_product_id = $customer_product->id;
                        //Send Mobile notification to Device Owner
                        $this->fireNotifications($user_email, $device, 'messages.message_1A', 'messages.error_1A', 'general_iot', array('p4_max' => $p4_max));

                        // Update Device Values
                        $updating_device['last_email_sent_too_high_ambient'] = $this->now_time->toDateTimeString();
                        $updating_device['alg_amb_stat'] = 2;
                        Device::where('id', '=', $device->id)->update($updating_device);

                        //create Error Logs
                        IotErrorLogs::insert(array(
                            'device_id' => (string) $device->device_id,
                            'message' => trans('messages.error_1A'),
                            'error_code' => '1A',
                            'temp_log_id' => $device_logs->_id,
                            'created_at' => (string)$this->now_time->toDateTimeString(),
                            'debugging' => array('last_compressor_start_time' => $device->last_compressor_start_time)
                        ));
                    }
                }
            }
        }else{
            // Update Device Values
            if($device->alg_amb_stat != 0){
                $updating_device['alg_amb_stat'] = 0;
                Device::where('id', '=', $device->id)->update($updating_device);
            }
        }
    }

    public function checkDefrostingNew($device){

        $updating_device = [];

        $defrost_detected = Task::where('device_id', '=', $device->device_id)->whereIn('name', ['enable_defroster','set_defrost_interval'])->orderBy('_id', 'desc')->take($device->no_of_defrost)->pluck('name')->toarray();

        //check if last 3 values are enable_defroster
        if(!empty($defrost_detected) && !in_array('set_defrost_interval',$defrost_detected)){

            // get average Time of enable_defroster
            $average_defrost_interval = Task::where('device_id', '=', (string) $device->device_id)->whereIn('name', ['enable_defroster','set_defrost_interval'])->orderBy('_id', 'desc')->take($device->no_of_defrost)->pluck('created_at')->toarray();
            $time_diff_transaction_vise = [];
            if(!empty($average_defrost_interval)){
                foreach($average_defrost_interval as $key => $time_diff_raw){
                    if($key != 0){
                        $time_diff_transaction_vise[]= (new Carbon($average_defrost_interval[$key-1]))->diff(new Carbon($average_defrost_interval[$key]))->format('%h');
                    }
                }
            }
            $average_time_diff = 0;
            if(count($time_diff_transaction_vise) > 0){
                $average_time_diff = array_sum($time_diff_transaction_vise)/count($time_diff_transaction_vise);
            }
            // get average Time of enable_defroster end


            //check defrost_interval > 1 and last 3 transactions time difference < defrost_interval
            if($device->defrost_interval > 1 && $average_time_diff < $device->defrost_interval) {

                $set_defrost_interval = [
                    'name'=>'set_defrost_interval',
                    'device_id' => (string) $device->device_id,
                    'is_done'=>0,
                    'data'=> $device->defrost_interval - 1,
                    'created_at' => $this->now_time->toDateTimeString()
                ];

                Task::insert($set_defrost_interval);

                if($device->alg_def_stat != 1){
                    $updating_device['alg_def_stat'] = 1;
                    Device::where('id', '=', $device->id)->update($updating_device);
                }

            }elseif($device->defrost_interval <= 1){

                //If last email was sent before 5 minz
                //if($this->now_time->diffInHours($device->last_email_sent_defrosting) > config('app.email_interval')) {

                    $customer_product = CustomerProduct::where('iot_device_no', '=', $device->device_id)->whereNull('deleted_at')->orderby('id', 'desc')->first();
                    if(!empty($customer_product) && isset($customer_product->customer_id)){

                        $user_email = User::where('customer_id', '=', $customer_product->customer_id)->join('user_roles','users.id','=','user_roles.user_id')->whereNull('user_roles.deleted_at')->where('user_roles.role_id', '=', Role::CUSTOMER)->select('users.*')->first();
                        if(!empty($user_email) && $device->alg_def_stat != 2){

                            $device->customer_product_id = $customer_product->id;
                            $customer_product->device_error = "MESSAGE_CREATE_JOB";

                            //Create Job notification
                            $this->fireNotifications($user_email, $device, 'messages.message_2B', 'messages.error_2B',"create_job");

                            unset($device->customer_product_id);

                            // Update Device Values
                            $updating_device['last_email_sent_defrosting'] = $this->now_time->toDateTimeString();
                            $updating_device['alg_def_stat'] = 2;
                            Device::where('id', '=', $device->id)->update($updating_device);

                            //create Error Logs
                            IotErrorLogs::insert(array(
                                'device_id' => (string) $device->device_id,
                                'message' => trans('messages.error_2B'),
                                'error_code' => '2B',
                                'temp_log_id' => null,
                                'created_at' => (string)$this->now_time->toDateTimeString()
                            ));
                        }

                    }
                //}

            }else{
                if($device->alg_def_stat != 0){
                    $updating_device['alg_def_stat'] = 0;
                    Device::where('id', '=', $device->id)->update($updating_device);
                }
            }

        }

    }

    public function sensorDamage($device){

        $updating_device = [];

        if ($device->p1_state == 0 || $device->p2_state == 0 || $device->p3_state == 0 || $device->p4_state == 0 ){

            //If last email was sent before 5 minz
            if($this->now_time->diffInMinutes($device->last_email_sent_probes_error) > config('app.email_interval')) {
                $customer_product = CustomerProduct::where('iot_device_no', '=', $device->device_id)->whereNull('deleted_at')->orderby('id', 'desc')->first();
                if(!empty($customer_product) && isset($customer_product->customer_id)){

                    $user_email = User::where('customer_id', '=', $customer_product->customer_id)->join('user_roles','users.id','=','user_roles.user_id')->whereNull('user_roles.deleted_at')->where('user_roles.role_id', '=', Role::CUSTOMER)->select('users.*')->first();
                    if(!empty($user_email) && $device->alg_probe_stat != 2){

                        $device->customer_product_id = $customer_product->id;
                        $customer_product->device_error = "MESSAGE_CREATE_JOB";

                        //Create Job notification
                        $this->fireNotifications($user_email, $device, 'messages.message_6A', 'messages.error_6A', "create_job");

                        unset($device->customer_product_id);

                        // Update Device Values
                        $updating_device['last_email_sent_probes_error'] = $this->now_time->toDateTimeString();
                        $updating_device['alg_probe_stat'] = 2;
                        Device::where('id', '=', $device->id)->update($updating_device);

                        //create Error Logs
                        IotErrorLogs::insert(array(
                            'device_id' => (string) $device->device_id,
                            'message' => trans('messages.error_6A'),
                            'error_code' => '6A',
                            'temp_log_id' => null,
                            'created_at' => (string)$this->now_time->toDateTimeString()
                        ));
                    }

                }
            }

        }else{
            // Update Device Values
            if($device->alg_probe_stat != 0){
                $updating_device['alg_probe_stat'] = 0;
                Device::where('id', '=', $device->id)->update($updating_device);
            }
        }
    }



    public function evalComprFail($p3_max2 ,$data = []){
        foreach($data as $rec){
            if($rec->temp3 < $p3_max2){
                return False;
            }
        }
        return True;
    }

    public function evalRefrFail($p3_max2, $p3_max ,$data = []){
        foreach($data as $rec){
            if($rec->temp3 < $p3_max || $rec->temp3 > $p3_max2){
                return False;
            }
        }
        return True;
    }

    public function evalEvapFail($p3_min ,$data = []){
        foreach($data as $rec){
            if($rec->temp3 > $p3_min){
                return False;
            }
        }
        return True;
    }

    public function evalAmbientFail($p4_max ,$data = []){
        foreach($data as $rec){
            if($rec->temp4 < $p4_max){
                return False;
            }
        }
        return True;
    }



    public function fireNotifications($user_email, $device, $notification_message, $message_title, $type = 'general_iot', $extra_inputs =[])
    {
        //Mobile Push Notification
        $action_en = 'review';
        $action_ar = 'مراجعه';
        $optionBuiler = new OptionsBuilder();
        $optionBuiler->setTimeToLive(2419200);
        $optionBuiler->setPriority('high');
        $optionBuiler->setContentAvailable(true);

        $customerDeviceTokens = Helper::getDeviceTokens([$user_email->id]);
        if (count($customerDeviceTokens) > 0) {
            foreach ($customerDeviceTokens as $customerDeviceToken) {
                $language = DeviceToken::where('token', '=', $customerDeviceToken)->value('language');

                $message = trans($notification_message);
                $title = trans($message_title);

                if ($language == 'ar') {
                    $message = trans($notification_message, [],'messages', $locale = 'ar');
                    $title = trans($message_title, [],'messages', $locale = 'ar');
                }

                if(!empty($extra_inputs) && isset($extra_inputs['p4_max'])){
                    $message = $message . $extra_inputs['p4_max'] . "’C";
                }

                $notificationBuilder = new PayloadNotificationBuilder($title);
                $notificationBuilder->setBody($message)->setSound('default');
                $dataBuilder = new PayloadDataBuilder();
                $dataBuilder->addData(['custom' => [
                    'view' => ($type == 'create_job') ? Helper::IOT_JOB_NOTIFICATION: Helper::IOT_DEVICE_NOTIFICATION,
                    'view_id' => hashid_encode($device->customer_product_id)
                ]]);
                $option = $optionBuiler->build();
                $notification = $notificationBuilder->build();
                $data = $dataBuilder->build();

                $view = ($type == 'create_job') ? Helper::IOT_JOB_NOTIFICATION: Helper::IOT_DEVICE_NOTIFICATION;
                $view_id = hashid_encode($device->customer_product_id);

                $success = Helper::FCMSendTo($customerDeviceToken, null,
                    [
                        'title'     => $title,
                        'message'   => $message
                    ],
                    [
                        'title'     => $title,
                        'message'   => $message,
                        'view'      => $view,
                        'view_id'   => $view_id,
                        'image'     => ''
                    ]
                );
                //$downstreamResponse = FCM::sendTo($customerDeviceToken, $option, $notification, $data);
                //$success = $downstreamResponse->numberSuccess();
            }
        }

        //Store in notification
        $notificationData = new \StdClass;
        $notificationData->id = $device->device_id;
        $notificationData->title_en = trans($message_title);
        $notificationData->title_ar   = trans($message_title, [],'messages', $locale = 'ar');
        $notificationData->message_en = trans($notification_message);
        $notificationData->message_ar = trans($notification_message, [],'messages', $locale = 'ar');
        $notificationData->module = 'iot';
        $notificationData->module_id = $device->device_id;
        $notificationData->status = 'Error';
        $notificationData->image = \Storage::url(config('app.files.job_file.storage_path')).'/no-issue-placehoder@3x.png';
        $notifiableUser = $user_email;

        Notification::send($notifiableUser, new IotDeviceNotifications($device->id, $notificationData));

        if($type == 'create_job'){
            $notificationData->id = hashid_encode($device->customer_product_id);
            $notificationData->module_id = hashid_encode($device->customer_product_id);
            $notificationData->action_en = $action_en;
            $notificationData->action_ar = $action_ar;
            Notification::send($notifiableUser, new IotDeviceNotifications(null, $notificationData));
        }



    }

}
