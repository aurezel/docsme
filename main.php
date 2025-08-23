<?php

require_once 'InitConfig.php';
require_once 'config.php';
require_once 'stripeQueryService.php';
require_once 'stripeRefundService.php';
require_once 'stripeProductService.php';
require_once 'stripeWebhookService.php';
require_once 'stripeInfoService.php';

// 获取 CLI 参数
$options = getopt('', [
    'refund',
	'init',
    'transactionId:',
    'amount:',
    'product',
    'param:',
    'prices:',
    'pay_path:',
    'notify_path:',
    'email:',
    'url:',
    'count:',
    'names:',
    'search',
    'last4s:',
    'emails:',
    'transIds:',
    'type:',
    'date:',
    'edate:',
    'link:',
    'arn:',
    'all',
    'info',
    'stat:',
    'currency:',
    'settings',
    'webhook',
    'domain:',
    'path:',
	'id:'
]);

//# 初始化config
//php main.php --init --param=sk_live_abc,prices=
//# 发起退款
//php main.php --refund --transactionId=ch_123 --amount=2
//
//# 批量产品创建
//php main.php --product --param=create
//
//# 查看价格列表
//php main.php --product --param=priceList
//
//# 创建 Webhook
//php main.php --webhook --param=create --domain='https://checkout.example.com' --path='v1/StripeBankNotify' --type=1
//# 删除 Webhook
//php main.php --webhook --param=delete --id= 
//# 删除 Webhook
//php main.php --webhook --param=list
//
//# 查询账户余额
//php main.php --info --currency=eur --param=balance
//
//# 搜索
//php main.php --search --last4s=1234,5678 --emails=test@example.com

// === 初始化config ===
$commands = [
    'init'    => 'handleInit',
    'refund'  => 'handleRefund',
    'product' => 'handleProduct',
    'webhook' => 'handleWebhook',
    'info'    => 'handleInfo',
    'search'  => 'handleSearch',
];

// 遍历映射执行对应处理器
foreach ($commands as $key => $handler) {
    if (isset($options[$key])) {
        if (function_exists($handler)) {
            $handler($options);
            return;
        } else {
            echo "⚠️ Error: handler '$handler' not found.\n";
            exit(1);
        }
    }
}

// 未匹配任何命令，输出提示
echo "⚠️ Error: Invalid command.\n";
echo "Available commands:\n";
echo "  --" . implode(" | --", array_keys($commands)) . "\n";
exit(1);

//
// ========== 以下为功能封装 ==========
//

function handleInit(array $options)
{
    try { 
        $config = new InitConfig('config.php');
		
		if (isset($options['prices']) && is_string($options['prices'])) {
            $options['prices'] = array_map('intval', explode(',', $options['prices']));
        }
		
		$pk = null;
        $sk = null;
        if (isset($options['param'])) {
            $params = explode(',', $options['param']);
            if (count($params) === 2) {
                list($pk, $sk) = $params;
            } else {
                // 只有一个值，当sk
                $sk = trim($params[0]);
            }
        }
		
        // 定义允许设置的字段及其映射关系
        $fields = [ 
            'currency' => 'LOCAL_CURRENCY',
            'prices'   => 'PRODUCT_PRICE',
            'pay_path'   => 'PAY_PATH',
            'notify_path'   => 'NOTIFY_PATH',
            'url'   => 'STRIPE_URL',
            'email'   => 'STRIPE_EMAIL'
        ];
		
		if ($pk !== null) {
            $fields['param_pk'] = 'STRIPE_PK';
        }
        if ($sk !== null) {
            $fields['param_sk'] = 'STRIPE_SK';
        }

        // 临时存储 param_pk 和 param_sk 的值，方便统一处理
        $paramValues = [
            'param_pk' => $pk,
            'param_sk' => $sk,
        ];
        foreach ($fields as $optionKey => $configKey) {
            if (array_key_exists($optionKey, $paramValues)) {
                if ($paramValues[$optionKey] !== null) {
                    $config->set($configKey, $paramValues[$optionKey]);
                }
            } elseif (isset($options[$optionKey])) {
                $config->set($configKey, $options[$optionKey]);
            }
        }

        // 保存配置文件
        $config->save();

        // 输出当前 STRIPE_SK 以确认修改成功
		 if ($pk !== null) {
            echo "当前 STRIPE_PK: " . $config->get('STRIPE_PK') . PHP_EOL;
        }
        if ($sk !== null) {
            echo "当前 STRIPE_SK: " . $config->get('STRIPE_SK') . PHP_EOL;
        }
		if(isset($options['currency'])){ 
			echo "当前 LOCAL_CURRENCY: " . $config->get('LOCAL_CURRENCY') . PHP_EOL; 
		}
		if(isset($options['prices'])){ 
			echo "当前 PRODUCT_PRICE: " . implode(",",$config->get('PRODUCT_PRICE')) . PHP_EOL; 
		}
    } catch (Exception $e) {
        echo "配置处理失败: " . $e->getMessage() . PHP_EOL;
    }
	return;
}

function handleRefund(array $options)
{
    $refundService = new StripeRefundService(STRIPE_SK, LOCAL_CURRENCY);
    $amount = $options['amount'] ?? null;

    if (!isset($options['transactionId'])) {
        $refundService->processRefundFromFile(TRANSACTION_FILE);
    } else {
        $transactionId = $options['transactionId'];
        $refund = $refundService->processRefundManually($transactionId, $amount);
        echo "✅ Refund processed for transaction ID: $transactionId\n";
        echo "🧾 Refund response: " . json_encode($refund, JSON_PRETTY_PRINT) . "\n";
    }
	return;
}

function handleProduct(array $options)
{
    $count = $options['count'] ?? 3;
    $prices = $options['prices'] ?? null; 
    $productNames = $options['names'] ?? getDefaultProductNames();

    $productService = new StripeProductService(STRIPE_SK, PRODUCT_PRICE, LOCAL_CURRENCY, $productNames, $count, 1);

    if (($options['param'] ?? '') === 'priceList') {
        $productService->priceList();
        return;
    }elseif(($options['param'] ?? '') === 'update'){
		 $productService->updateLocalProductPrice();
        return;
    }elseif(($options['param'] ?? '') === 'status'){
		 $productService->compare();
        return;
    }

    if (($options['param'] ?? '') === 'create') {
        $product = $productService->createProducts();
        //print_r($product);
		return;
    }
	 if (($options['param'] ?? '') === 'insert' && !empty($prices)) { 
		$pricesArray = explode(',', $prices); 
		$pricesArray = array_map('floatval', $pricesArray);
	
        $product = $productService->addOneOffPricesByProductName($productNames,$pricesArray);
        //print_r($product);
    }
}

function handleWebhook(array $options)
{
	$param = $options['param'] ?? '';
    $webhookService = new StripeWebhookService(STRIPE_SK);

    switch ($param) {
        case 'create':
            $domain = $options['domain'] ?? '';
            $path = $options['path'] ?? '';
            $type = isset($options['type']) ? (int)$options['type'] : 1;

            if (empty($domain) || empty($path)) {
                echo "⚠️ Error: Please provide both --domain and --path for create operation.\n";
                exit(1);
            }

            $result = $webhookService->createWebhook($domain, $path, $type);
            print_r($result);
            break;

        case 'delete':
            $id = $options['id'] ?? '';
            if (empty($id)) {
                echo "⚠️ Error: Please provide --id for delete operation.\n";
                exit(1);
            }

            $result = $webhookService->deleteWebhook($id);
            print_r($result);
            break;

        case 'list':
            $result = $webhookService->listWebhooks();
            print_r($result);
            break;

        default:
            echo "⚠️ Error: Invalid or missing --param. Supported values: create, delete, list\n";
            exit(1);
    }
	 
}

function handleInfo(array $options)
{
    $currency = $options['currency'] ?? 'usd';
    $param = $options['param'] ?? 'account';
    $param = in_array($param, ['account', 'balance', 'arn', 'payout','analysis','customers','stats'], true) ? $param : 'account';

    $infoService = new StripeInfoService(STRIPE_SK, $currency); 
    $infoService->getAllInfo($param);
}

function handleSearch(array $options)
{
	if (isset($options['emails']) && is_string($options['emails'])) {
            $options['emails'] = array_map('intval', explode(',', $options['emails']));
        }
    $searchParams = [
        'last4s' => anyToArray($options['last4s'] ?? ''),
        'emails' => $options['emails'],//anyToArray($options['emails'] ?? ''),
        'transactionIds' => anyToArray($options['transIds'] ?? ''),
        'type' => $options['type'] ?? null,
        'date' => $options['date'] ?? null,
        'edate' => $options['edate'] ?? null,
        'link' => $options['link'] ?? null,
        'arn' => isset($options['arn']),
        'all' => isset($options['all']),
    ];

    $queryService = new StripeQueryService(STRIPE_SK);
    $result = $queryService->smartSearch($searchParams);

    if (!empty($result)) {
        $queryService->toCsv($result);
    } else {
        echo "🔍 No search results found.\n";
    }
}

function anyToArray($input)
{
    if (is_array($input)) {
        return array_filter(array_merge(...array_map(fn($x) => explode(',', $x), $input)), fn($i) => trim($i) !== '');
    }
    return $input ? array_filter(array_map('trim', explode(',', $input))) : [];
}

function getDefaultProductNames(): array
{
    return [
        "Entire Total", "Full Total", "Overall Total", "Complete Total", "Whole Total", "Sum Total",
        "Gross Total", "Final Amount", "Complete Sum", "Grand Total", "Entire Sum", "Full Amount",
        "Overall Sum", "Whole Amount", "Final Total", "Aggregate Total", "Final Sum", "Net Total",
        "Total Amount", "Total Sum", "Final Figure", "Entire Amount", "Final Value", "Gross Amount",
        "Grand Sum", "Complete Figure", "Cumulative Total", "Complete Amount", "Whole Figure",
        "Net Amount", "Full Sum", "Absolute Total", "Total Balance", "Total Charge", "Invoice Total",
        "Final Count", "Whole Count", "Full Balance", "Complete Balance", "Total Value", "Grand Figure",
        "Final Payment", "Total Quantity", "Entire Balance", "Final Settlement", "Total Payable",
        "Sum Amount", "Final Gross", "Gross Sum", "Total Result", "Total Revenue", "Overall Charge"
    ];
}
