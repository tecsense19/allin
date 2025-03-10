<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DailyTask;

class SendDailyTaskCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'task:send-daily';

    /**
     * The console command description.
     */
    protected $description = 'Send daily task messages to users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dailyTask = DailyTask::get();

        foreach ($dailyTask as $key => $dTask) {
            $day = $dTask->task_day;
            $time = $dTask->task_time;
            $nextDateTime = date("Y-m-d H:i:s", strtotime("next $day $time"));
            echo $nextDateTime;
            die;
        }

        $this->info('Daily task messages have been dispatched.');
    }
}
