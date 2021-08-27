<?php

namespace Systruss\SchedTransactions\Commands;

use Illuminate\Console\Command;

use ArkEcosystem\Crypto\Configuration\Network;
use ArkEcosystem\Crypto\Identities\Address;
use Systruss\SchedTransactions\Services\Networks\MainnetExt;
use Systruss\SchedTransactions\Services\SchedTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Console\Scheduling\Schedule;
use Systruss\SchedTransactions\Services\Delegate;
use Systruss\SchedTransactions\Services\Transactions;


class Register extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crypto:register {--filename?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register Delegate';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

	// check if shedule run command already exist in crontab
	protected function cronjob_exists($command){
		$cronjob_exists=false;
		exec('crontab -l', $crontab,$result);
		if(isset($crontab)&&is_array($crontab)){
			$crontab = array_flip($crontab);
			if(isset($crontab[$command])){
				$cronjob_exists=true;
			}
		}
		return $cronjob_exists;
	}

	// append the schedule run command to crontab
    protected function append_cronjob($command){
        $output = [];
        if(is_string($command)&&!empty($command)&&$this->cronjob_exists($command)==FALSE){
            //add job to crontab
            $output = shell_exec("crontab -l | { cat; echo '$command'; } |crontab -");
        }
        return $output;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
		//
		// user select netowrk (infi or hedge)
		//
		$quit=1;
		if ($this->argument('filename')) {
			//register delagates from file
			$filename=$this->option('--filename');
			echo "reguster delegates from file $filename \n";
		}
		while (1 == 1) {
			$network = $this->ask('select network [ (1) infi or (2) Hedge]: ');
			echo "\n";
			switch ($network) {
				case "1":
						echo "you selected infi\n";
					$network = 'infi';
					$quit=1;
						break;
				case "2":
						echo "you selected Hedge\n";
					$network = 'edge';
					$quit=1;
						break;
				case "q":
						echo "you selected to quit\n";
					$quit=1;
						break;
				default:
						echo "please select 1 for infi or 2 for Hedge or q to quit \n";
					$quit = 0;
				}

			if ($quit == 1) {
				break;
			}
		}
		
		//
		//network is select now provide passphrase
		//
		while (1 == 1) {
			$passphrase = $this->ask('please enter a passphrase : ');
			$this->info("you provided the following passphrase :  $passphrase ");
			$confirm = $this->confirm('Is it correct [y/n] or q to quit : ');
			if ($confirm) { break;}
		}

		//register delegate 
		$delegate = new Delegate();
		$success = $delegate->register($passphrase,$network);
		
		if (!$success) 
		{
			$this->info("error while registering delegate");
			return false;
		}
	}	
}