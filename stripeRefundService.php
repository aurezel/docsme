<?php

use Stripe\Stripe;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Payment;

class StripeRefundService
{
    private $currency;

    public function __construct($apiKey, $currency = 'usd')
    {
        Stripe::setApiKey($apiKey);
        $this->currency = $currency;
    }

    /**
     * 批量处理退款
     * 从 `transaction.csv` 文件中读取交易ID，执行退款操作
     * @param string $csvFile 交易记录的CSV文件路径
     * @param float|null $partialAmount 如果是部分退款，传入退款金额
     */
    public function processRefundFromFile($csvFile, $partialAmount = null)
    {
        // 从CSV文件读取交易ID
        $transactionIds = $this->getTransactionIdsFromCSV($csvFile);

        // 执行退款
        foreach ($transactionIds as $transactionId) {
            $this->processRefund($transactionId, $partialAmount);
        }
    }

    /**
     * 从CSV文件中提取交易ID
     * @param string $csvFile CSV文件路径
     * @return array 交易ID数组
     */
    private function getTransactionIdsFromCSV($csvFile)
    {
        $transactionIds = [];

        if (($handle = fopen($csvFile, "r")) !== false) {
            $isHeader = true;
            while (($data = fgetcsv($handle)) !== false) {
                // 跳过头部行
                if ($isHeader) {
                    $isHeader = false;
                    continue;
                }

                // 假设第一列是交易ID
                if($data[4] == 'succeeded'){
					$transactionIds[] = $data[1];
				}
            }
            fclose($handle);
        }

        return $transactionIds;
    }

    /**
     * 执行退款
     * @param string $transactionId 交易ID（可以是 Charge ID 或 PaymentIntent ID）
     * @param float|null $partialAmount 部分退款金额（可选）
     */
    private function processRefund($transactionId, $partialAmount = null)
    {
        try {
            // 检查交易ID前缀，判断是Charge还是PaymentIntent
            if (strpos($transactionId, 'ch_') === 0) {
                // Charge ID，直接退款
                $this->refundCharge($transactionId, $partialAmount);
            } elseif (strpos($transactionId, 'pi_') === 0) {
                // PaymentIntent ID，先获取Charge ID，然后退款
                $this->refundPaymentIntent($transactionId, $partialAmount);
            }elseif (strpos($transactionId, 'py_') === 0) {
				 $payment = Payment::retrieve($transaction_id);
				if (!empty($payment->payment_intent)) {
					$paymentIntentId = $payment->payment_intent;
					$this->refundPaymentIntent($paymentIntentId, $partialAmount);
				}
                 
            } else {
                echo "Invalid transaction ID: $transactionId. Must start with 'ch_' or 'py_'.\n";
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            echo "Error processing refund for transaction ID: $transactionId. " . $e->getMessage() . "\n";
        }
    }

    /**
     * 通过 Charge ID 执行退款
     * @param string $chargeId Charge ID
     * @param float|null $partialAmount 部分退款金额（可选）
     */
    private function refundCharge($chargeId, $partialAmount = null)
    {
        // 获取Charge对象
        $charge = Charge::retrieve($chargeId);

        // 全额退款或部分退款
        $amountToRefund = $partialAmount ? min($charge->amount, $partialAmount * 100) : $charge->amount;

        // 创建退款
        $refund = Refund::create([
            'charge' => $chargeId,
            'amount' => $amountToRefund,  // 退款金额（以分为单位）
        ]);

        echo "Refund successful for Charge ID: $chargeId. Amount refunded: " . ($amountToRefund / 100) . " {$refund->currency}\n";
    }

    /**
     * 通过 PaymentIntent ID 执行退款
     * @param string $paymentIntentId PaymentIntent ID
     * @param float|null $partialAmount 部分退款金额（可选）
     */
    private function refundPaymentIntent($paymentIntentId, $partialAmount = null)
    { 
        $amountToRefund = $partialAmount ? min($charge->amount, $partialAmount * 100) : $charge->amount;

        // 创建退款
        $refund = Refund::create([
            'payment_intent' => $paymentIntentId,
            'amount' => $amountToRefund,  // 退款金额（以分为单位）
        ]);

        echo "Refund successful for PaymentIntent ID: $paymentIntentId. Amount refunded: " . ($amountToRefund / 100) . " USD\n";
    }

    /**
     * 手动输入交易ID进行退款
     * @param string $transactionId 交易ID
     * @param float|null $partialAmount 部分退款金额（可选）
     */
    public function processRefundManually($transactionId, $partialAmount = null)
    {
        $this->processRefund($transactionId, $partialAmount);
    }

    /**
     * 生成 `transaction.csv` 文件
     * 以便进行退款操作
     * @param array $transactions 交易记录数据
     */
    public function generateTransactionCSV($transactions)
    {
        $csvData = [];
        $csvData[] = 'transaction_id,amount,currency,status,created_at';

        foreach ($transactions as $transaction) {
            $csvData[] = "{$transaction['transaction_id']},{$transaction['amount']},{$transaction['currency']},{$transaction['status']},{$transaction['created_at']}";
        }

        $this->saveCSV(TRANSACTION_FILE, $csvData);
    }

    /**
     * 保存CSV文件到本地
     * @param string $filename 文件名
     * @param array $data 数据
     */
    private function saveCSV($filename, $data)
    {
        $file = fopen($filename, 'w');
        foreach ($data as $row) {
            fputcsv($file, explode(',', $row));
        }
        fclose($file);
        echo "CSV file '$filename' generated successfully!\n";
    }
}

// ========== 用法示例 ==========

//$apiKey = 'sk_live_xxx'; // 替换为你的实际Stripe密钥
//$processor = new StripeRefundProcessor($apiKey, 'usd');
//
//// 示例：从CSV文件中批量处理退款
//$csvFile = 'transaction.csv'; // 假设你已经生成了包含交易ID的文件
//$processor->processRefundFromFile($csvFile, 50); // 50表示部分退款金额（可选），如果为null则为全额退款
//
//// 示例：手动输入交易ID进行全额或部分退款
//$transactionId = 'ch_xxxxxxxxxxxxx'; // 输入你要退款的交易ID
//$partialAmount = 30; // 30表示部分退款金额
//$processor->processRefundManually($transactionId, $partialAmount);
