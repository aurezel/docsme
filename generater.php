#!/usr/bin/env php
<?php
require_once 'InitConfig.php';  // 请调整路径
class PathConfigurator {
    private $baseName;
    private $suffix;
    private $envFile = '../checkout/.env';
    private $htaccessFile = '../.htaccess';
    
    public function __construct($baseName, $suffix) {
        $this->baseName = $baseName;
        $this->suffix = $suffix;
    }
    
    public function run() {
        try {
            $this->validateInputs();
            $this->updateEnvFile();
            $this->updateHtaccess();
			$this->updateConfigPhp();
            $this->displaySuccess();
        } catch (Exception $e) {
            $this->displayError($e->getMessage());
            exit(1);
        }
    }
    
    private function validateInputs() {
        if (empty($this->baseName)) {
            throw new Exception("Base name cannot be empty");
        }
        
        if (empty($this->suffix)) {
            throw new Exception("Suffix cannot be empty");
        }
        
        if (!file_exists($this->envFile)) {
            throw new Exception(".env file not found at: {$this->envFile}");
        }
        
        if (!file_exists($this->htaccessFile)) {
            throw new Exception(".htaccess file not found at: {$this->htaccessFile}");
        }
    }
    
    private function updateEnvFile() {
        $searchLines = [
            'checkout_success_path = "/neckilla/success"',
			'checkout_cancel_path = "/neckilla/cancel"'
        ];
        
        $replaceLines = [
            "checkout_success_path = \"/{$this->baseName}pay/success{$this->suffix}\"",
            "checkout_cancel_path = \"/{$this->baseName}pay/cancel{$this->suffix}\""
        ];
        
        $envContent = file_get_contents($this->envFile);
        $newEnvContent = str_replace($searchLines, $replaceLines, $envContent);
        
        if (file_put_contents($this->envFile, $newEnvContent) === false) {
            throw new Exception("Failed to write to .env file");
        }
    }
    
    private function updateHtaccess() {
        $htaccessContent = file_get_contents($this->htaccessFile);
        
        $newRules = <<<EOT
RewriteRule ^{$this->baseName}pay/pay{$this->suffix}\$ checkout/checkout.php [QSA,PT,L]
RewriteRule ^{$this->baseName}pay/notify{$this->suffix}\$ /checkout/pay/stckWebhook [QSA,PT,L]
RewriteRule ^{$this->baseName}pay/success{$this->suffix}\$ /checkout/pay/stckSuccess [QSA,PT,L]
RewriteRule ^{$this->baseName}pay/cancel{$this->suffix}\$ /checkout/pay/stckCancel [QSA,PT,L]
RewriteRule ^{$this->baseName}pay/(.*)\$ checkout/\$1 [QSA,PT,L]
EOT;
        
        if (strpos($htaccessContent, $newRules) !== false) {
            return; // Rules already exist
        }
        
        $marker = "RewriteBase /\n";
        $insertPosition = strpos($htaccessContent, $marker);
        
        if ($insertPosition === false) {
            throw new Exception("Could not find 'RewriteBase /' in .htaccess");
        }
        
        $insertPosition += strlen($marker);
        $newHtaccessContent = substr_replace(
            $htaccessContent,
            $newRules . "\n",
            $insertPosition,
            0
        );
        
        if (file_put_contents($this->htaccessFile, $newHtaccessContent) === false) {
            throw new Exception("Failed to update .htaccess file");
        }
    }
     private function updateConfigPhp() {
        $configFile = 'config.php';  // 你的配置文件路径，调整成实际路径
        if (!file_exists($configFile)) {
            throw new Exception("配置文件不存在: $configFile");
        }

        $initConfig = new InitConfig($configFile);

        $payPath = "/{$this->baseName}pay/pay{$this->suffix}";
        $notifyPath = "/{$this->baseName}pay/notify{$this->suffix}";

        $initConfig->set("PAY_PATH", $payPath);
        $initConfig->set("NOTIFY_PATH", $notifyPath);

        $initConfig->save();
    }
    private function displaySuccess() {
        echo "\n✅ Configuration updated successfully!\n\n";
        echo "Endpoints configured:\n";
        echo "/{$this->baseName}pay/pay{$this->suffix}\n";
        echo "/{$this->baseName}pay/notify{$this->suffix}\n";
    }
    
    private function displayError($message) {
        echo "\n❌ Error: $message\n";
        echo "Usage: php generater.php <base_name> <payment_suffix>\n";
        echo "Example: php generater.php opalify tffy\n\n";
    }
}

// Main execution
if ($argc < 3) {
    echo "Usage: php generater.php <base_name> <payment_suffix>\n";
    echo "Example: php generater.php opalify tffy\n";
    exit(1);
}

$configurator = new PathConfigurator($argv[1], $argv[2]);
$configurator->run();