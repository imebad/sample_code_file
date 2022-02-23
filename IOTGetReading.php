<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Data\Models\Task;
use App\Data\Models\Device;

class IOTGetReading extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reading:get';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add reading temps task to be performed by the RPi';

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
            $now = Carbon::now();

            $devices = Device::join('customer_products', function ($join) {
                $join->on(\DB::raw('BINARY customer_products.iot_device_no'), '=', \DB::raw('BINARY devices.device_id'));
                $join->whereNull('customer_products.deleted_at');
            })->select('devices.*', 'customer_products.id as customer_product_id', 'customer_products.customer_id')->get();


            if (!$devices->isEmpty()) {
                foreach ($devices as $device) {
                    //prepare task
                    $task = [
                        'name' => 'get_probe_temps',
                        'device_id' => (string)$device->device_id,
                        'is_done' => 0,
                        'data' => '',
                        'created_at' => $now->toDateTimeString()
                    ];
                    $get_current_defrost_interval = [
                        'name' => 'get_current_defrost_interval',
                        'device_id' => (string)$device->device_id,
                        'is_done' => 0,
                        'data' => '',
                        'created_at' => $now->toDateTimeString()
                    ];
                    //$read_p3_value = [
                    //    'name'=>'read_p3_value',
                    //    'device_id' => $device->device_id,
                    //    'is_done'=>0,
                    //    'data'=>'',
                    //    'created_at' => Carbon::now()
                    //];
                    //push on the queue
                    $probe_temps_task = Task::where('name', '=', 'get_probe_temps')->where('device_id', '=', (string)$device->device_id)->latest()->first();
                    $current_defrost_interval_task = Task::where('name', '=', 'get_current_defrost_interval')->where('device_id', '=', (string)$device->device_id)->latest()->first();
                    //$read_p3_value_task = Task::where('name', '=', 'read_p3_value')->latest()->first();

                    if (!$probe_temps_task || $probe_temps_task->is_done == 1) {
                        Task::insert($task);
                    }
                    if (!$current_defrost_interval_task || $current_defrost_interval_task->is_done == 1) {
                        Task::insert($get_current_defrost_interval);
                    }
                    //if(!$read_p3_value_task || $read_p3_value_task->is_done == 1){
                    //    Task::create($read_p3_value);
                    //}
                }
            }

            sleep(config('app.reading_interval'));
        }
    }
}
