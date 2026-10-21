<?php
namespace App\Console\Commands;

use App\Http\Controllers\open\OpenRechargeController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 开放平台充值兜底查单（crontab 每分钟执行）：
 * 扫 30 分钟内未支付的充值单，主动向微信/支付宝查单，已支付则结算加余额。
 * 纯新增命令，不影响现有任何业务。
 */
class OpenRechargeScanCommand extends Command
{
    protected $signature = 'open:recharge-scan';
    protected $description = '开放平台充值单主动查单兜底（30分钟内待支付单）';

    public function handle()
    {
        $rows = DB::table('open_recharge')
            ->where('status', 0)
            ->where('created_time', '>=', time() - 1800)
            ->orderBy('id', 'asc')->limit(50)->get();
        if ($rows->isEmpty()) {
            $this->info('no pending recharge');
            return 0;
        }
        $ctrl = new OpenRechargeController();
        $settled = 0;
        foreach ($rows as $rc) {
            if ($ctrl->queryAndSettle($rc)) $settled++;
        }
        $this->info('scanned=' . $rows->count() . ' settled=' . $settled);
        return 0;
    }
}
