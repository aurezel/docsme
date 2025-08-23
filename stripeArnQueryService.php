<?php

require 'vendor/autoload.php';

use Stripe\Stripe;
use Stripe\Charge;
use Stripe\Payout;

class StripeArnQueryService
{
    public function __construct($apiKey)
    {
        Stripe::setApiKey($apiKey);
    }

    /**
     * 综合查询ARN等信息
     * @param array $params
     * @return array
     */
    public function searchWithArn(array $params = []): array
    {
        $results = [];

        $emails   = array_map('strtolower', $params['emails'] ?? []);
        $txnIds   = $params['transaction_ids'] ?? [];
        $type     = $params['type'] ?? 0;
        [$startTime, $endTime] = $this->getDateRangeByType($type);

        // 1. 交易号查
        if (!empty($txnIds)) {
            foreach ($txnIds as $txnId) {
                try {
                    $charge = Charge::retrieve($txnId);
                    if ($this->chargeMatch($charge, $emails, $startTime, $endTime)) {
                        $results[] = $this->formatChargeArn($charge);
                    }
                } catch (\Exception $e) {}
            }
            return $results;
        }

        // 2. 邮箱查（全表匹配）
        $emailSet = array_flip($emails);
        $chargeParams = [
            'limit'   => 100,
            'created' => ['gte' => $startTime, 'lte' => $endTime]
        ];
        foreach (Charge::all($chargeParams)->autoPagingIterator() as $charge) {
            $chargeEmail = strtolower($charge->billing_details->email ?? $charge->receipt_email ?? '');
            if (empty($emails) || isset($emailSet[$chargeEmail])) {
                $results[] = $this->formatChargeArn($charge);
            }
        }

        return $results;
    }

    /**
     * 查询类型映射
     */
    private function getDateRangeByType($type): array
    {
        $now = strtotime('today') + 86399;
        switch ($type) {
            case 1: return [$now - 14 * 86400, $now]; // 近15天
            case 2: return [$now - 29 * 86400, $now]; // 近30天
            case 3: return [$now - 59 * 86400, $now]; // 近60天
            case 4: return [$now - 119 * 86400, $now - 60 * 86400]; // 60~120天
            case 5: return [$now - 179 * 86400, $now - 120 * 86400];//120~180天
            default: return [$now - 6 * 86400, $now]; // 近7天
        }
    }

    /**
     * 是否匹配邮箱和时间
     */
    private function chargeMatch($charge, $emails, $startTime, $endTime)
    {
        if ($startTime && $charge->created < $startTime) return false;
        if ($endTime && $charge->created > $endTime) return false;
        if (!empty($emails)) {
            $email = strtolower($charge->billing_details->email ?? $charge->receipt_email ?? '');
            if (!in_array($email, $emails)) return false;
        }
        return true;
    }

    /**
     * 获取Charge信息带ARN等
     */
    private function formatChargeArn($charge)
    {
        // 获取ARN
        $arn = 'N/A';
        try {
            // 一般情况下，destination_payment字段会关联Payout或相关对象
            if (!empty($charge->destination_payment)) {
                $payout = \Stripe\Payout::retrieve($charge->destination_payment);
                $arn = $payout->arrival_date ?? 'N/A'; // Stripe未开放直接ARN字段（如有可替换）
            }
            // 如果你有插件或自定义metadata记录了arn，可： $charge->metadata['arn'] ?? 'N/A'
        } catch (\Exception $e) {}

        return [
            'transaction_id' => $charge->id,
            'arn'            => $arn,
            'descriptor'     => $charge->description ?? 'N/A',
            'card_brand'     => $charge->payment_method_details->card->brand ?? 'N/A',
            'last4'          => $charge->payment_method_details->card->last4 ?? 'N/A',
            'created_at'     => date('Y-m-d H:i:s', $charge->created)
        ];
    }

    /**
     * 导出CSV
     */
    public function toCsv(array $data)
    {
        $lines = [];
        $lines[] = 'transaction_id,arn,descriptor,card_brand,last4,created_at';
        foreach ($data as $row) {
            $lines[] = implode(',', array_map(function($v) {
                return '"' . str_replace('"', '""', $v) . '"';
            }, $row));
        }
        return implode("\n", $lines);
    }
}

// ========== 示例调用 ==========

//$stripeApiKey = 'sk_live_xxx';
//$stripe = new StripeQueryService($stripeApiKey);
//
//// type=1查15天内；或指定邮箱；或指定交易号
//$params = [
//    'emails'         => ['abc@xx.com'],
//    'transaction_ids'=> ['ch_1Pxxxxxx'],
//    'type'           => 1,
//];
//
//$data = $stripe->searchWithArn($params);
//echo $stripe->toCsv($data);

