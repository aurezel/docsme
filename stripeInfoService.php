<?php

require 'vendor/autoload.php';

use Stripe\Stripe;
use Stripe\Account;
use Stripe\Charge;
use Stripe\Balance;

class StripeInfoService
{
	private string $currency;
    public function __construct(string $secretKey, string $currency='usd')
    {
        Stripe::setApiKey($secretKey);
		$this->currency = strtolower($currency);
    }

    public function getAccountStatus(): array
    {
        $account = Account::retrieve();
		
		$balance = \Stripe\Balance::retrieve();
		
		// 处理余额数据
		$available = 0;    // 可用余额
		$pending = 0;      // 待到账余额
		
		foreach ($balance->available as $fund) {
			$available += $fund->amount;
		}
		
		foreach ($balance->pending as $fund) {
			$pending += $fund->amount;
		}
		
		// 总余额是可用余额 + 待到账余额
		$total = $available + $pending; 
		$info = [
            'id' => $account->id,
            'email' => $account->email,
			'url' => $account->business_profile->url ?? 'N/A',
			'country' => $account->country ?? 'N/A',
			'default_currency' => $account->default_currency ?? 'N/A',
			'timezone' => $account->settings->dashboard->timezone ?? 'N/A', 
            'charges_enabled' => $account->charges_enabled ? "true":"false",
            'payouts_enabled' => $account->payouts_enabled ? "true":"false",
            'total' => number_format($total / 100, 2),
            'formatted_available' => number_format($available / 100, 2),
            'formatted_pending' => number_format($pending / 100, 2),
            'details_submitted' => $account->details_submitted,
            'descriptor' => $account->settings['payments']['statement_descriptor'] ?? 'N/A', 
        ];
        if($timezone = $account->settings->dashboard->timezone){
            date_default_timezone_set($timezone);
        }

        $firstCharges = \Stripe\Charge::all([
            'limit' => 1,
            'ending_before' => null,
            'starting_after' => null,
            'created' => ['gte' => 0], // 从最早开始
            'expand' => ['data.balance_transaction'],
        ]);

        if (!empty($firstCharges->data)) {
            $firstCharge = end($firstCharges->data); // 最早的交易
            $info['first_charge'] = date('Y-m-d H:i:s', $firstCharge->created);
        }
        $lastCharges = \Stripe\Charge::all([
            'limit' => 1,
            'expand' => ['data.balance_transaction'], // 如果要更多细节
        ]);

        if (!empty($lastCharges->data)) {
            $latestCharge = $lastCharges->data[0];
            $info['latest_charge'] = date('Y-m-d H:i:s', $latestCharge->created);
        }

		try {
			$config = new InitConfig('config.php');

			// set 方法里面判断了数组，转换成 PHP 数组语法写入 
			$config->set('STRIPE_ID', $account->id);
			$config->set('STRIPE_EMAIL', $account->email);
			$config->set('STRIPE_URL',  $account->business_profile->url ?? '');
			$config->set('STRIPE_TIMEZONE', $account->settings->dashboard->timezone ?? '');
			$config->set('STRIPE_COUNTRY', $account->country ?? '');
			  
			$config->save();

			echo "Stripe 信息写入成功\n";
		} catch (Exception $e) {
			echo "错误: " . $e->getMessage() . "\n";
		}
		$payoutSchedule = $account->settings->payouts->schedule ?? null;

		if ($payoutSchedule) {
			$info['delay_days'] = $payoutSchedule->delay_days ?? 'N/A';
			$info['interval'] = $payoutSchedule->interval ?? 'N/A'; 
		} else {
			echo "No payout schedule info found." . PHP_EOL;
		}
        return $info;
    }
	public function getStatusLine(): string
	{
		$email = '';
		$url = '';
		$OrderRandom = $this->generateRandomOrderString();
		$pay = '';
		$notify = '';

		if (defined('STRIPE_EMAIL')) {
			$email = STRIPE_EMAIL;
		}
		
		if (defined('STRIPE_URL')) {
			$url = STRIPE_URL;
			// 去掉 http(s):// 和末尾斜杠
			$url = preg_replace('#^https?://#', '', $url);
			$url = rtrim($url, '/'); 
		} 
		  
		if (defined('PAY_PATH')) {
			$pay = PAY_PATH;
			if ($pay !== '' && $pay[0] !== '/') {
				$pay = '/' . $pay;
			}
		}

		if (defined('NOTIFY_PATH')) {
			$notify = NOTIFY_PATH;
			if ($notify !== '' && $notify[0] !== '/') {
				$notify = '/' . $notify;
			}
		}

		$formatData = [
			'Stripe V5',
			$email,
			defined('STRIPE_PK') ? STRIPE_PK : '',
			defined('STRIPE_SK') ? STRIPE_SK : '',
			$OrderRandom,
			$url,
			$pay,
			'3000000',
			'1000',
			'10000',
			'10000',
			'150',
			'暂停',
			'是',
			'3',
		];

		return implode("\t", $formatData)."\n\n\n".$notify."\n\n";
	}
	
	public function generateRandomOrderString(int $minLength = 7, int $maxLength = 14): string
	{
		$types = ['int', 'char', 'letter'];
		$type = $types[random_int(0, count($types) - 1)];
		$length = random_int($minLength, $maxLength);
		return "Order #random_{$type}{$length}";
	}
    public function getChargeStats(): array
    {
        $totalCharges = 0;
        $successfulCharges = 0;
        $totalRefundAmount = 0;
        $lastTransactionTime = 0;

        $charges = Charge::all(['limit' => 100]);

        foreach ($charges->autoPagingIterator() as $charge) {
            $totalCharges++;

            if ($charge->paid && !$charge->refunded) {
                $successfulCharges++;
            }

            if ($charge->refunded) {
                $totalRefundAmount += $charge->amount_refunded;
            }

            if ($charge->created > $lastTransactionTime) {
                $lastTransactionTime = $charge->created;
            }
        }

        return [
            'total_charges' => $totalCharges,
            'successful_charges' => $successfulCharges,
            'refund_amount' => $totalRefundAmount / 100,
			'currency' => strtoupper($this->currency),
            'last_transaction_time' => $lastTransactionTime > 0 ? date('Y-m-d H:i:s', $lastTransactionTime) : null,
        ];
    }
	
	
	public function getDetailedChargeAnalysis(): array
	{
		$charges = \Stripe\Charge::all(['limit' => 100]);
		$allCharges = [];

		$latestSuccessTime = 0;

		// 临时汇总变量
		$countryCounts = [];
		$totalSuccess = 0;
		$disputesInWindow = 0;
		$totalInWindow = 0;

		$cardTypeCounts = [
			'credit' => 0,
			'debit' => 0,
			'prepaid' => 0,
			'unknown' => 0
		];

		// Step 1: 找出最新成功交易时间
		foreach ($charges->autoPagingIterator() as $charge) {
			if ($charge->paid && !$charge->refunded && $charge->status === 'succeeded') {
				if ($charge->created > $latestSuccessTime) {
					$latestSuccessTime = $charge->created;
				}
				$allCharges[] = $charge;
			}
		}

		$cutoff = $latestSuccessTime - 30 * 24 * 60 * 60;

		// Step 2: 遍历成功交易，统计国家、卡类型、拒付等
		foreach ($allCharges as $charge) {
			$country = $charge->payment_method_details->card->country ?? 'Unknown';
			$funding = $charge->payment_method_details->card->funding ?? 'unknown';

			$totalSuccess++;

			// 国家统计
			if (!isset($countryCounts[$country])) {
				$countryCounts[$country] = 0;
			}
			$countryCounts[$country]++;

			// 卡类型统计
			if (isset($cardTypeCounts[$funding])) {
				$cardTypeCounts[$funding]++;
			} else {
				$cardTypeCounts['unknown']++;
			}

			// 拒付率窗口计算
			if ($charge->created >= $cutoff) {
				$totalInWindow++;
				if ($charge->disputed) {
					$disputesInWindow++;
				}
			}
		}

		arsort($countryCounts); // 按数量排序

		return [
			'top_countries' => array_slice($countryCounts, 0, 5, true),
			'total_successful_charges' => $totalSuccess,
			'dispute_rate_last_30_days' => $totalInWindow > 0 ? round(($disputesInWindow / $totalInWindow) * 100, 2) . '%' : '0%',
			'card_type_distribution' => $cardTypeCounts,
			'analysis_cutoff_date' => date('Y-m-d H:i:s', $cutoff),
			'latest_successful_charge_time' => date('Y-m-d H:i:s', $latestSuccessTime),
		];
	}

	function getVisaRefundsDetailed() {
		$twoMonthsAgo = strtotime('-2 months');
		$params = [
			'limit' => 100,
			'created' => ['gte' => $twoMonthsAgo],
		];

		$results = [];
		$hasMore = true;
		$startingAfter = null;

		while ($hasMore) {
			if ($startingAfter) {
				$params['starting_after'] = $startingAfter;
			}

			$refundList = \Stripe\Refund::all($params);

			foreach ($refundList->data as $refund) {
				$charge = \Stripe\Charge::retrieve($refund->charge);

				$cardDetails = $charge->payment_method_details->card ?? null;
				$cardBrand = $cardDetails ? strtolower($cardDetails->brand) : '';

				if ($cardBrand !== 'visa') {
					continue; // 非Visa跳过
				}

				// ARN通常无直接字段，示例从metadata尝试取，需根据实际情况调整
				$descriptor = $charge->calculated_statement_descriptor ?? '-';
				$arn = $refund->destination_details->card->reference ?? 'ARN未提供';
				$results[] = [
					'charge_id' => $charge->id,
					'email' => $charge->billing_details->email ?? '',
					'statement_descriptor' => $descriptor,
					'arn' => $arn,
					'charge_amount' => $charge->amount / 100,
					'charge_currency' => strtoupper($charge->currency),
					'charge_time' => date('Y-m-d H:i:s', $charge->created),
					'refund_amount' => $refund->amount / 100,
					'refund_time' => date('Y-m-d H:i:s', $refund->created),
					'card_brand' => ucfirst($cardBrand),
				];
			}

			$hasMore = $refundList->has_more;
			if ($hasMore) {
				$startingAfter = end($refundList->data)->id;
			}
		}

		return $results;
	}
	
	public function getSchedule():array
	{
		$payouts = \Stripe\Payout::all([
			'limit' => 10,
			'status' => 'pending' // 也可查 paid, failed
		]);

		$account = \Stripe\Account::retrieve();
		$payout_schedule = $account->settings->payouts->schedule;
		$interval = $payout_schedule->interval; // daily, weekly, monthly
		$delay_days = $account->settings->payouts->schedule->delay_days; // 延迟天数（如3表示T+3）
		echo '账户出款周期:'. $delay_days."\n";
		echo '账户出款频率:'. $interval."\n";		
		foreach ($payouts as $payout) {
			echo "出款ID: " . $payout->id . "\n";
			echo "金额: $" . number_format($payout->amount / 100, 2) . "\n";
			echo "状态: " . $payout->status . "\n";
			echo "预计到账日期: " . date('Y-m-d', $payout->arrival_date) . "\n"; // 关键字段！
			echo "出款方式: " . $payout->method . "\n"; // standard（普通）或 instant（即时）
			echo "---\n";
		}
		return [];
	}
	
	function exportSuccessfulChargesToCSV($csvFile = TRANSACTION_FILE, $limit = 100) {
		$latestCharge = Charge::all([
			'limit' => 1,
			'created' => ['lte' => time()],
			'status' => 'succeeded'
		])->first();

		if (!$latestCharge) {
			echo "❌ 未找到成功交易。\n";
			return;
		}

		$latestTime = $latestCharge->created;
		$startTime = strtotime('-30 days', $latestTime);

		echo "📅 查询区间：".gmdate('Y-m-d H:i:s', $startTime)." 到 ".gmdate('Y-m-d H:i:s', $latestTime)."\n";

		// 第二步：获取符合时间段的所有成功交易（全部）
		$params = [
			'created' => [
				'gte' => $startTime,
				'lte' => $latestTime,
			],
			'limit' => 100,
		];

		$output = fopen(TRANSACTION_FILE, 'w');

		// 写入表头
		fputcsv($output, [
			'Charge ID',
			'Created date (UTC)',
			'Customer Email',
			'Customer Phone',
			'Shipping Name',
			'Shipping Address Line1',
			'Shipping Address City',
			'Shipping Address State',
			'Shipping Address Country',
			'Shipping Address Postal Code'
		]);

		$count = 0;

		foreach (Charge::all($params)->autoPagingIterator() as $charge) {
			if ($charge->status !== 'succeeded') {
				continue;
			}

			$row = [
				$charge->id,
				gmdate('Y-m-d H:i:s', $charge->created),
				$charge->billing_details->email ?? '',
				$charge->billing_details->phone ?? '',
				$charge->shipping->name ?? '',
				$charge->shipping->address->line1 ?? '',
				$charge->shipping->address->city ?? '',
				$charge->shipping->address->state ?? '',
				$charge->shipping->address->country ?? '',
				$charge->shipping->address->postal_code ?? '',
			];

			fputcsv($output, $row);
			$count++;
		}

		fclose($output);
		echo "✅ 共导出成功交易 $count 条，文件：{TRANSACTION_FILE}\n";
	}

    public function getBalance(): array
    {
        $balance = Balance::retrieve();
        $available = 0;
        $pending = 0;

        foreach ($balance->available as $item) {
            if ($item->currency === $currency) {
                $available += $item->amount;
            }
        }

        foreach ($balance->pending as $item) {
            if ($item->currency === $this->currency) {
                $pending += $item->amount;
            }
        }

        return [
            'available' => $available / 100,
            'pending' => $pending / 100,
            'total' => ($available + $pending) / 100,
			'currency' => strtoupper($this->currency),
        ];
    }

    public function getAllInfo($type=1): array
    {
		if($type=='account'){
			echo "=== 账号状态 ===\n";
			print_r($this->getAccountStatus());
		}
		
		if($type=='analysis'){
			echo "=== 账号状态 ===\n";
			print_r($this->getDetailedChargeAnalysis());
		}
		 
		if($type=='balance'){ 
			echo "\n=== 余额信息 ===\n";
			print_r($this->getBalance());
			echo "\n=== 交易统计 ===\n";
			print_r($data['charge_stats']);
		}
		if($type=='payout'){ 
			echo "\n=== 出款计划 ===\n";
			print_r($this->getSchedule()); 
		}
		if($type=='stats'){ 
			echo "\n=== 输出账号导入的格式 ===\n";
			echo $this->getStatusLine();
		}
		if($type=='customers'){ 
			echo "\n=== 最近一个月的客户记录 ===\n";
			print_r($this->exportSuccessfulChargesToCSV()); 
		}
		if($type=='arn'){ 
			echo "\n=== ARN与描述符信息 ===\n";
			$data = $this->getVisaRefundsDetailed();
			if($data){
				$headers = array_keys($data[0]);
				echo implode("\t", $headers) . PHP_EOL;

				// 输出每一行数据
				foreach ($data as $row) {
					echo implode(',', $row) . PHP_EOL;
				}
			}
			
		} 
		return [];
        return [
				'account_status' => $this->getAccountStatus(),
				'charge_stats' => $this->getChargeStats(),
				'balance' => $this->getBalance(),
			];
    }
}
