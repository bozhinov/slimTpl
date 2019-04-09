<?php

/**
 *  RainTPL
 *  --------
 *  Realized by Federico Ulfo & maintained by the Rain Team
 *  Distributed under GNU/LGPL 3 License
 *
 *  @version 3.0 Alpha milestone: https://github.com/rainphp/raintpl3/issues/milestones?with_issues=no
 *
 *  mod_Slim 1.0 (unofficial)
 *  maintained by Momchil Bozhinov (momchil@bojinov.info)
 *  ------------
 *  - Removed plugins
 *  - Removed blacklist
 *  - Removed the option for extra tags
 *  - Removed SyntaxException
 *  - Removed autoload, replaced with a simple class include
 *  - Simplified config (see examples for usage)
 *  - Parser code somewhat reorganized
 */

namespace Rain;

class Parser {

	// variables
	private $config;
	private $loopLevel = 0;

	// tags natively supported
	protected static $tags = array(
		'loop' => [
			'({loop.*?})',
			'/{loop="(?<variable>\${0,1}[^"]*)"(?: as (?<key>\$.*?)(?: => (?<value>\$.*?)){0,1}){0,1}}/'
		],
		'loop_close' => ['({\/loop})', '/{\/loop}/'],
		'loop_break' => ['({break})', '/{break}/'],
		'loop_continue' => ['({continue})', '/{continue}/'],
		'if' => ['({if.*?})', '/{if="([^"]*)"}/'],
		'elseif' => ['({elseif.*?})', '/{elseif="([^"]*)"}/'],
		'else' => ['({else})', '/{else}/'],
		'if_close' => ['({\/if})', '/{\/if}/'],
		'autoescape' => ['({autoescape.*?})', '/{autoescape="([^"]*)"}/'],
		'autoescape_close' => ['({\/autoescape})', '/{\/autoescape}/'],
		'noparse' => ['({noparse})', '/{noparse}/'],
		'noparse_close' => ['({\/noparse})', '/{\/noparse}/'],
		'ignore' => ['({ignore}|{\*)', '/{ignore}|{\*/'],
		'ignore_close' => ['({\/ignore}|\*})', '/{\/ignore}|\*}/'],
		'include' => ['({include.*?})', '/{include="([^"]*)"}/'],
		'function' =>[
			'({function.*?})',
			'/{function="([a-zA-Z_][a-zA-Z_0-9\:]*)(\(.*\)){0,1}"}/'
		],
		'ternary' => ['({.[^{?]*?\?.*?\:.*?})', '/{(.[^{?]*?)\?(.*?)\:(.*?)}/'],
		'variable' => ['({\$.*?})', '/{(\$.*?)}/'],
		'constant' => ['({#.*?})', '/{#(.*?)#{0,1}}/'],
	);

	function __construct($config)
	{
		$this->config = $config;
	}

	/**
	* Compile the file and save it in the cache
	*
	* @param string $filePath: full path to the template to be compiled
	*
	*/
	public function compileFile($filePath)
	{
		// read the template
		$code = file_get_contents($filePath);

		// xml substitution
		$code = preg_replace("/<\?xml(.*?)\?>/s", /*<?*/ "##XML\\1XML##", $code);

		// xml re-substitution
		$code = preg_replace_callback("/##XML(.*?)XML##/s", function($match) {
				return "<?php echo '<?xml " . stripslashes($match[1]) . " ?>'; ?>";
			}, $code);

		// set tags
		$tagSplit = [];
		$tagMatch = [];

		foreach (static::$tags as $tag => $tagArray) {
			$tagSplit[$tag] = $tagArray[0];
			$tagMatch[$tag] = $tagArray[1];
		}

		//Remove comments
		if ($this->config['remove_comments']) {
			$code = preg_replace('/<!--(.*)-->/Uis', '', $code);
		}

		//split the code with the tags regexp
		$codeSplit = preg_split("/" . implode("|", $tagSplit) . "/", $code, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

		unset($code); // we don't need it any longer

		//variables initialization
		$parsedCode = $commentIsOpen = $ignoreIsOpen = $auto_escape_old = NULL;
		$openIf = 0;

		// if the template is not empty
		if ($codeSplit)

			//read all parsed code
			foreach ($codeSplit as $html) {

				switch(true){
					//close ignore tag
					case (!$commentIsOpen && preg_match($tagMatch['ignore_close'], $html)):
						$ignoreIsOpen = FALSE;
						break;
					//code between tag ignore id deleted
					case ($ignoreIsOpen):
						break;
					//close no parse tag
					case (preg_match($tagMatch['noparse_close'], $html)):
						$commentIsOpen = FALSE;
						break;
					//code between tag noparse is not compiled
					case ($commentIsOpen):
						$parsedCode .= $html;
						break;
					//ignore
					case (preg_match($tagMatch['ignore'], $html)):
						$ignoreIsOpen = TRUE;
						break;
					//noparse
					case (preg_match($tagMatch['noparse'], $html)):
						$commentIsOpen = TRUE;
						break;
					 //include tag
					case (preg_match($tagMatch['include'], $html, $matches)):

						// reduce the path
						$matches[1] = preg_replace(array("#(://(*SKIP)(*FAIL))|(/{2,})#", "#(/\./+)#", "#\\\#"), array("/", "/","\\\\\\"), $matches[1]);		
						while(preg_match('#\w+\.\./#', $matches[1])) {
							$matches[1] = preg_replace('#\w+/\.\./#', '', $matches[1]);
						}
						// parse
						$includeTemplate = $this->varReplace($matches[1]);

						//dynamic include
						if ((strpos($matches[1], '$') !== FALSE)) {
							$parsedCode .= '<?php echo $this->checkTemplate(' . $includeTemplate . ');?>';
						} else {
							$parsedCode .= '<?php echo $this->checkTemplate("' . $includeTemplate . '");?>';
						}
						break;
					//loop
					case(preg_match($tagMatch['loop'], $html, $matches)):

						//replace the variable in the loop
						$var = $this->varReplace($matches['variable'], FALSE);

						$this->loopLevel++; // increase the loop counter

						if (preg_match('#\(#', $var)) {
							$newvar = "\$newvar{$this->loopLevel}";
							$assignNewVar = "$newvar=$var;";
						} else {
							$newvar = $var;
							$assignNewVar = NULL;
						}

						//loop variables
						$counter = "\$counter{$this->loopLevel}"; // count iteration

						if (isset($matches['key']) && isset($matches['value'])) {
							$key = $matches['key'];
							$value = $matches['value'];
						} elseif (isset($matches['key'])) {
							$key = "\$key{$this->loopLevel}";// key
							$value = $matches['key'];
						} else {
							$key = "\$key{$this->loopLevel}"; // key
							$value = "\$value{$this->loopLevel}"; // value
						}

						//loop code
						if (PHP_VERSION_ID > 70099) { # is_iterable avail since 7.1
							$parsedCode .= "<?php $counter=-1; $assignNewVar if(is_iterable($newvar) && count($newvar)) foreach( $newvar as $key => $value ){ $counter++; ?>";
						} else {
							$parsedCode .= "<?php $counter=-1; $assignNewVar if((is_array($newvar) || $newvar instanceof Traversable) && count($newvar)) foreach( $newvar as $key => $value ){ $counter++; ?>";
						}
						break;
					//close loop tag
					case (preg_match($tagMatch['loop_close'], $html)):
						$counter = "\$counter{$this->loopLevel}"; //iterator
						$this->loopLevel--; //decrease the loop counter
						$parsedCode .= "<?php } ?>"; //close loop code
						break;
					//break loop tag
					case (preg_match($tagMatch['loop_break'], $html)):
						$parsedCode .= "<?php break; ?>"; //close loop code
						break;
					//continue loop tag
					case (preg_match($tagMatch['loop_continue'], $html)):
						$parsedCode .= "<?php continue; ?>"; //close loop code
						break;
					//if
					case (preg_match($tagMatch['if'], $html, $matches)):
						$openIf++; //increase open if counter (for intendation)
						//variable substitution into condition (no delimiter into the condition)
						$parsedCondition = $this->varReplace($matches[1], FALSE);
						$parsedCode .= "<?php if( $parsedCondition ){ ?>"; //if code
						break;
					//elseif
					case (preg_match($tagMatch['elseif'], $html, $matches)):
						//variable substitution into condition (no delimiter into the condition)
						$parsedCondition = $this->varReplace($matches[1], FALSE);
						$parsedCode .= "<?php }elseif( $parsedCondition ){ ?>"; //elseif code
						break;
					//else
					case (preg_match($tagMatch['else'], $html)):
						$parsedCode .= '<?php }else{ ?>'; //else code
						break;
					//close if tag
					case (preg_match($tagMatch['if_close'], $html)):
						$openIf--; //decrease if counter
						$parsedCode .= '<?php } ?>'; // close if code
						break;
					//variables
					case (preg_match($tagMatch['variable'], $html, $matches)):
						//variables substitution (es. {$title})
						$parsedCode .= "<?php " . $this->varReplace($matches[1], TRUE, TRUE) . "; ?>";
						break;
					// autoescape off
					case (preg_match($tagMatch['autoescape'], $html, $matches)):
						$auto_escape_old = $this->config['auto_escape'];
						$this->config['auto_escape'] = (in_array($matches[1], array('off','false','0', NULL))) ? FALSE : TRUE;
						break;
					// autoescape on
					case (preg_match($tagMatch['autoescape_close'], $html, $matches)):
						$this->config['auto_escape'] = $auto_escape_old;
						break;
					// function
					case (preg_match($tagMatch['function'], $html, $matches)):
						$parsedCode .= "<?php echo ".$matches[1] . (isset($matches[2]) ? $this->varReplace($matches[2], FALSE) : "()")."; ?>"; // function
						break;
					//ternary
					case (preg_match($tagMatch['ternary'], $html, $matches)):
						$parsedCode .= "<?php echo " . '(' . $this->varReplace($matches[1]) . '?' . $this->varReplace($matches[2]) . ':' . $this->varReplace($matches[3]) . ')' . "; ?>";
						break;
					//constants
					case (preg_match($tagMatch['constant'], $html, $matches)):
						//$parsedCode .= "<?php echo " . $this->conReplace($matches[1], $this->loopLevel) . "; 
						//Issue recorded as: https://github.com/rainphp/raintpl3/issues/178
						$parsedCode .= "<?php " . $this->modifierReplace($matches[1]) . "; ?>";
						break;
					default:
						$parsedCode .= $html;
				}

			}

		if ($openIf > 0) {
			$e = new Exception("Error! You need to close an {if} tag in ".$filePath." template");
			throw $e->templateFile($filePath);
		}

		if ($this->loopLevel > 0) {
			$e = new Exception("Error! You need to close the {loop} tag in ".$filePath." template");
			throw $e->templateFile($filePath);
		}

		$parsedCode = "<?php if(!class_exists('Rain\Tpl')){exit;}?>" . $parsedCode;

		// fix the php-eating-newline-after-closing-tag-problem
		#$parsedCode = str_replace("?\>\n", "?\>\n\n", $parsedCode);

		return $parsedCode;
    }

    protected function varReplace($html, $escape = TRUE, $echo = FALSE) 
	{
		// change variable name if loop level
		$html = preg_replace(['/(\$key)\b/', '/(\$value)\b/', '/(\$counter)\b/'], ['${1}' . $this->loopLevel, '${1}' . $this->loopLevel, '${1}' . $this->loopLevel], $html);

		// if it is a variable
		if (preg_match_all('/(\$[a-z_A-Z][^\s]*)/', $html, $matches)) {
			// substitute . and [] with [" "]
			for ($i = 0; $i < count($matches[1]); $i++) {

				$rep = preg_replace('/\[(\${0,1}[a-zA-Z_0-9]*)\]/', '["$1"]', $matches[1][$i]);
				$rep = preg_replace('/\.(\${0,1}[a-zA-Z_0-9]*(?![a-zA-Z_0-9]*(\'|\")))/', '["$1"]', $rep);
				$html = str_replace($matches[0][$i], $rep, $html);
			}

			// update modifier
			$html = $this->modifierReplace($html);

			// if does not initialize a value, e.g. {$a = 1}
			if (!preg_match('/\$.*=.*/', $html)) {

				// escape character
				($this->config['auto_escape'] && $escape) AND $html = "htmlspecialchars($html, ENT_COMPAT, '" . $this->config['charset'] . "', FALSE)";
				($echo) AND $html = "echo ".$html;
			}
		}

		return $html;
    }

    protected function modifierReplace($html)
	{

		while (strpos($html,'|') !== false && substr($html, strpos($html,'|')+1,1) != "|") {

			preg_match('/([\$a-z_A-Z0-9\(\),\[\]"->]+)\|([\$a-z_A-Z0-9\(\):,\[\]"->]+)/i', $html, $result);

			list($function, $params) = explode(":", $result[2]);
			(!is_null($params)) AND $params = ",".$params;

			$html = str_replace($result[0], $function . "(" . $result[1] . "$params)", $html);
		}

		return $html;
    }

}

?>