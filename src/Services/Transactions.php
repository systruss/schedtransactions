<?php

namespace Systruss\SchedTransactions\Services;

use Illuminate\Console\Command;

use ArkEcosystem\Crypto\Configuration\Network;
use ArkEcosystem\Crypto\Identities\Address;
use Systruss\SchedTransactions\Services\Networks\MainnetExt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Console\Scheduling\Schedule;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use ArkEcosystem\Crypto\Transactions\Builder\TransferBuilder;
use ArkEcosystem\Crypto\Transactions\Builder\MultiPaymentBuilder;
use Systruss\SchedTransactions\Services\Voters;
use Systruss\SchedTransactions\Services\Delegate;
use Systruss\SchedTransactions\Services\Beneficary;
use Systruss\SchedTransactions\Services\Server;



const api_fee_url = "https://raw.githubusercontent.com/InfinitySoftwareLTD/common/main/fees/fee.json";

class Transactions
{
	public $fee;
	public $nonce = 1;
	public $balance;
	public $rate;
	public $wallet_valid;
	public $buildSucceed;
	public $delegateAddress;
	public $address;
	public $amountToBeDistributed;
	public $delegatePassphrase;
	public $network;
	public $voters;
	public $peer_ip;
	public $peer_port;
	public $peers;
	public $transactions;
	public $api_delegates_url;
	public $publicKey;
	public $errMesg;
	public $transaction_result;

	public function getFee($network,$totalVoters, $multiPaymentLimit)
	{	
		$fee = '';
		$totalFee = 0;
		// get fees from api
		$client = new Client();
		$res = $client->get(api_fee_url);
		if ($data = $res->getBody()->getContents()) 
		{
			$data = json_decode($data);
			switch ($network) {
				case "edge" : 
					$fee = $data->data->edge->multiPayment->min;
					break;
				case "infi" : 
					$fee = $data->data->infi->multiPayment->min;
					break;
				default:
					echo "\n network provided is not infi or edge \n";
			}
			// total fee = rounded(number of voters + 1  / $multiPaymentLimit) * minimum fee according network
			if ($totalVoters > 0) 
			{
				$totalVoters = $totalVoters +1; //add beneficary
				$FeeQuotient =  floor($totalVoters/$multiPaymentLimit)+1;
				$totalFee = $FeeQuotient * $fee;
			} else 
			{
				echo "\n total voters is 0 !!!\n";
			}
		}	
		return $totalFee;
	}

    public function initScheduler() 
    {
            //schedule task
            echo "\n schdeduling crypto:perform_transactions task hourly \n";
            $logFile = storage_path() . "/logs/schedule_job.log";
			echo "\n $logFile \n";
            $schedule = app(Schedule::class);
            $schedule->command('crypto:perform_transactions')->hourly()->appendOutputTo($logFile);
			return 1;
    }



	public function buildTransactions(Voters $voters, Delegate $delegate, Beneficary $beneficary)
	{	
		
		echo "\n ------------------- Building transactions  ----------- \n";
        $valid = $delegate->checkDelegateEligibility();
		if ($valid)
		{
			// delegate rank is between 1 and 25 and balance as required

            // get fee
            $totalFee = $this->getFee($delegate->network, $voters->nbEligibleVoters, $beneficary->multiPaymentLimit);
			echo "\n totalFee   = $totalFee \n";
			if ($totalFee > $delegate->balance) {
				$this->buildSucceed = false;
				$this->errMesg = "buildTransactions : warning : Fee greater than delegate available balance";
				return $this;
			}
			$this->fee = $totalFee;

			// balanceForDistribution = delegateCurrentBalance - totalfee- maintainMInimumBalance
			// beneficiaryAMount = balancefordistribution * beneficaryRate / 100
			// balanceForVoter = balanceForDistribution - beneficaryAmount
			// get Beneficary address and amount
			$beneficaryAddress = $beneficary->address;
			$multiPaymentLimit = $beneficary->multiPaymentLimit;
			$this->rate = $beneficary->rate;
									
            // calculate voters amount
			// to be distributed = balance - (total fee + beneficary)
			echo "\n Delegate balance = $delegate->balance \n";
			echo "\n Beneficiary Mintain Mimimum Balance = $beneficary->maintainMinimumBalance \n";
			echo "\n beneficary rate = $this->rate \n";
			if ($delegate->balance > $beneficary->maintainMinimumBalance ) {
				if ($delegate->balance > $totalFee ) {
					$remaining_balance = $delegate->balance - ($totalFee + $beneficary->maintainMinimumBalance);
					// Beneficiary Amount
					$beneficaryAmount = ($remaining_balance * $beneficary->rate)/100; 
					echo "\n beneficiaryAmount = $beneficaryAmount \n";
					// Balance to be distributed to voters
					$amountToBeDistributed = $remaining_balance - $beneficaryAmount;
					$this->amountToBeDistributed = $amountToBeDistributed;
					echo "\n amount to be distributed : $amountToBeDistributed";
				} else {
					echo "\n delegate balance less than fee, trying at next iteration in 1 hour \n";
					$this->buildSucceed = false;
					return $this;
				}
			} else {
				echo "\n (error) maintain minimum balance greater than delegate balance \n";
				$this->buildSucceed = false;
				return $this;
			}

			$votersList = $voters->calculatePortion($amountToBeDistributed);
			$this->balance = $delegate->balance;
			
			Network::set(new MainnetExt());
			$this->buildSucceed = false;
			$nonce = $delegate->nonce;
			// Generate transaction
			if ($votersList->eligibleVoters)
			{
				$indexVoter = 2;
				$i = 1;
				$generated = MultiPaymentBuilder::new();
				foreach ($votersList->eligibleVoters as $voter) {
					$amount = ($voter['portion'] * $amountToBeDistributed) / 100;
					$generated = $generated->add($voter['address'], (int)$amount);
					$indexVoter++;
					if ($indexVoter > $multiPaymentLimit) {
						$generated = $generated->withFee($totalFee);
						$generated = $generated->withNonce($nonce);
						$generated = $generated->sign($delegate->passphrase);
						$this->transactions[$i] = [ 'transactions' => [$generated->transaction->data] ];
						$i++;
						$indexVoter = 1;
						$generated = MultiPaymentBuilder::new();
					}
				}
				if ($indexVoter > 1) {
					$generated = $generated->add($beneficaryAddress,floor($beneficaryAmount));
					$generated = $generated->withFee($totalFee);
					$generated = $generated->withNonce($nonce);
					$generated = $generated->sign($delegate->passphrase);
					$this->transactions[$i] = [ 'transactions' => [$generated->transaction->data] ];
				}
                $this->peer_ip = $delegate->peer_ip;
                $this->peer_port = $delegate->peer_port;
				$this->buildSucceed = true;
			} else {
				// there is no voters
				$this->buildSucceed = false;	
			}
		} else {
			//invalid sender
			$this->buildSucceed = false;
		}
		return $this;
	}

	public function sendTransactions()
	{

		if (!count($this->transactions)) {
			echo "\n there is no transaction \n";
			return false;
		}

		foreach ($this->transactions as $transaction) 
		{
			$response = [];
			$client = new Client();
			$api_url = "http://$this->peer_ip:$this->peer_port/api".'/transactions';
			echo "\n api_url   : $api_url \n";
		
			try {
				$req = $client->post($api_url,['json'=> $transaction]);
				$data = $req->getBody()->getContents();
				if ($data)
				{
					$data = json_decode($data);
					//treating data errors
					if (isset($data->errors))
					{
						foreach ($data->errors as $error) {
							$response['http_data'][] = is_object($error) ? $error : $error[0];
						}
						echo "\n(Failed) Return Funds to Main Wallet";
						echo "\n(Failed) to connect to the node server.";
						$this->transaction_result = json_encode($response);
						return false;
					}

				}
			} catch (RequestException $e) {
				echo "\n (Failed) Return Funds to Main Wallet. Unable to connect to the node. \n";
				$response = $e->getResponse();
				$responseBodyAsString = $response->getBody()->getContents();
				//echo "\n json_encode($e->getMessage() . $e->getLine() . $e->getFile()) \n";
				return false;
			}
		} 

		//echo " \n (success) Return Funds to Main Wallet";
		echo " \n Transactions sent Successfully \n";
		return true;
	}	
}
