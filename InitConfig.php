<?php

class InitConfig
{
    private $filePath;
    private $content;
    private $backupPath;

    public function __construct($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception("配置文件不存在：$filePath");
        }

        $this->filePath = $filePath;
        $this->backupPath = $filePath . '.bak';

        $this->content = file_get_contents($filePath);
        if ($this->content === false) {
            throw new Exception("无法读取配置文件内容。");
        }
    }

    public function set($constantName, $value)
	{
		if (is_array($value)) {
			$replacement = 'define("' . $constantName . '", [' . implode(',', array_map('intval', $value)) . ']);';
		} else {
			$replacement = 'define("' . $constantName . '", "' . addslashes($value) . '");';
		}

		$pattern = '/define\("' . preg_quote($constantName, '/') . '",\s*(\[.*?\]|".*?")\);/s';

		if (preg_match($pattern, $this->content)) {
			// 直接替换，不要加额外换行，保持一行一个定义
			$this->content = preg_replace($pattern, $replacement, $this->content);
		} else {
			// 不存在该常量，追加到内容末尾，且确保换行
			$this->content .= PHP_EOL . $replacement . PHP_EOL;
		}
	}

    public function get($constantName)
    {
        $pattern = '/define\("' . preg_quote($constantName, '/') . '",\s*(\[.*?\]|".*?")\);/s';
        if (preg_match($pattern, $this->content, $matches)) {
            $value = $matches[1];
            if ($value[0] === '[') {
                // 数组值
                eval('$result = ' . $value . ';');
                return $result;
            } else {
                return stripcslashes(trim($value, '"'));
            }
        }
        return null;
    }

    public function save()
    {
        // 自动备份
        copy($this->filePath, $this->backupPath);

        $result = file_put_contents($this->filePath, $this->content);
        if ($result === false) {
            throw new Exception("无法保存配置文件。");
        }
    }

    public function getBackupPath()
    {
        return $this->backupPath;
    }
}
