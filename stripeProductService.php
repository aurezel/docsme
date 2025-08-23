<?php

require 'vendor/autoload.php';

use Stripe\Stripe;
use Stripe\Product;
use Stripe\Price;

class StripeProductService
{
    private $currency;
    private $productNames;
    private $priceArray;
    private $productCount;
    private $type; 

    public function __construct($apiKey, $priceArray, $currency = 'usd', $productNames = [], $productCount = 3, $type = 1)
    {
        Stripe::setApiKey($apiKey);
        $this->currency = $currency;
        $this->priceArray = $priceArray;
        $this->productNames = $productNames ?:  ["Entire Total","Full Total","Overall Total","Complete Total","Whole Total","Sum Total","Gross Total","Final Amount","Complete Sum","Grand Total","Entire Sum","Full Amount","Overall Sum","Whole Amount","Final Total","Aggregate Total","Final Sum","Net Total","Total Amount","Total Sum","Final Figure","Entire Amount","Final Value","Gross Amount","Grand Sum","Complete Figure","Cumulative Total","Complete Amount","Whole Figure","Net Amount","Full Sum","Absolute Total","Total Balance","Total Charge","Invoice Total","Final Count","Whole Count","Full Balance","Complete Balance","Total Value","Grand Figure","Final Payment","Total Quantity","Entire Balance","Final Settlement","Total Payable","Sum Amount","Final Gross","Gross Sum","Total Result","Total Revenue","Overall Charge","Overall Amount","Whole Charge","Total Collection","Total Number","Final Collection","Grand Amount","Complete Revenue","Final Charge","Entire Value","Full Count","Total Line","Full Settlement","Final Invoice","Total Cost","Final Output","Net Sum","Complete Output","Entire Figure","Whole Sum","Final Result","Total Due","Entire Invoice","Whole Payment","Overall Figure","Total Funds","Invoice Amount","Net Figure","Total Payment","Full Revenue","Invoice Sum","Final Total Value","Accumulated Total","Final Calculation","Summed Total","Finalized Amount","Full Gross","Calculated Total","Rounded Total","Fixed Total","Grand Invoice","Full Invoice","Closing Total","Statement Total","Entire Payable","Net Charge","Collected Total","Cleared Total","Statement Amount"];
		 if (!defined('PRODUCT_CSV')) {
            define('PRODUCT_CSV', 'product.csv');
        }
        $this->productCount = $productCount;
        $this->type = $type;
    }

    /**
     * 创建指定数量的产品及对应价格
     * @return array
     */
	public function priceList()
	{
		$products = \Stripe\Product::all(['limit' => 100]);

		foreach ($products->data as $product) {
			echo "产品ID: " . $product->id . "，产品名: " . $product->name . PHP_EOL;

			// 根据产品ID获取对应的价格列表
			$prices = \Stripe\Price::all(['product' => $product->id, 'limit' => 100]);

			if (count($prices->data) === 0) {
				echo "  无价格" . PHP_EOL;
				continue;
			}

			foreach ($prices->data as $price) {
				echo "  价格ID: " . $price->id . ", 金额: " . $price->unit_amount . ", 货币: " . $price->currency . PHP_EOL;
			}
			echo "-------------------" . PHP_EOL;
		}
	}

    public function createProducts()
    {
        $names = $this->productNames;
        shuffle($names);  // 随机打乱产品名
        $chosenNames = array_slice($names, 0, $this->productCount);
		$randomInt = random_int(0,9);
        // 将价格数组按顺序随机切割成若干区间
        $priceChunks = $this->getRandomPriceChunks();

        $result = [];
        foreach ($chosenNames as $index => $productName) {
            // 获取该产品的价格数组
            $productPrices = $priceChunks[$index];

            // 创建产品
            $product = Product::create([
                'name' => $productName, 
            ]);

            // 为产品创建多个价格
            $productInfo = [
                'product_name' => $productName,
                'product_id'   => $product->id,
                'prices'        => []
            ];

            foreach ($productPrices as $priceValue) {
                // 为每个价格创建价格对象
                $price = Price::create([
                    'product' => $product->id,
                    'unit_amount' => intval(round($priceValue * 100 + $randomInt)), // 美分 价格
                    'currency' => $this->currency,
                ]);

                $productInfo['prices'][] = [
                    'amount' => number_format($priceValue, 2, '.', ''),
                    'unit_amount' => intval(round($priceValue * 100)),
                    'currency' => $this->currency,
                    'stripe_price_id' => $price->id,
                    'product_id' => $product->id,
                    'product_name' => $productName,
                    'product_statement_descriptor' => $productName,
                    'product_tax_code' => 'tax_code', // This is just a placeholder
                    'description' => 'Product description here', // Example, replace with actual description
                    'created_at' => date('Y-m-d H:i:s', $price->created),
                    'interval' => 'month', // Example, replace with actual interval if needed
                    'interval_count' => 1,
                    'usage_type' => 'licensed',
                    'aggregate_usage' => null, // Placeholder, replace if needed
                    'billing_scheme' => 'per_unit',
                    'trial_period_days' => null, // Placeholder
                    'tax_behavior' => 'exclusive', // Placeholder
                ];
            }

            $result[] = $productInfo;
        }

        // 根据type生成不同的CSV
        $this->generateCSV($result);

        return $result;
    }

    /**
     * 随机将价格数组切分成若干区间
     * @return array
     */
    private function getRandomPriceChunks()
{
    $prices = PRODUCT_PRICE;
    $totalCount = count($prices);
    
    // 如果总数不足或 chunkCount 不合理，直接返回整个数组
    if ($totalCount <= 0 || $this->productCount <= 0) {
        return [$prices];
    }
    if ($this->productCount == 1 || $totalCount <= $this->productCount) {
        return array_chunk($prices, 1); // 或者 return [$prices];
    }

    $priceChunks = [];
    $remaining = $totalCount;
    
    // 计算基准大小和允许的波动范围（±5）
    $baseSize = (int)($totalCount / $this->productCount);
    $minSize = max(1, $baseSize - 5); // 确保最小为1
    $maxSize = min($totalCount, $baseSize + 5); // 确保不超过总数
    
    for ($i = 0; $i < $this->productCount - 1; $i++) {
        // 动态计算当前可能的随机范围，确保剩余区间也能满足
        $currentMaxPossible = $remaining - ($this->productCount - $i - 1) * $minSize;
        $currentMinPossible = $remaining - ($this->productCount - $i - 1) * $maxSize;
        
        $currentMinSize = max($minSize, $currentMinPossible);
        $currentMaxSize = min($maxSize, $currentMaxPossible);
        
        // 随机选择当前区间的大小
        $chunkSize = rand($currentMinSize, $currentMaxSize);
        
        // 取出对应数量的元素
        $priceChunks[] = array_splice($prices, 0, $chunkSize);
        $remaining -= $chunkSize;
    }
    
    // 最后一个区间取剩余所有
    $priceChunks[] = $prices;
    
    return $priceChunks;
}

public function addOneOffPricesByProductName(string $productName, array $prices): void
{
    $hasMore = true;
    $startingAfter = null;
    $productId = null;

    while ($hasMore) {
        $params = ['limit' => 100];
        if ($startingAfter) {
            $params['starting_after'] = $startingAfter;
        }

        $products = Product::all($params);

        foreach ($products->data as $product) {
            if ($product->name === $productName) {
                $productId = $product->id;
                break 2;
            }
        }

        $hasMore = $products->has_more;
        if ($hasMore) {
            $startingAfter = end($products->data)->id;
        }
    }

    if ($productId === null) {
        throw new RuntimeException("未找到名称为 '{$productName}' 的产品");
    }

    foreach ($prices as $priceAmount) {
        $unitAmount = intval(round($priceAmount * 100));

        Price::create([
            'unit_amount' => $unitAmount,
            'currency' => $this->currency,
            'product' => $productId,
            
        ]);

        echo "给产品 '{$productName}' 添加一次性价格 {$priceAmount} 成功\n";
    }
}
 public function updateLocalProductPrice(): void
    {
		 
        $handle = fopen(PRODUCT_CSV, 'w');
        if (!$handle) {
            throw new RuntimeException("无法打开文件写入: {PRODUCT_CSV}");
        }

        $hasMore = true;
        $startingAfter = null;

        while ($hasMore) {
            $params = ['limit' => 100];
            if ($startingAfter) {
                $params['starting_after'] = $startingAfter;
            }

            $prices = Price::all($params);

            foreach ($prices->data as $price) {
                $priceId = $price->id;
                $unitAmount = $price->unit_amount;
                if ($unitAmount === null) {
                    continue;
                }
                $priceValue = number_format($unitAmount / 100, 2, '.', '');
                fputcsv($handle, [$priceId, $priceValue]);
            }

            $hasMore = $prices->has_more;
            if ($hasMore) {
                $startingAfter = end($prices->data)->id;
            }
        }

        fclose($handle);
}

private function readLocalPrices(): array
    {
        if (!file_exists(PRODUCT_CSV)) {
            throw new RuntimeException("本地CSV文件不存在: {PRODUCT_CSV}");
        }

        $lines = file(PRODUCT_CSV, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $count = count($lines);

        if ($count < 3) {
            throw new RuntimeException("CSV文件记录少于3条，无法完成比较");
        }

        $indexes = [0, 1, $count - 1]; // 前两条和最后一条
        $prices = [];

        foreach ($indexes as $i) {
            $data = str_getcsv($lines[$i]);
            if (count($data) < 2) {
                throw new RuntimeException("CSV行格式不正确: " . $lines[$i]);
            }
            $priceId = trim($data[0]);
            $priceVal = floatval($data[1]);
            $prices[] = [$priceId, $priceVal];
        }

        return $prices;
    }
    /**
     * 与Stripe线上价格比较本地价格
     * @return void
     */
    public function compare(): void
    {
        $localPrices = $this->readLocalPrices();

        $allMatch = true;

        foreach ($localPrices as [$priceId, $localPrice]) {
			#echo 'test:'.$priceId." | "$localPrice;
            try {
                $stripePriceObj = Price::retrieve($priceId);
                if ($stripePriceObj->unit_amount === null) {
                    echo "跳过无价格的Stripe ID: {$priceId}\n";
                    continue;
                }
                $stripePrice = $stripePriceObj->unit_amount / 100;

                if (abs($localPrice - $stripePrice) > 0.001) {
                    echo "数据待更新：ID={$priceId}，本地价格={$localPrice}，Stripe价格=$stripePrice\n";
                    $allMatch = false;
                } else {
                    echo "价格一致：ID={$priceId}，价格={$localPrice}\n";
                }
            } catch (Exception $e) {
                echo "获取Stripe价格失败，ID=$priceId，错误: " . $e->getMessage() . "\n";
                $allMatch = false;
            }
        }

        if ($allMatch) {
            echo "价格数据最新，全部一致。\n";
        }
    
}
    /**
     * 根据type生成不同的CSV文件
     * type=1 生成一个CSV，只含价格和价格ID
     * type=2 生成两个CSV文件，一个含价格和价格ID，另一个含产品信息和价格
     */
    private function generateCSV($data)
    {
        $csvData = [];
		#$csvData[] = 'price_id,price';
		foreach ($data as $row) {
			foreach ($row['prices'] as $price) {
				$csvData[] = "{$price['stripe_price_id']},{$price['amount']}";
			}
		}
		
		$this->saveCSV('product.csv', $csvData);
		echo 'product.csv create success!';
		if ($this->type == 2) {
		  
            // 产品详细信息和价格 CSV
            $csvDataDetails = [];
            $csvDataDetails[] = 'Price ID,Product ID,Product Name,Product Statement Descriptor,Product Tax Code,Description,Created (UTC),Amount,Currency,Interval,Interval Count,Usage Type,Aggregate Usage,Billing Scheme,Trial Period Days,Tax Behavior';
            foreach ($data as $row) {
                foreach ($row['prices'] as $price) {
                    $csvDataDetails[] = "{$price['stripe_price_id']},{$price['product_id']},{$price['product_name']},{$price['product_statement_descriptor']},{$price['product_tax_code']},{$price['description']},{$price['created_at']},{$price['amount']},{$price['currency']},{$price['interval']},{$price['interval_count']},{$price['usage_type']},{$price['aggregate_usage']},{$price['billing_scheme']},{$price['trial_period_days']},{$price['tax_behavior']}";
                }
            }
            $this->saveCSV('product_prices.csv', $csvDataDetails);
			echo 'product_prices.csv create success!';
        }
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

//// ========== 用法示例 ==========
//
//$apiKey = 'sk_live_xxx'; // 替换为你的实际Stripe密钥
//$priceArray = range(5, 12); // 提供的价格数组，如 [5,6,7,8,9,10,11,12]
//$productNames = [
//    "Entire Total", "Full Total", "Overall Total", "Complete Total", "Whole Total",
//    "Sum Total", "Gross Total", "Final Amount", "Complete Sum", "Grand Total"
//];
//$productCount = 2; // 生成2个产品
//$type = 2; // type=1 生成一个CSV文件，type=2 生成两个CSV文件
//
//$generator = new StripeProductService($apiKey, $priceArray, 'usd', $productNames, $productCount, $type);
//$products = $generator->createProducts();
//print_r($products); // 输出产品信息和价格
