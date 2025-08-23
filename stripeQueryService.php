<?php

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Charge;
use Stripe\BalanceTransaction;

class StripeQueryService
{
    public function __construct($apiKey)
    {
        Stripe::setApiKey($apiKey);
    }

    /**
     * 综合查询：邮箱、卡后四位、交易号、时间
     * 支持组合任意查询
     * @param array $params 结构示例：
     *  [
     *    'emails' => ['a@a.com', ...], // 可选
     *    'last4s' => ['4242','8888'],   // 可选，多个后四位
     *    'transaction_ids' => ['ch_xxx','ch_yyy'], // 可选
     *    'type'  => 1 // 时间区间类型，见getDateRangeByType
     *  ]
     */
    public function smartSearch(array $params = []): array
    {
        $results = [];

        // 处理参数
        $emails   = array_map('strtolower', $params['emails'] ?? []);
        $last4s   = $params['last4s'] ?? [];
        $txnIds   = $params['transactionIds'] ?? [];
        $type     = $params['type'] ?? 0;
        $sdate = $params['date'] ?? 0;
        $edate = $params['edate'] ?? 0;
        $link = $params['link'] ?? 0;
        $arn = $params['arn'] ?? false;
        $all = $params['all'] ?? false;
        [$startTime, $endTime] = $this->getDateRangeByType($type,$sdate,$edate);

        // 1. 精准交易号查，优先
        if (!empty($txnIds)) {
            foreach ($txnIds as $txnId) {
                try {
                    $charge = Charge::retrieve($txnId);
                    $results[] = $this->formatCharge($charge);
                } catch (\Exception $e) {
                    // 交易号查不到/不存在时跳过
                }
            }
            return $results;
        }

        // 2. 邮箱分组处理
        $emailToCustomerId = [];
        $noCustomerEmails = [];
        if(!empty($emails)){
            foreach ($emails as $email) {
                $customers = \Stripe\Customer::all(['email' => $email]);
                if (!empty($customers->data)) {
                    $emailToCustomerId[$email] = $customers->data[0]->id;
                } else {
                    $noCustomerEmails[] = $email;
                }
            }
        }


        // 3. 通过客户ID查交易
        if(!empty($emailToCustomerId)){
            foreach ($emailToCustomerId as $email => $customerId) {
                $chargeParams = [
                    'customer' => $customerId,
                    'limit'    => 100,
                    'created'  => ['gte' => $startTime, 'lte' => $endTime]
                ];
                foreach (Charge::all($chargeParams)->autoPagingIterator() as $charge) {
                    if ($this->chargeMatch($charge, [], $last4s)) {
                        $results[] = $this->formatCharge($charge);
                    }
                }
            }
        }

        // 3. 通过客户ID查交易
        if(!empty($link)){
            $chargeParams = [
                'limit'    => 100,
                'created'  => ['gte' => $startTime, 'lte' => $endTime]
            ];
            foreach (Charge::all($chargeParams)->autoPagingIterator() as $charge) {
                $paymentMethod = $charge->payment_method_details;
                if (!isset($paymentMethod->card)) {
                    $results[] = $this->formatCharge($charge);
                }
            }
            return $results;
        }
		if($all){
            $chargeParams = [
                'limit'    => 100,
                'created'  => ['gte' => $startTime, 'lte' => $endTime]
            ];
            foreach (Charge::all($chargeParams)->autoPagingIterator() as $charge) {
                $paymentMethod = $charge->payment_method_details;
                $results[] = $this->formatCharge($charge);
            }
            return $results;
        }

		// 4. 通过客户ID查交易
        if($arn){
            $chargeParams = [
                'limit'    => 100,
                'created'  => ['gte' => $startTime, 'lte' => $endTime]
            ];
            foreach (Charge::all($chargeParams)->autoPagingIterator() as $charge) {
				if (!empty($charge->refunds->data)) {
					foreach ($charge->refunds->data as $refund) {
						if ($refund->status === 'succeeded') {
							$balanceTransaction = \Stripe\BalanceTransaction::retrieve($refund->balance_transaction);
                    
							// ARN 存储在 source_transfer 中
							if (isset($balanceTransaction->source_transfer->id)) {
								$arn = $balanceTransaction->source_transfer->id;
								$statementDescriptor = $charge->statement_descriptor;
								$results[] = $this->formatCharge($charge,$arn."|".$statementDescriptor);
								echo "✅ ARN: $arn - {$statementDescriptor}\n";
							} else {
								echo "❌ 找不到 ARN\n";
							}
						}
					}
				}
				 
                
            }
			//var_dump($results);
            return $results;
        }

        // 4. 没有客户ID的邮箱，遍历全表查邮箱
        if (!empty($noCustomerEmails) || !empty($last4s)) {
            $emailSet = array_flip($noCustomerEmails);
            $last4Set = array_flip($last4s);
            $chargeParams = [
                'limit'   => 100,
                'created' => ['gte' => $startTime, 'lte' => $endTime]
            ];
            foreach (Charge::all($chargeParams)->autoPagingIterator() as $charge) {
                $email = strtolower($charge->billing_details->email ?? $charge->receipt_email ?? '');
                $matchLast4 = isset($last4Set[$charge->payment_method_details->card->last4 ?? '']);
                $matchEmail = isset($emailSet[$email]);
                if (($matchEmail || $matchLast4)) {
                    $results[] = $this->formatCharge($charge);
                }
            }
        }

        // 5. 仅查卡后四位（未传邮箱/交易号）
//        if (empty($emails) && !empty($last4s)) {
//            $last4Set = array_flip($last4s);
//            $chargeParams = [
//                'limit'   => 100,
//                'created' => ['gte' => $startTime, 'lte' => $endTime]
//            ];
//            foreach (Charge::all($chargeParams)->autoPagingIterator() as $charge) {
//                $matchLast4 = isset($last4Set[$charge->payment_method_details->card->last4 ?? '']);
//                if ($matchLast4) {
//                    $results[] = $this->formatCharge($charge);
//                }
//            }
//        }

        return $results;
    }

    /**
     * 判断交易是否满足邮箱/后四位/时间范围
     */
    private function chargeMatch($charge, $emails = [], $last4s = [], $startTime = null, $endTime = null)
    {
        // 交易时间
        if ($startTime && $charge->created < $startTime) return false;
        if ($endTime && $charge->created > $endTime) return false;
        // 邮箱
        if (!empty($emails)) {
            $email = strtolower($charge->billing_details->email ?? $charge->receipt_email ?? '');
            if (!in_array($email, $emails)) return false;
        }
        // 卡后四位
        if (!empty($last4s)) {
            $last4 = $charge->payment_method_details->card->last4 ?? '';
            if (!in_array($last4, $last4s)) return false;
        }
        return true;
    }

    /**
     * type=1: 近15天，2: 近30天，3: 近60天，4: 60~120天前，5: 120~180天前，默认7天
     */
    private function getDateRangeByType($type,$startdate=0,$enddate=0): array
    {	
		if ($startdate && $enddate) {
			return [strtotime($startdate), strtotime($enddate) + 86400];
		}

		// 如果传递了datetime（假设是一个日期字符串）
		if ($startdate) {
			$datetime = strtotime($startdate); // Convert the datetime string to a timestamp
			$previousDay = strtotime("-1 day", $datetime);
			$nextDay = strtotime("+1 day", $datetime) + 86400;
			return [$previousDay, $nextDay];
		}
        $now = strtotime('today') + 86399; // 今天23:59:59
        switch ($type) {
            case 1: return [$now - 14 * 86400, $now];              // 近15天
            case 2: return [$now - 29 * 86400, $now];              // 近30天
            case 3: return [$now - 59 * 86400, $now];              // 近60天
            case 4: return [$now - 119 * 86400, $now - 60 * 86400];// 60~120天前
            case 5: return [$now - 179 * 86400, $now - 120 * 86400];//120~180天前
            default: return [$now - 6 * 86400, $now];              // 近7天
        }
    }

    private function formatCharge($charge,$arnStr = "")
    {
        $refundStatus = 'none';
        $refundAmount = 0;
        if ($charge->amount_refunded > 0) {
            $refundAmount = $charge->amount_refunded / 100;
            $refundStatus = ($refundAmount == ($charge->amount / 100)) ? 'fully_refunded' : 'partially_refunded';
        }
		
		$card_brand = $charge->payment_method_details->card->brand ?? null;
		/**$arnStr = "";
		if (!empty($charge->destination_payment)) {
			$destinationCharge = \Stripe\Charge::retrieve($charge->destination_payment);
			$arnStr = $destinationCharge->transfer_data->arn ?? null;
		}

		if (!empty($charge->balance_transaction) && empty($arnStr)) {
			$txn = \Stripe\BalanceTransaction::retrieve($charge->balance_transaction);
			$arnStr = $txn->source->transfer_data->arn ?? null;
		}**/
		
        return [
            $charge->billing_details->email ?? $charge->receipt_email ?? '',
            $charge->id,
            number_format($charge->amount / 100, 2, '.', ''),
            strtoupper($charge->currency),
            $charge->status,
            $charge->payment_intent ?? '',
            $refundStatus,
            number_format($refundAmount, 2, '.', ''),
            date('Y-m-d H:i:s', $charge->created),
            isset($charge->payment_method_details) ? $charge->payment_method_details->type : "",
            isset($charge->payment_method_details) ? $charge->payment_method_details->card->last4 ?? '':'',
            isset($charge->presentment_details) ? $charge->presentment_details->presentment_amount : "",
            isset($charge->presentment_details) ? $charge->presentment_details->presentment_currency : "",
			$card_brand,
        ];
    }

    public function toCsv(array $data)
    {
        // 定义 CSV 文件的列标题
        $lines = [];
        $lines[] = ['email', 'transaction_id', 'amount', 'currency', 'status', 'paymentIntent', 'refundStatus', 'refundAmount', 'created_at','paymentMethod','paymentLast4','presentmentAmount','presentmentCurrency','cardbrand'];

        // 遍历数据并将每行数据添加到 $lines 数组
        foreach ($data as $row) {
            $lines[] = $row;
        }

        // 输出 CSV 到屏幕
        echo "Generated CSV:\n";
        $this->saveCSV(TRANSACTION_FILE, $lines);
    }

    private function saveCSV($filename, $data)
    {
        // 打开文件进行写入
        $file = fopen($filename, 'w');
        foreach ($data as $row) {
            // 使用 fputcsv 直接将数组写入文件，自动处理引号和分隔符
            fputcsv($file, $row);
        }
        fclose($file);
        echo "CSV file '$filename' generated successfully!\n";
    }
}

//// ====== 示例调用 ======
//
//$stripeApiKey = 'sk_live_xxx';
//$stripe = new StripeQueryService($stripeApiKey);
//
//$params = [
//    'emails'         => ['test1@xx.com', 'test2@xx.com'],
//    'last4s'         => ['4242', '8888'],
//    'transaction_ids'=> [], // 或 ['ch_xxx']，精准查
//    'type'           => 3,  // 60天
//];
//
//$data = $stripe->smartSearch($params);
//echo $stripe->toCsv($data);

