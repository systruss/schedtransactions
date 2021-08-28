<?php

namespace Systruss\SchedTransactions\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Systruss\SchedTransactions\Models\DelegateDb;
use Systruss\SchedTransactions\Models\CryptoLog;

class Admin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crypto:admin {action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'crypto administration';

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
     * @return int
     */
    public function handle()
    {
        $action = $this->argument('action');
        
        //check is task scheduling active
        switch ($action) {
            case "delete_delegate":
                 //provide wallet_id
                 $wallet_id = $this->ask('provide wallet id : ');
                 echo "\n";
                if (Schema::hasTable('delegate_dbs')) {
                    $wallet = DelegateDb::where('wallet_id',$wallet_id);
                    if ($wallet) {
                        $wallet->delete;
                        $this->info("wallet $wallet_id deleted"); 
                    } else {
                        $this->info("that wallet $wallet_id does not eist");                        
                    }
                } else {
                    $this->info("no delegate table exist");
                }                
                break;
            case "delete_table":
                if (Schema::hasTable('delegate_dbs')) {
                    Schema::drop('delegate_dbs');
                    DB::table('migrations')->where('migration',"2021_05_25_080651_create_delegate_dbs_table.php")->delete();
                    $this->info("delegate table deleted"); 
                } else {
                    $this->info("nothing to delete");
                }     
                break;
            case "show_logs":
                if (Schema::hasTable('crypto_logs')) {
                    $cryptoLogs = CryptoLog::all();
                    if ($cryptoLogs) {
                        foreach ($cryptoLogs as $log) {
                            $this->info("--------------------------");
                            echo "Transactions performed at : $log->created_at";
                            echo "\n delegate address : $log->delegate_address";
                            echo "\n beneficary address : $log->beneficary_address";
                            echo "\n transactions id : $log->transactions";
                            echo "\n Amount to be distributed : $log->amount";
                            echo "\n total voters : $log->totalVoters";
                            echo "\n delegate balance : $log->delegate_balance";
                            echo "\n fee : $log->fee";
                            echo "\n rate : $log->rate";
                            echo "\n hourCount : $log->hourCount";
                            echo "\n succeed : $log->succeed \n";
                        }
                    } else {
                        $this->info("no logs in DB");                        
                    }
                } else {
                    $this->info("no log entries in table exist");
                }                
                break;
            case "clear_logs":
                if (Schema::hasTable('crypto_logs')) {
                    $cryptoLogs = CryptoLog::truncate();
                } else {
                    $this->info("log table not prsent, did you forget to run migrate ?");
                }                
                break;
            case "show_delegate":
                $num_record = 0;
                $current_page = 1;
                $page = 1;
                if (Schema::hasTable('delegate_dbs')) {
                    if (DelegateDb::count() > 0) {
                        foreach (DelegateDb::all() as $wallet) {
                            echo "\n----------------------------------------- \n";
                            echo " wallet_id = $wallet->id \n";
                            echo " address = $wallet->address \n";
                            echo " passphrase = $wallet->passphrase \n";
                            echo " network = $wallet->network \n";
                            echo " sched_active = $wallet->sched_active \n";
                            echo " sched_freq = $wallet->sched_freq \n";
                            $num_record++;
                            $page = $num_record % 5;
                            if ( $page > $current_page) {
                                $current_page = $page;
                                if (!$this->confirm('Do you wish to continue ?', true)) {
                                    break;
                                }
                            }
                        }

                    } else {
                        $this->info("no wallet in DB");                        
                    }
                } else {
                    $this->info("no delegate table exist");
                }                
                break;
            case "enable_sched":
                //provide wallet_id
                $wallet_id = $this->ask('provide wallet id : ');
			    echo "\n";
                if (Schema::hasTable('delegate_dbs')) {
                    $wallet = DelegateDb::where('wallet_id',$wallet_id)->get();
                    if ($wallet) {
                        $wallet->sched_active = true;
                        $wallet->save();
                    } else {
                        $this->info("the wallet_id $wallet_id does not exist");                        
                    }
                } else {
                    $this->info("no delegate table exist, scheduler cannot be activated");
                }                
                break;
            case "disable_sched":
                //provide wallet_id
                $wallet_id = $this->ask('provide wallet id : ');
			    echo "\n";
                if (Schema::hasTable('delegate_dbs')) {
                    $wallet = DelegateDb::where('wallet_id',$wallet_id)->get();
                    if ($wallet) {
                        $wallet->sched_active = false;
                        $w->save();
                    } else {
                        $this->info("the wallet_id $wallet_id does not exist");                        
                    }
                } else {
                    $this->info("no delegate table exist, scheduler cannot be disabled");
                }                
                break;
            case "change_sched":
                $wallet_id = $this->ask('provide wallet id : ');
			    echo "\n";
                if (Schema::hasTable('delegate_dbs')) {
                    $wallet = DelegateDb::where('wallet_id',$wallet_id)->get();
                    if ($wallet) {
                        // get current schedule frequency 
                        $current_sched_freq = $wallet->sched_freq;
                        $this->info("current schedule frequency : " . $current_sched_freq);
                        $quit=1;
                        while (1 == 1) {
                            $new_sched_freq = (int)$this->ask('change schedule frequency value between 1 and 24 hour : ');
                            if (  ($new_sched_freq >= 1 ) && ($new_sched_freq <= 24)) {
                                break;
                            }
                            $this->info("please provide a value between 1 and 24");
                        }
                        $wallet->sched_freq = $new_sched_freq;
                        $wallet->save();
                    } else {
                        $this->info("the wallet id provided does not exist");
                    }
                } else {
                    $this->info("no delegate table exist, scheduler frequency cannot be set");
                }                
                break;
            default:
                $this->info('usage : php artisan crypto:admin delete_delegate/delete_table/show_delegate/enable_sched/disable_sched/show_logs/clear_logs/change_sched ');
                $quit = 0;
            }
        return 0;
    }
}
