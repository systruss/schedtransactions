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
            $this->info("there is no delegate table in DB ");
            return false;
        }
        $wallets = DelegateDb::all();
        foreach($wallets as $wallet) {
            $success = $delegate->init($wallet);
            
            if (!$success) 
            {
                $this->info("Error initialising delegate with wallet $wallet->address from DB ");
                continue;
            }

            // wallet exist, check if scheduler is active
            if (!$delegate->sched_active) {
                echo "\n Scheduler is not active for wallet $wallet->address, activate scheduler using : php artisan crypto:admin enable_sched \n";
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
                    $this->info("Next Transactions for wallet $wallet->address in $next_transactions hours");
                    continue;
                }
            }
 
            //check delegate validity
            $valid = $delegate->checkDelegateValidity();

            // Check Delegate  Eligibility
            $this->info(" ----------- checking delegate elegibility"); echo ("\n");
            $success = $delegate->checkDelegateEligibility();
            if (!$success) {
                $this->info("the wallet $wallet->address ($wallet_id) is not yet eligble trying after an hour");
                continue;
            }
            $this->info("(success) delegate with wallet $wallet->address is eligible");

            // get beneficary and amount = (delegate balance - totalFee) * 20%
            $this->info(" ---------------- get benificiary info");
            $beneficary = new Beneficary();
            $success = $beneficary->initBeneficary($delegate);
            if (!$success) {
                $this->info("(error) an issue happened with the beneficary for wallet $wallet->address");
                continue; 
            }
            $requiredMinimumBalance = $beneficary->requiredMinimumBalance;

            //init voters
            $this->info(" ---------- initialising voters");
            $voters = new voters();
            $voters = $voters->initEligibleVoters($delegate,$requiredMinimumBalance);
            if (!($voters->nbEligibleVoters > 0)) {
                echo "\n there is no Eligible voters for wallet $wallet->address ($wallet->id) \n";
                continue;
            }
            $this->info("voters initialized successfully for $wallet->address ($wallet->id) \n ");
            $this->info("number of Elegible voters " . $voters->nbEligibleVoters);
    
            $transactions = new Transactions();
            $transactions = $transactions->buildTransactions($voters,$delegate,$beneficary);
            if (!$transactions->buildSucceed) {
                $this->info("(error) for wallet $wallet->address ($wallet->id)" . $transactions->errMesg);
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

            $this->info("transaction initialized successfully for wallet $wallet->address ($wallet->id)");
            $this->info("ready to run the folowing transactions ");
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
                echo "\n performing the transactions \n";
                $success = $transactions->sendTransactions();
                if (!$success) {
                    echo "\n error while sending transactions for wallet $wallet->address ($wallet->id) \n";
                    continue;
                }
                $this->info("transactions performed successefully for wallet $wallet->address ($wallet->id)");
                echo json_encode($transactions->transactions );
            } 
        }
    }
}