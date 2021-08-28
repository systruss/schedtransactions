<?php

namespace Systruss\SchedTransactions\Commands;

use Illuminate\Console\Command;

use ArkEcosystem\Crypto\Configuration\Network;
use ArkEcosystem\Crypto\Identities\Address;
use Illuminate\Database\QueryException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use ArkEcosystem\Crypto\Transactions\Builder\TransferBuilder;
use ArkEcosystem\Crypto\Transactions\Builder\MultiPaymentBuilder;
use Systruss\SchedTransactions\Services\Networks\MainnetExt;
use Systruss\SchedTransactions\Services\Voters;
use Systruss\SchedTransactions\Services\Delegate;
use Systruss\SchedTransactions\Services\Beneficary;
use Systruss\SchedTransactions\Services\Transactions;
use Systruss\SchedTransactions\Services\SchedTransaction;
use Illuminate\Support\Facades\Schema;
use Systruss\SchedTransactions\Models\DelegateDb;
use Systruss\SchedTransactions\Models\CryptoLog;

const SCHED_NB_HOURS = 6;


class PerformTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crypto:perform_transactions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'perform transactions every 24 hours';

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
        $disabled = 1;
        
		$this->info("---------------------------------------------");
        echo date('d-m-y h:i:s'); 
        $this->info(" : starting a new transaction");

		//Initialise Delegate
        $delegate = new Delegate();

        //get all wallets
        if (!Schema::hasTable('delegate_dbs')) {
            echo date('d-m-y h:i:s');
            $this->info(" : there is no delegate table in DB ");
            return false;
        }
        $wallets = DelegateDb::all();
        foreach($wallets as $wallet) {
            echo date('d-m-y h:i:s');
            $this->info(" : ($wallet->address) ---------------------------------------");
            $success = $delegate->init($wallet);
            
            if (!$success) 
            {
                echo date('d-m-y h:i:s'); 
                $this->info(" : ($wallet->address) Error initialising delegate from DB ($wallet->id)");
                continue;
            }

            // wallet exist, check if scheduler is active
            if (!$delegate->sched_active) {
                echo date('d-m-y h:i:s'); 
                echo " : ($wallet->address) Scheduler is not active, activate scheduler using : php artisan crypto:admin enable_sched ($wallet->id)\n";
                continue;
            }

            // scheduler active , check counter last transactions
            $sched_freq = $delegate->sched_freq;
            $latest_transactions = CryptoLog::orderBy('id','DESC')->first();
            if ($latest_transactions) {
                if (($latest_transactions->succeed) && ($latest_transactions->hourCount < $sched_freq)) {
                    $next_transactions =  $sched_freq - $latest_transactions->hourCount;
                    $latest_transactions->hourCount = $latest_transactions->hourCount + 1;
                    $latest_transactions->save();
                    echo date('d-m-y h:i:s');
                    $this->info(" : ($wallet->address) Next Transactions in $next_transactions hours ($wallet->id)");
                    continue;
                }
            }
 
            //check delegate validity
            $valid = $delegate->checkDelegateValidity();

            // Check Delegate  Eligibility
            echo date('d-m-y h:i:s');
            $this->info(" : ($wallet->address) checking delegate elegibility ($wallet->id)"); echo ("\n");
            $success = $delegate->checkDelegateEligibility();
            if (!$success) {
                echo date('d-m-y h:i:s');
                $this->info(" : ($wallet->address) not yet eligble trying after an hour ($wallet->id) ");
                continue;
            }
            echo date('d-m-y h:i:s');
            $this->info(" : ($wallet->address) wallet is eligible ($wallet->id)");

            // get beneficary and amount = (delegate balance - totalFee) * 20%
            echo date('d-m-y h:i:s');
            $this->info(" : ($wallet->address) get benificiary info ($wallet->id)");
            $beneficary = new Beneficary();
            $success = $beneficary->initBeneficary($delegate);
            if (!$success) {
                echo date('d-m-y h:i:s');
                $this->info(" : ($wallet->address) an issue happened with the beneficary ($wallet->id)");
                continue; 
            }
            $requiredMinimumBalance = $beneficary->requiredMinimumBalance;

            //init voters
            echo date('d-m-y h:i:s');
            $this->info(" : ($wallet->address) initialising voters");
            $voters = new voters();
            $voters = $voters->initEligibleVoters($delegate,$requiredMinimumBalance);
            if (!($voters->nbEligibleVoters > 0)) {
                echo date('d-m-y h:i:s');
                echo " : ($wallet->address) there is no Eligible voters ($wallet->id) \n";
                continue;
            }
            echo date('d-m-y h:i:s');
            $this->info(" : ($wallet->address) voters initialized successfully ($wallet->id) \n ");
            echo date('d-m-y h:i:s');
            $this->info(" : ($wallet->address) number of Elegible voters $voters->nbEligibleVoters \n");
    
            $transactions = new Transactions();
            $transactions = $transactions->buildTransactions($voters,$delegate,$beneficary);
            if (!$transactions->buildSucceed) {
                echo date('d-m-y h:i:s');
                $this->info(" : ($wallet->address) transaction build issue ($wallet->id) error $transactions->errMesg \n");
                continue;
            }
        
            //log transaction
            $cryptoLog = new CryptoLog();
            $cryptoLog->rate = $beneficary->rate;
            $cryptoLog->beneficary_address = $beneficary->address;
            $cryptoLog->delegate_address = $delegate->address;
            $cryptoLog->delegate_balance = $delegate->balance;
            $cryptoLog->fee = $transactions->fee;
            $cryptoLog->amount = $transactions->amountToBeDistributed;
            $cryptoLog->totalVoters = $voters->nbEligibleVoters;
            $cryptoLog->transactions = 0;
            $cryptoLog->hourCount = 0;
            $cryptoLog->succeed = false;
            echo date('d-m-y h:i:s');
            $this->info(" : ($wallet->address) transaction initialized successfully ($wallet->id) \n");
            echo date('d-m-y h:i:s');
            $this->info(" : ($wallet->address) ready to run the folowing transactions \n");
            // var_dump($transactions->transactions);
            echo json_encode($transactions->transactions, JSON_PRETTY_PRINT);
            echo "\n";

            //for simulation transactions status
            $succeed = 1;
            if ($succeed) {
                $cryptoLog->succeed = true;
                $cryptoLog->save();
            }

            if (!$disabled) {
                //perform transactions
                echo date('d-m-y h:i:s');
                echo " : ($wallet->address) performing the transactions \n";
                $success = $transactions->sendTransactions();
                if (!$success) {
                    echo date('d-m-y h:i:s');
                    echo " : ($wallet->address) error while sending transactions ($wallet->id) \n";
                    continue;
                }
                echo date('d-m-y h:i:s');
                $this->info(" : ($wallet->address) transactions performed successefully  ($wallet->id) \n");
                echo json_encode($transactions->transactions );
            } 
        }
    }
}