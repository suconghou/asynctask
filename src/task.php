<?php

/**
 * Closure context class
 */
class ClosureContext
{
	/**
	 * @var ClosureScope Closures scope
	 */
	public $scope;

	/**
	 * @var integer
	 */
	public $locks;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->scope = new ClosureScope();
		$this->locks = 0;
	}
}

/**
 * Closure scope class
 */
class ClosureScope extends SplObjectStorage
{
	/**
	 * @var integer Number of serializations in current scope
	 */
	public $serializations = 0;

	/**
	 * @var integer Number of closures that have to be serialized
	 */
	public $toserialize = 0;
}


class ClosureStream
{
	const STREAM_PROTO = 'closure';

	protected static $isRegistered = false;

	protected $content;

	protected $length;

	protected $pointer = 0;

	function stream_open($path, $mode, $options, &$opened_path)
	{
		$this->content = "<?php\nreturn " . substr($path, strlen(static::STREAM_PROTO . '://')) . ";";
		$this->length = strlen($this->content);
		return true;
	}

	public function stream_read($count)
	{
		$value = substr($this->content, $this->pointer, $count);
		$this->pointer += $count;
		return $value;
	}

	public function stream_eof()
	{
		return $this->pointer >= $this->length;
	}

	public function stream_stat()
	{
		$stat = stat(__FILE__);
		$stat[7] = $stat['size'] = $this->length;
		return $stat;
	}

	public function url_stat($path, $flags)
	{
		$stat = stat(__FILE__);
		$stat[7] = $stat['size'] = $this->length;
		return $stat;
	}

	public function stream_seek($offset, $whence = SEEK_SET)
	{
		$crt = $this->pointer;

		switch ($whence) {
			case SEEK_SET:
				$this->pointer = $offset;
				break;
			case SEEK_CUR:
				$this->pointer += $offset;
				break;
			case SEEK_END:
				$this->pointer = $this->length + $offset;
				break;
		}

		if ($this->pointer < 0 || $this->pointer >= $this->length) {
			$this->pointer = $crt;
			return false;
		}

		return true;
	}

	public function stream_tell()
	{
		return $this->pointer;
	}

	public static function register()
	{
		if (!static::$isRegistered) {
			static::$isRegistered = stream_wrapper_register(static::STREAM_PROTO, __CLASS__);
		}
	}

}



class ReflectionClosure extends ReflectionFunction
{
	protected $code;
	protected $tokens;
	protected $hashedName;
	protected $useVariables;
	protected $isStaticClosure;
	protected $isScopeRequired;
	protected $isBindingRequired;

	protected static $files = array();
	protected static $classes = array();
	protected static $functions = array();
	protected static $constants = array();
	protected static $structures = array();

	/**
	 * ReflectionClosure constructor.
	 * @param Closure $closure
	 * @param string|null $code
	 */
	public function __construct(Closure $closure, $code = null)
	{
		$this->code = $code;
		parent::__construct($closure);
	}

	/**
	 * @return bool
	 */
	public function isStatic()
	{
		if ($this->isStaticClosure === null) {
			$this->isStaticClosure = strtolower(substr($this->getCode(), 0, 6)) === 'static';
		}

		return $this->isStaticClosure;
	}

	/**
	 * @return string
	 */
	public function getCode()
	{
		if($this->code !== null){
			return $this->code;
		}

		$fileName = $this->getFileName();
		$line = $this->getStartLine() - 1;

		$match = ClosureStream::STREAM_PROTO . '://';

		if ($line === 1 && substr($fileName, 0, strlen($match)) === $match) {
			return $this->code = substr($fileName, strlen($match));
		}

		$className = null;


		if (null !== $className = $this->getClosureScopeClass()) {
			$className = '\\' . trim($className->getName(), '\\');
		}


		$php7 = '7' === "\u{37}";
		$php7_types = array('string', 'int', 'bool', 'float');
		$ns = $this->getNamespaceName();
		$nsf = $ns == '' ? '' : ($ns[0] == '\\' ? $ns : '\\' . $ns);

		$_file = var_export($fileName, true);
		$_dir = var_export(dirname($fileName), true);
		$_namespace = var_export($ns, true);
		$_class = var_export(trim($className, '\\'), true);
		$_function = $ns . ($ns == '' ? '' : '\\') . '{closure}';
		$_method = ($className == '' ? '' : trim($className, '\\') . '::') . $_function;
		$_function = var_export($_function, true);
		$_method = var_export($_method, true);
		$_trait = null;

		$hasTraitSupport = defined('T_TRAIT_C');
		$tokens = $this->getTokens();
		$state = $lastState = 'start';
		$open = 0;
		$code = '';
		$id_start = $id_start_ci = $id_name = $context = '';
		$classes = $functions = $constants = null;
		$use = array();
		$lineAdd = 0;
		$isUsingScope = false;
		$isUsingThisObject = false;


		for($i = 0, $l = count($tokens); $i < $l; $i++) {
			$token = $tokens[$i];
			switch ($state) {
				case 'start':
					if ($token[0] === T_FUNCTION || $token[0] === T_STATIC) {
						$code .= $token[1];
						$state = $token[0] === T_FUNCTION ? 'function' : 'static';
					}
					break;
				case 'static':
					if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_FUNCTION) {
						$code .= $token[1];
						if ($token[0] === T_FUNCTION) {
							$state = 'function';
						}
					} else {
						$code = '';
						$state = 'start';
					}
					break;
				case 'function':
					switch ($token[0]){
						case T_STRING:
							$code = '';
							$state = 'named_function';
							break;
						case '(':
							$code .= '(';
							$state = 'closure_args';
							break;
						default:
							$code .= is_array($token) ? $token[1] : $token;
					}
					break;
				case 'named_function':
					if($token[0] === T_FUNCTION || $token[0] === T_STATIC){
						$code = $token[1];
						$state = $token[0] === T_FUNCTION ? 'function' : 'static';
					}
					break;
				case 'closure_args':
					switch ($token[0]){
						case T_NS_SEPARATOR:
						case T_STRING:
							$id_start = $token[1];
							$id_start_ci = strtolower($id_start);
							$id_name = '';
							$context = 'args';
							$state = 'id_name';
							$lastState = 'closure_args';
							break;
						case T_USE:
							$code .= $token[1];
							$state = 'use';
							break;
						case '=':
							$code .= $token;
							$lastState = 'closure_args';
							$state = 'ignore_next';
							break;
						case '{':
							$code .= '{';
							$state = 'closure';
							$open++;
							break;
						default:
							$code .= is_array($token) ? $token[1] : $token;
					}
					break;
				case 'use':
					switch ($token[0]){
						case T_VARIABLE:
							$use[] = substr($token[1], 1);
							$code .= $token[1];
							break;
						case '{':
							$code .= '{';
							$state = 'closure';
							$open++;
							break;
						default:
							$code .= is_array($token) ? $token[1] : $token;
							break;
					}
					break;
				case 'closure':
					switch ($token[0]){
						case T_CURLY_OPEN:
						case T_DOLLAR_OPEN_CURLY_BRACES:
						case T_STRING_VARNAME:
						case '{':
							$code .= '{';
							$open++;
							break;
						case '}':
							$code .= '}';
							if(--$open === 0){
								break 3;
							}
							break;
						case T_LINE:
							$code .= $token[2] - $line + $lineAdd;
							break;
						case T_FILE:
							$code .= $_file;
							break;
						case T_DIR:
							$code .= $_dir;
							break;
						case T_NS_C:
							$code .= $_namespace;
							break;
						case T_CLASS_C:
							$code .= $_class;
							break;
						case T_FUNC_C:
							$code .= $_function;
							break;
						case T_METHOD_C:
							$code .= $_method;
							break;
						case T_COMMENT:
							if (substr($token[1], 0, 8) === '#trackme') {
								$timestamp = time();
								$code .= '/**' . PHP_EOL;
								$code .= '* Date      : ' . date(DATE_W3C, $timestamp) . PHP_EOL;
								$code .= '* Timestamp : ' . $timestamp . PHP_EOL;
								$code .= '* Line      : ' . ($line + 1) . PHP_EOL;
								$code .= '* File      : ' . $_file . PHP_EOL . '*/' . PHP_EOL;
								$lineAdd += 5;
							} else {
								$code .= $token[1];
							}
							break;
						case T_VARIABLE:
							if($token[1] == '$this'){
								$isUsingThisObject = true;
							}
							$code .= $token[1];
							break;
						case T_STATIC:
							$isUsingScope = true;
							$code .= $token[1];
							break;
						case T_NS_SEPARATOR:
						case T_STRING:
							$id_start = $token[1];
							$id_start_ci = strtolower($id_start);
							$id_name = '';
							$context = 'root';
							$state = 'id_name';
							$lastState = 'closure';
							break 2;
						case T_NEW:
							$code .= $token[1];
							$context = 'new';
							$state = 'id_start';
							$lastState = 'closure';
							break 2;
						case T_INSTANCEOF:
							$code .= $token[1];
							$context = 'instanceof';
							$state = 'id_start';
							$lastState = 'closure';
							break;
						case T_OBJECT_OPERATOR:
						case T_DOUBLE_COLON:
							$code .= $token[1];
							$lastState = 'closure';
							$state = 'ignore_next';
							break;
						default:
							if ($hasTraitSupport && $token[0] == T_TRAIT_C) {
								if ($_trait === null) {
									$startLine = $this->getStartLine();
									$endLine = $this->getEndLine();
									$structures = $this->getStructures();

									$_trait = '';

									foreach ($structures as &$struct) {
										if ($struct['type'] === 'trait' &&
											$struct['start'] <= $startLine &&
											$struct['end'] >= $endLine
										) {
											$_trait = ($ns == '' ? '' : $ns . '\\') . $struct['name'];
											break;
										}
									}

									$_trait = var_export($_trait, true);
								}

								$token[1] = $_trait;
							} else {
								$code .= is_array($token) ? $token[1] : $token;
							}
					}
					break;
				case 'ignore_next':
					switch ($token[0]){
						case T_WHITESPACE:
							$code .= $token[1];
							break;
						case T_CLASS:
						case T_STATIC:
						case T_VARIABLE:
						case T_STRING:
							$code .= $token[1];
							$state = $lastState;
							break;
						default:
							$state = $lastState;
							$i--;
					}
					break;
				case 'id_start':
					switch ($token[0]){
						case T_WHITESPACE:
							$code .= $token[1];
							break;
						case T_NS_SEPARATOR:
						case T_STRING:
						case T_STATIC:
							$id_start = $token[1];
							$id_start_ci = strtolower($id_start);
							$id_name = '';
							$state = 'id_name';
							break 2;
						default:
							$i--;//reprocess last
							$state = 'id_name';
					}
					break;
				case 'id_name':
					switch ($token[0]){
						case T_NS_SEPARATOR:
						case T_STRING:
							$id_name .= $token[1];
							break;
						case T_WHITESPACE:
							$id_name .= $token[1];
							break;
						case '(':
							if($context === 'new' || false !== strpos($id_name, '\\')){
								if($id_start !== '\\'){
									if ($classes === null) {
										$classes = $this->getClasses();
									}
									if (isset($classes[$id_start_ci])) {
										$id_start = $classes[$id_start_ci];
									}
									if($id_start[0] !== '\\'){
										$id_start = $nsf . '\\' . $id_start;
									}
								}
							} else {
								if($id_start !== '\\'){
									if($functions === null){
										$functions = $this->getFunctions();
									}
									if(isset($functions[$id_start_ci])){
										$id_start = $functions[$id_start_ci];
									}
								}
							}
							$code .= $id_start . $id_name . '(';
							$state = $lastState;
							break;
						case T_VARIABLE:
						case T_DOUBLE_COLON:
							if($id_start !== '\\') {
								if($id_start_ci === 'self' || $id_start_ci === 'static'){
									$isUsingScope = true;
								} elseif (!($php7 && in_array($id_start_ci, $php7_types))){
									if ($classes === null) {
										$classes = $this->getClasses();
									}
									if (isset($classes[$id_start_ci])) {
										$id_start = $classes[$id_start_ci];
									}
									if($id_start[0] !== '\\'){
										$id_start = $nsf . '\\' . $id_start;
									}
								}
							}
							$code .= $id_start . $id_name . $token[1];
							$state = $token[0] === T_DOUBLE_COLON ? 'ignore_next' : $lastState;
							break;
						default:
							if($id_start !== '\\'){
								if($context === 'instanceof' || $context === 'args'){
									if($id_start_ci === 'self' || $id_start_ci === 'static'){
										$isUsingScope = true;
									} elseif (!($php7 && in_array($id_start_ci, $php7_types))){
										if($classes === null){
											$classes = $this->getClasses();
										}
										if(isset($classes[$id_start_ci])){
											$id_start = $classes[$id_start_ci];
										}
										if($id_start[0] !== '\\'){
											$id_start = $nsf . '\\' . $id_start;
										}
									}
								} else {
									if($constants === null){
										$constants = $this->getConstants();
									}
									if(isset($constants[$id_start])){
										$id_start = $constants[$id_start];
									}
								}
							}
							$code .= $id_start . $id_name;
							$state = $lastState;
							$i--;//reprocess last token
					}
					break;
			}
		}

		$this->isBindingRequired = $isUsingThisObject;
		$this->isScopeRequired = $isUsingScope;
		$this->code = $code;
		$this->useVariables = empty($use) ? $use : array_intersect_key($this->getStaticVariables(), array_flip($use));

		return $this->code;
	}

	/**
	 * @return array
	 */
	public function getUseVariables()
	{
		if($this->useVariables !== null){
			return $this->useVariables;
		}

		$tokens = $this->getTokens();
		$use = array();
		$state = 'start';

		foreach ($tokens as &$token) {
			$is_array = is_array($token);

			switch ($state) {
				case 'start':
					if ($is_array && $token[0] === T_USE) {
						$state = 'use';
					}
					break;
				case 'use':
					if ($is_array) {
						if ($token[0] === T_VARIABLE) {
							$use[] = substr($token[1], 1);
						}
					} elseif ($token == ')') {
						break 2;
					}
					break;
			}
		}

		$this->useVariables = empty($use) ? $use : array_intersect_key($this->getStaticVariables(), array_flip($use));

		return $this->useVariables;
	}

	/**
	 * return bool
	 */
	public function isBindingRequired()
	{
		if($this->isBindingRequired === null){
			$this->getCode();
		}

		return $this->isBindingRequired;
	}

	/**
	 * return bool
	 */
	public function isScopeRequired()
	{
		if($this->isScopeRequired === null){
			$this->getCode();
		}

		return $this->isScopeRequired;
	}

	/**
	 * @return string
	 */
	protected function getHashedFileName()
	{
		if ($this->hashedName === null) {
			$this->hashedName = md5($this->getFileName());
		}

		return $this->hashedName;
	}

	/**
	 * @return array
	 */
	protected function getFileTokens()
	{
		$key = $this->getHashedFileName();

		if (!isset(static::$files[$key])) {
			static::$files[$key] = token_get_all(file_get_contents($this->getFileName()));
		}

		return static::$files[$key];
	}

	/**
	 * @return array
	 */
	protected function getTokens()
	{
		if ($this->tokens === null) {
			$tokens = $this->getFileTokens();
			$startLine = $this->getStartLine();
			$endLine = $this->getEndLine();
			$results = array();
			$start = false;

			foreach ($tokens as &$token) {
				if (!is_array($token)) {
					if ($start) {
						$results[] = $token;
					}

					continue;
				}

				$line = $token[2];

				if ($line <= $endLine) {
					if ($line >= $startLine) {
						$start = true;
						$results[] = $token;
					}

					continue;
				}

				break;
			}

			$this->tokens = $results;
		}

		return $this->tokens;
	}

	/**
	 * @return array
	 */
	protected function getClasses()
	{
		$key = $this->getHashedFileName();

		if (!isset(static::$classes[$key])) {
			$this->fetchItems();
		}

		return static::$classes[$key];
	}

	/**
	 * @return array
	 */
	protected function getFunctions()
	{
		$key = $this->getHashedFileName();

		if (!isset(static::$functions[$key])) {
			$this->fetchItems();
		}

		return static::$functions[$key];
	}

	/**
	 * @return array
	 */
	protected function getConstants()
	{
		$key = $this->getHashedFileName();

		if (!isset(static::$constants[$key])) {
			$this->fetchItems();
		}

		return static::$constants[$key];
	}

	/**
	 * @return array
	 */
	protected function getStructures()
	{
		$key = $this->getHashedFileName();

		if (!isset(static::$structures[$key])) {
			$this->fetchItems();
		}

		return static::$structures[$key];
	}

	protected function fetchItems()
	{
		$key = $this->getHashedFileName();

		$classes = array();
		$functions = array();
		$constants = array();
		$structures = array();
		$tokens = $this->getFileTokens();

		$open = 0;
		$state = 'start';
		$prefix = '';
		$name = '';
		$alias = '';
		$isFunc = $isConst = false;

		$startLine = $endLine = 0;
		$structType = $structName = '';
		$structIgnore = false;

		$hasTraitSupport = defined('T_TRAIT');

		foreach ($tokens as $token) {
			$is_array = is_array($token);

			switch ($state) {
				case 'start':
					if ($is_array) {
						switch ($token[0]) {
							case T_CLASS:
							case T_INTERFACE:
								$state = 'before_structure';
								$startLine = $token[2];
								$structType = $token[0] == T_CLASS ? 'class' : 'interface';
								break;
							case T_USE:
								$state = 'use';
								$prefix = $name = $alias = '';
								$isFunc = $isConst = false;
								break;
							case T_FUNCTION:
								$state = 'structure';
								$structIgnore = true;
								break;
							default:
								if ($hasTraitSupport && $token[0] == T_TRAIT) {
									$state = 'before_structure';
									$startLine = $token[2];
									$structType = 'trait';
								}
								break;
						}
					}
					break;
				case 'use':
					if ($is_array) {
						switch ($token[0]) {
							case T_FUNCTION:
								$isFunc = true;
								break;
							case T_CONST:
								$isConst = true;
								break;
							case T_NS_SEPARATOR:
								$name .= $token[1];
								break;
							case T_STRING:
								$name .= $token[1];
								$alias = $token[1];
								break;
							case T_AS:
								if ($name[0] !== '\\' && $prefix === '') {
									$name = '\\' . $name;
								}
								$state = 'alias';
								break;
						}
					} else {
						if ($name[0] !== '\\' && $prefix === '') {
							$name = '\\' . $name;
						}

						if($token == '{') {
							$prefix = $name;
							$name = '';
						} else {
							if($isFunc){
								$functions[strtolower($alias)] = $prefix . $name;
							} elseif ($isConst){
								$constants[$alias] = $prefix . $name;
							} else {
								$classes[strtolower($alias)] = $prefix . $name;
							}
							$name = '';
							$state = $token == ',' ? 'use' : 'start';
						}
					}
					break;
				case 'alias':
					if ($is_array) {
						if($token[0] == T_STRING){
							$alias = $token[1];
						}
					} else {
						if($isFunc){
							$functions[strtolower($alias)] = $prefix . $name;
						} elseif ($isConst){
							$constants[$alias] = $prefix . $name;
						} else {
							$classes[strtolower($alias)] = $prefix . $name;
						}
						$name = '';
						$state = $token == ',' ? 'use' : 'start';
					}
					break;
				case 'before_structure':
					if ($is_array && $token[0] == T_STRING) {
						$structName = $token[1];
						$state = 'structure';
					}
					break;
				case 'structure':
					if (!$is_array) {
						if ($token === '{') {
							$open++;
						} elseif ($token === '}') {
							if (--$open == 0) {
								if(!$structIgnore){
									$structures[] = array(
										'type' => $structType,
										'name' => $structName,
										'start' => $startLine,
										'end' => $endLine,
									);
								}
								$structIgnore = false;
								$state = 'start';
							}
						}
					} else {
						if($token[0] === T_CURLY_OPEN ||
							$token[0] === T_DOLLAR_OPEN_CURLY_BRACES ||
							$token[0] === T_STRING_VARNAME){
							$open++;
						}
						$endLine = $token[2];
					}
					break;
			}
		}

		static::$classes[$key] = $classes;
		static::$functions[$key] = $functions;
		static::$constants[$key] = $constants;
		static::$structures[$key] = $structures;
	}

}

class SecurityException extends Exception
{

}


interface ISecurityProvider
{
	/**
	 * Sign serialized closure
	 * @param string $closure
	 * @return array
	 */
	public function sign($closure);

	/**
	 * Verify signature
	 * @param array $data
	 * @return bool
	 */
	public function verify(array $data);
}

class SecurityProvider implements ISecurityProvider
{
	/** @var  string */
	protected $secret;

	/**
	 * SecurityProvider constructor.
	 * @param string $secret
	 */
	public function __construct($secret)
	{
		$this->secret = $secret;
	}

	/**
	 * @inheritdoc
	 */
	public function sign($closure)
	{
		return array(
			'closure' => $closure,
			'hash' => base64_encode(hash_hmac('sha256', $closure, $this->secret, true)),
		);
	}

	/**
	 * @inheritdoc
	 */
	public function verify(array $data)
	{
		return base64_encode(hash_hmac('sha256', $data['closure'], $this->secret, true)) === $data['hash'];
	}
}

/**
 * Helper class used to indicate a reference to an object
 */
class SelfReference
{
	/**
	 * @var string An unique hash representing the object
	 */
	public $hash;

	/**
	 * Constructor
	 *
	 * @param string $hash
	 */
	public function __construct($hash)
	{
		$this->hash = $hash;
	}
}


/**
 * Provides a wrapper for serialization of closures
 */
class SerializableClosure implements Serializable
{
	/**
	 * @var Closure Wrapped closure
	 *
	 * @see \Opis\Closure\SerializableClosure::getClosure()
	 */
	protected $closure;

	/**
	 * @var ReflectionClosure A reflection instance for closure
	 *
	 * @see \Opis\Closure\SerializableClosure::getReflector()
	 */
	protected $reflector;

	/**
	 * @var mixed Used at deserialization to hold variables
	 *
	 * @see \Opis\Closure\SerializableClosure::unserialize()
	 * @see \Opis\Closure\SerializableClosure::getReflector()
	 */
	protected $code;

	/**
	 * @var string Closure's ID
	 */
	protected $reference;

	/**
	 * @var string Closure scope
	 */
	protected $scope;

	/**
	 * @var ClosureContext Context of closure, used in serialization
	 */
	protected static $context;

	/**
	 * @var ISecurityProvider|null
	 */
	protected static $securityProvider;

	/** Array recursive constant*/
	const ARRAY_RECURSIVE_KEY = '¯\_(ツ)_/¯';

	/**
	 * Constructor
	 *
	 * @param   Closure $closure Closure you want to serialize
	 */
	public function __construct(Closure $closure)
	{
		$this->closure = $closure;
		if (static::$context !== null) {
			$this->scope = static::$context->scope;
			$this->scope->toserialize++;
		}
	}

	/**
	 * Get the Closure object
	 *
	 * @return  Closure The wrapped closure
	 */
	public function getClosure()
	{
		return $this->closure;
	}

	/**
	 * Get the reflector for closure
	 *
	 * @return  ReflectionClosure
	 */
	public function getReflector()
	{
		if ($this->reflector === null) {
			$this->reflector = new ReflectionClosure($this->closure, $this->code);
			$this->code = null;
		}

		return $this->reflector;
	}

	/**
	 * Implementation of magic method __invoke()
	 */
	public function __invoke()
	{
		return call_user_func_array($this->closure, func_get_args());
	}

	/**
	 * Implementation of Serializable::serialize()
	 *
	 * @return  string  The serialized closure
	 */
	public function serialize()
	{
		if ($this->scope === null) {
			$this->scope = new ClosureScope();
			$this->scope->toserialize++;
		}

		$this->scope->serializations++;

		$scope = $object = null;
		$reflector = $this->getReflector();

		if($reflector->isBindingRequired()){
			$object = $reflector->getClosureThis();
			static::wrapClosures($object, $this->scope);
			if($scope = $reflector->getClosureScopeClass()){
				$scope = $scope->name;
			}
		} elseif($reflector->isScopeRequired()) {
			if($scope = $reflector->getClosureScopeClass()){
				$scope = $scope->name;
			}
		}

		$this->reference = spl_object_hash($this->closure);

		$this->scope[$this->closure] = $this;

		$use = $reflector->getUseVariables();
		$code = $reflector->getCode();

		$this->mapByReference($use);

		$ret = \serialize(array(
			'use' => $use,
			'function' => $code,
			'scope' => $scope,
			'this' => $object,
			'self' => $this->reference,
		));

		if(static::$securityProvider !== null){
			$ret =  '@' . json_encode(static::$securityProvider->sign($ret));
		}

		if (!--$this->scope->serializations && !--$this->scope->toserialize) {
			$this->scope = null;
		}

		return $ret;
	}

	/**
	 * Implementation of Serializable::unserialize()
	 *
	 * @param   string $data Serialized data
	 * @throws SecurityException
	 */
	public function unserialize($data)
	{
		ClosureStream::register();

		if($data[0] === '@'){
			$data = json_decode(substr($data, 1), true);
			if(static::$securityProvider !== null){
				if(!static::$securityProvider->verify($data)){
					throw new SecurityException("Your serialized closure might have been modified and it's unsafe to be unserialized." .
						"Make sure you are using the same security provider, with the same settings, " .
						"both for serialization and unserialization.");
				}
			}
			$data = $data['closure'];
		}

		$this->code = \unserialize($data);

		// unset data
		unset($data);

		$this->code['objects'] = array();

		if ($this->code['use']) {
			$this->scope = new ClosureScope();
			$this->mapPointers($this->code['use']);
			extract($this->code['use'], EXTR_OVERWRITE | EXTR_REFS);
			$this->scope = null;
		}

		$this->closure = include(ClosureStream::STREAM_PROTO . '://' . $this->code['function']);

		if($this->code['this'] === $this){
			$this->code['this'] = null;
		}

		if ($this->code['scope'] !== null || $this->code['this'] !== null) {
			$this->closure = $this->closure->bindTo($this->code['this'], $this->code['scope']);
		}

		if(!empty($this->code['objects'])){
			foreach ($this->code['objects'] as $item){
				$item['property']->setValue($item['instance'], $item['object']->getClosure());
			}
		}

		$this->code = $this->code['function'];
	}

	/**
	 * Wraps a closure and sets the serialization context (if any)
	 *
	 * @param   Closure $closure Closure to be wrapped
	 *
	 * @return  self    The wrapped closure
	 */
	public static function from(Closure $closure)
	{
		if (static::$context === null) {
			$instance = new static($closure);
		} elseif (isset(static::$context->scope[$closure])) {
			$instance = static::$context->scope[$closure];
		} else {
			$instance = new static($closure);
			static::$context->scope[$closure] = $instance;
		}

		return $instance;
	}

	/**
	 * Increments the context lock counter or creates a new context if none exist
	 */
	public static function enterContext()
	{
		if (static::$context === null) {
			static::$context = new ClosureContext();
		}

		static::$context->locks++;
	}

	/**
	 * Decrements the context lock counter and destroy the context when it reaches to 0
	 */
	public static function exitContext()
	{
		if (static::$context !== null && !--static::$context->locks) {
			static::$context = null;
		}
	}

	/**
	 * @param string $secret
	 */
	public static function setSecretKey($secret)
	{
		if(static::$securityProvider === null){
			static::$securityProvider = new SecurityProvider($secret);
		}
	}

	/**
	 * @param ISecurityProvider $securityProvider
	 */
	public static function addSecurityProvider(ISecurityProvider $securityProvider)
	{
		static::$securityProvider = $securityProvider;
	}

	/**
	 * @return null|ISecurityProvider
	 */
	public static function getSecurityProvider()
	{
		return static::$securityProvider;
	}

	/**
	 * Wrap closures
	 *
	 * @param $data
	 * @param ClosureScope|SplObjectStorage|null $storage
	 */
	public static function wrapClosures(&$data, SplObjectStorage $storage = null)
	{
		static::enterContext();

		if($storage === null){
			$storage = static::$context->scope;
		}

		if($data instanceof Closure){
			$data = static::from($data);
		} elseif (is_array($data)){
			if(isset($data[self::ARRAY_RECURSIVE_KEY])){
				return;
			}
			$data[self::ARRAY_RECURSIVE_KEY] = true;
			foreach ($data as $key => &$value){
				if($key === self::ARRAY_RECURSIVE_KEY){
					continue;
				}
				static::wrapClosures($value, $storage);
			}
			unset($value);
			unset($data[self::ARRAY_RECURSIVE_KEY]);
		} elseif($data instanceof \stdClass){
			if(isset($storage[$data])){
				$data = $storage[$data];
				return;
			}
			$data = $storage[$data] = clone($data);
			foreach ($data as &$value){
				static::wrapClosures($value, $storage);
			}
			unset($value);
		} elseif (is_object($data) && ! $data instanceof static){
			if(isset($storage[$data])){
				$data = $storage[$data];
				return;
			}
			$instance = $data;
			$reflection = new ReflectionObject($data);
			$storage[$instance] = $data = $reflection->newInstanceWithoutConstructor();
			foreach ($reflection->getProperties() as $property){
				if($property->isStatic()){
					continue;
				}
				$property->setAccessible(true);
				$value = $property->getValue($instance);
				if(is_array($value) || is_object($value)){
					static::wrapClosures($value, $storage);
				}
				$property->setValue($data, $value);
			}
		}

		static::exitContext();
	}

	/**
	 * Unwrap closures
	 *
	 * @param $data
	 * @param SplObjectStorage|null $storage
	 */
	public static function unwrapClosures(&$data, SplObjectStorage $storage = null)
	{
		if($storage === null){
			$storage = static::$context->scope;
		}

		if($data instanceof static){
			$data = $data->getClosure();
		} elseif (is_array($data)){
			if(isset($data[self::ARRAY_RECURSIVE_KEY])){
				return;
			}
			$data[self::ARRAY_RECURSIVE_KEY] = true;
			foreach ($data as $key => &$value){
				if($key === self::ARRAY_RECURSIVE_KEY){
					continue;
				}
				static::unwrapClosures($value, $storage);
			}
			unset($data[self::ARRAY_RECURSIVE_KEY]);
		}elseif ($data instanceof \stdClass){
			if(isset($storage[$data])){
				return;
			}
			$storage[$data] = true;
			foreach ($data as &$property){
				static::unwrapClosures($property, $storage);
			}
		} elseif (is_object($data) && !($data instanceof Closure)){
			if(isset($storage[$data])){
				return;
			}
			$storage[$data] = true;
			$reflection = new ReflectionObject($data);
			foreach ($reflection->getProperties() as $property){
				if($property->isStatic()){
					continue;
				}
				$property->setAccessible(true);
				$value = $property->getValue($data);
				if(is_array($value) || is_object($value)){
					static::unwrapClosures($value, $storage);
					$property->setValue($data, $value);
				}
			}
		}
	}

	/**
	 * Internal method used to map closure pointers
	 * @param $data
	 */
	protected function mapPointers(&$data)
	{
		$scope = $this->scope;

		if ($data instanceof static) {
			$data = &$data->closure;
		} elseif (is_array($data)) {
			if(isset($data[self::ARRAY_RECURSIVE_KEY])){
				return;
			}
			$data[self::ARRAY_RECURSIVE_KEY] = true;
			foreach ($data as $key => &$value){
				if($key === self::ARRAY_RECURSIVE_KEY){
					continue;
				} elseif ($value instanceof static) {
					$data[$key] = &$value->closure;
				} elseif ($value instanceof SelfReference && $value->hash === $this->code['self']){
					$data[$key] = &$this->closure;
				} else {
					$this->mapPointers($value);
				}
			}
			unset($value);
			unset($data[self::ARRAY_RECURSIVE_KEY]);
		} elseif ($data instanceof \stdClass) {
			if(isset($scope[$data])){
				return;
			}
			$scope[$data] = true;
			foreach ($data as $key => &$value){
				if ($value instanceof SelfReference && $value->hash === $this->code['self']){
					$data->{$key} = &$this->closure;
				} elseif(is_array($value) || is_object($value)) {
					$this->mapPointers($value);
				}
			}
			unset($value);
		} elseif (is_object($data) && !($data instanceof Closure)){
			if(isset($scope[$data])){
				return;
			}
			$scope[$data] = true;
			$reflection = new ReflectionObject($data);
			foreach ($reflection->getProperties() as $property){
				if($property->isStatic()){
					continue;
				}
				$property->setAccessible(true);
				$item = $property->getValue($data);
				if ($item instanceof SerializableClosure || ($item instanceof SelfReference && $item->hash === $this->code['self'])) {
					$this->code['objects'][] = array(
						'instance' => $data,
						'property' => $property,
						'object' => $item instanceof SelfReference ? $this : $item,
					);
				} elseif (is_array($item) || is_object($item)) {
					$this->mapPointers($item);
					$property->setValue($data, $item);
				}
			}
		}
	}

	/**
	 * Internal method used to map closures by reference
	 *
	 * @param   mixed &$data
	 */
	protected function mapByReference(&$data)
	{
		if ($data instanceof Closure) {
			if($data === $this->closure){
				$data = new SelfReference($this->reference);
				return;
			}

			if (isset($this->scope[$data])) {
				$data = $this->scope[$data];
				return;
			}

			$instance = new static($data);

			if (static::$context !== null) {
				static::$context->scope->toserialize--;
			} else {
				$instance->scope = $this->scope;
			}

			$data = $this->scope[$data] = $instance;
		} elseif (is_array($data)) {
			if(isset($data[self::ARRAY_RECURSIVE_KEY])){
				return;
			}
			$data[self::ARRAY_RECURSIVE_KEY] = true;
			foreach ($data as $key => &$value){
				if($key === self::ARRAY_RECURSIVE_KEY){
					continue;
				}
				$this->mapByReference($value);
			}
			unset($value);
			unset($data[self::ARRAY_RECURSIVE_KEY]);
		} elseif ($data instanceof \stdClass) {
			if(isset($this->scope[$data])){
				$data = $this->scope[$data];
				return;
			}
			$instance = $data;
			$this->scope[$instance] = $data = clone($data);

			foreach ($data as &$value){
				$this->mapByReference($value);
			}
			unset($value);
		} elseif (is_object($data) && !$data instanceof SerializableClosure){
			if(isset($this->scope[$data])){
				$data = $this->scope[$data];
				return;
			}

			$instance = $data;
			$reflection = new ReflectionObject($data);
			$this->scope[$instance] = $data = $reflection->newInstanceWithoutConstructor();
			foreach ($reflection->getProperties() as $property){
				if($property->isStatic()){
					continue;
				}
				$property->setAccessible(true);
				$value = $property->getValue($instance);
				if(is_array($value) || is_object($value)){
					$this->mapByReference($value);
				}
				$property->setValue($data, $value);
			}
		}
	}
}
/**
*
*/
class task
{

	private static $bin='php';

	static function run(closure $pretask,$logfile='/dev/null',$timeout=3600)
	{
		try
		{
			if((!(defined('STDIN')&&defined('STDOUT')&&defined('STDERR')))&&(!function_exists('fastcgi_finish_request')))
			{
				ob_start();
			}
			$task=$pretask();
			if($task && $task instanceof closure)
			{
				$wrapper = new SerializableClosure($task);
				$serialized = serialize($wrapper);
				self::process($serialized,$timeout,$logfile);
			}
		}
		catch(Exception $e)
		{
			exit($e->getMessage());
		}
	}

	static function process($serialized,$timeout=3600,$logfile='/dev/null')
	{
		$timeLimit=($timeout&&is_int($timeout))?"set_time_limit($timeout);":'';
		$runner=sprintf("%srequire '%s';task::call(\\\$argv);",$timeLimit,__FILE__);
		return self::popen($runner,base64_encode($serialized),$logfile);
	}

	static function popen($script,$args,$logfile='/dev/null')
	{
		$shell=sprintf('%s -r "%s" %s >%s 2>&1 &',self::$bin,$script,$args,$logfile);
		if(defined('STDIN')&&defined('STDOUT')&&defined('STDERR'))
		{
			pclose(popen($shell,'w'));
		}
		else if(function_exists('fastcgi_finish_request'))
		{
			fastcgi_finish_request();
			pclose(popen($shell,'w'));
		}
		else //php build in server
		{
			pclose(popen($shell,'w'));
			$len=ob_get_length();
			if($len)
			{
				header("Content-Length: ".$len);
				while (ob_get_level() > 0) {ob_end_flush();}
			}
			else
			{
				header("Content-Length: 0");
			}
		}
	}

	static function call($argv)
	{
		try
		{
			if(!empty($argv[1]))
			{
				$task=base64_decode($argv[1]);
				if($task)
				{
					$task=unserialize($task);
					if(is_callable($task))
					{
						$task();
					}
				}
			}
		}
		catch(Exception $e)
		{
			exit($e->getMessage());
		}
	}

	static function bin($binPath)
	{
		self::$bin=$binPath;
	}
}

