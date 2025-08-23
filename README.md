

refund 批量退款 默认transaction.csv
php main.php --refund
php main.php --refund --transactionId=ch_xxx
php main.php --product --count=3 --prices=1,2,3
refund 单个退款
php main.php --refund --transactionId=ch_xxxxxxxxxx
type |default:7days|1:15|2:30|3:60|4:60-120|5:120-180
date：|+-1 day: 20250428
php main.php --search --last4s=1234[,2345] --type=1
php main.php --search --transIds=ch_xxx[,ch_xxx]
php main.php --search --link=1 --date=20250501
php main.php --search --last4s=1234[,2345] --date=20250428
php main.php --search --emails=a@b.com --type=1
php main.php --search --transactionIds=ch_xxxxx --type=1
