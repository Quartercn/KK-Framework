<?php
/**
 * KK Forum
 * A simple bulletin board system
 * Author: kookxiang <r18@ikk.me>
 */
namespace Core;

use Helper\PHPLock;

class Template {
	/**
	 * Load a template from data folder, compile it if it is outdated or not exists
	 * @param $templateName
	 * @return string
	 * @throws Error
	 */
	public static function load($templateName){
		$templateFileOrigin = self::getPath($templateName);
		if(!file_exists($templateFileOrigin))
			throw new Error("Template {$templateName} not exists!", 101);
		$templateFile = DATA_PATH."Template/{$templateName}.php";
		if(file_exists($templateFile))
			if(filemtime($templateFile) > filemtime($templateFileOrigin))
				return $templateFile;
		self::compile($templateName);
		return $templateFile;
	}

	/**
	 * Get template file path
	 * @param string $templateName       Template file name
	 * @param string $customTemplateName Find file in specified template folder
	 * @return string Absolute path of the template file
	 */
	public static function getPath($templateName, $customTemplateName = ""){
		if(file_exists(FORUM_PATH."Template/{$templateName}.htm")){
			return FORUM_PATH."Template/{$templateName}.htm";
		}else{
			return "";
		}
	}

	private static function compile($templateName){
		$headers = '';
		$fp = @fopen(self::getPath($templateName), 'rb');
		if(!$fp) return;
		$sourceCode = '';
		while(!feof($fp))
			$sourceCode .= fread($fp, 8192);

		$lock = new PHPLock($sourceCode);
		$lock->acquire();

		// variable with braces:
		$sourceCode = preg_replace('/\{\$([A-Za-z0-9_\[\]\->]+)\}/', '<?php echo \$\\1; ?>', $sourceCode);
		$sourceCode = preg_replace('/\{([A-Z][A-Z0-9_\[\]]*)\}/', '<?php echo \\1; ?>', $sourceCode);
		$lock->acquire();

		// PHP code:
		$sourceCode = preg_replace('/<php>(.+?)<\/php>/is', '<?php \\1; ?>', $sourceCode);
		$lock->acquire();

		// import:
		$sourceCode = preg_replace('/\<import template="([A-z0-9_\-\/]+)"[\/ ]*\>/i', '<?php include \\Core\\Template::load(\'\\1\'); ?>', $sourceCode);
		$lock->acquire();

		// loop:
		$sourceCode = preg_replace_callback('/\<loop(.*?)\>/is', array('\\Core\\Template', 'parseLoop'), $sourceCode);
		$sourceCode = preg_replace('/\<\/loop\>/i', '<?php } ?>', $sourceCode);
		$lock->acquire();

		// if:
		$sourceCode = preg_replace('/\<if (?:condition=)?"(.+?)"[\/ ]*\>/i', '<?php if(\\1) { ?>', $sourceCode);
		$sourceCode = preg_replace('/\<elseif (?:condition=)?"(.+?)"[\/ ]*\>/i', '<?php } elseif(\\1) { ?>', $sourceCode);
		$sourceCode = preg_replace('/\<else[\/ ]*\>/i', '<?php } else { ?>', $sourceCode);
		$sourceCode = preg_replace('/\<\/if\>/i', '<?php } ?>', $sourceCode);
		$lock->acquire();

		// header:
		preg_match_all('/\<meta header="(.+?)" content="(.+?)"[ \/]*\>/i', $sourceCode, $matches);
		foreach($matches[0] as $offset => $string){
			$headers .= "header('{$matches[1][$offset]}: {$matches[2][$offset]}');".PHP_EOL;
			$sourceCode = str_replace($string, '', $sourceCode);
		}
		$lock->acquire();

		// variable without braces
		$sourceCode = preg_replace('/\$([a-z][A-Za-z0-9_]+)/', '<?php echo \$\\1; ?>', $sourceCode);
		// unlock PHP code
		$lock->release();

		// rewrite link
		if(!defined('USE_REWRITE') || !USE_REWRITE){
			$sourceCode = preg_replace_callback('/href="([A-Z0-9_\\.\\-\\/%\\?=&]*?)"/is', array('\\Core\\Template', 'parseUrlRewrite'), $sourceCode);
		}

		// clear space and tab
		$sourceCode = preg_replace('/^[ \t]*(.+)[ \t]*$/m', '\\1', $sourceCode);

		$output = '<?php'.PHP_EOL;
		$output .= 'if(!defined(\'FORUM_PATH\'))';
		$output .= ' exit(\'This file could not be access directly.\');'.PHP_EOL;
		if($headers) $output .= $headers;
		$output .= '?>'.PHP_EOL;
		$output .= $sourceCode;
		$output = preg_replace('/\s*\?\>\s*\<\?php\s*/is', PHP_EOL, $output);

		self::createDir(dirname(DATA_PATH."Template/{$templateName}.php"));
		if(!file_exists(DATA_PATH."Template/{$templateName}.php")) @touch(DATA_PATH."Template/{$templateName}.php");
		if(!is_writable(DATA_PATH."Template/{$templateName}.php")){
			throw new Error('Cannot write template file: '.DATA_PATH."Template/{$templateName}.php", 8);
		}
		file_put_contents(DATA_PATH."Template/{$templateName}.php", $output);
	}

	private static function createDir($dir, $permission = 0777){
		if(is_dir($dir)) return;
		self::createDir(dirname($dir), $permission);
		@mkdir($dir, $permission);
	}

	public static function parseLoop($match){
		$variable = self::preg_get($match[1], '/variable="([^"]+)"/i');
		if(!$variable) $variable = self::preg_get($match[1], '/^\s*"([^"]+)"/i');
		if(!$variable) throw new Error('Cannot convert loop label: '.htmlspecialchars($match[0]), 102);
		$query = self::preg_get($match[1], '/query="([^"]+)"/i');
		if($query) return '<?php while ('.$variable.' = '.($query).'->getRow()) { ?>';
		$key = self::preg_get($match[1], '/key="([^"]+)"/i');
		$value = self::preg_get($match[1], '/value="([^"]+)"/i');
		return '<?php foreach ('.$variable.' as '.($key ? $key : '$key').' => '.($value ? $value : '$value').') { ?>';
	}

	public static function parseUrlRewrite($match){
		$originText = $match[0];
		$linkTarget = $match[1];
		if(strpos($linkTarget, '//') !== false) return $originText;
		if(file_exists(FORUM_PATH.$linkTarget)) return $originText;
		return str_replace($linkTarget, 'index.php/'.$linkTarget, $originText);
	}

	private static function preg_get($subject, $pattern, $offset = 1){
		if(!preg_match($pattern, $subject, $matches)) return null;
		return $matches[$offset];
	}
}