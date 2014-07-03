<?php

/**
 * Consoler
 * Sinatra-like application builder for the console
 *
 * @author justim
 **/
class Consoler
{
	// option attributes
	const OPTION_ORIGINAL = 'original';
	const OPTION_LONG     = 'long';
	const OPTION_SHORT    = 'short';
	const OPTION_ARGUMENT = 'argument';
	const OPTION_VALUE    = 'value';
	const OPTION_OPTIONAL = 'optional';
	const OPTION_FILTER   = 'filter';
	const OPTION_ALIAS    = 'alias';
	const OPTION_ALIASES  = 'aliases';

	// parsed option attributes
	const ARGUMENT_TYPE    = 'type';
	const ARGUMENT_NAME    = 'name';
	const ARGUMENT_VALUE   = 'value';
	const ARGUMENT_OPTIONS = 'options';

	// parsed option attribute values
	const ARGUMENT_TYPE_MULTIPLE = 'multiple';
	const ARGUMENT_TYPE_SINGLE   = 'single';
	const ARGUMENT_TYPE_VALUE    = 'value';
	const ARGUMENT_TYPE_ARGUMENT = 'argument';

	// default values for empty optionals
	const DEFAULTS_VALUE = null;
	const DEFAULTS_SHORT = 0;
	const DEFAULTS_LONG  = false;

	// list of available commands
	private $_commands = [];

	// should we handle exception internally (used for testing)
	private $_handleExceptions = true;

	// when handling exception, should we exit after the error/usage message
	private $_exitOnFailure = true;

	// streams used for in and output
	private $_streams = [
		'in'  => STDIN,
		'out' => STDOUT,
		'err' => STDERR,
	];

	// available filters
	private $_filters = [];

	public function __construct()
	{
		$this->_filters = [
			'file' => [$this, '_isFile'],
			'dir'  => [$this, '_isDirectory'],
		];
	}

	/**
	 * Set whether we should handle expections internally (useful for testing)
	 * @param boolean
	 */
	public function setHandleExceptions($flag)
	{
		$this->_handleExceptions = (bool) $flag;

		return $this;
	}

	public function setExitOnFailure($flag)
	{
		$this->_exitOnFailure = (bool) $flag;

		return $this;
	}

	public function setStreams(array $streams)
	{
		foreach ($streams as $type => $stream)
		{
			$this->setStream($type, $stream);
		}

		return $this;
	}

	public function setStream($type, $stream)
	{
		if (is_resource($stream) || is_callable($stream))
		{
			$this->_streams[$type] = $stream;
		}
		else
		{
			throw new \InvalidArgumentException('Invalid stream for "' . $type . '"');
		}

		return $this;
	}

	public function __call($command, $arguments)
	{
		$action = array_pop($arguments);
		if (is_object($action) && is_callable([$action, '__consolerInvoke']))
		{
			$action = [$action, '__consolerInvoke'];
		}
		else if (is_callable($action) === false)
		{
			throw new \BadMethodCallException('Action needs to be specified');
		}

		$options = (string) current($arguments);

		return $this->_addCommand($command, $options, $action);
	}

	public function __invoke()
	{
		return $this->__call(null, func_get_args());
	}

	public function run($args = null)
	{
		if ($args === null)
		{
			$args = $_SERVER['argv'];
			array_shift($args); // remove script name
		}

		try
		{
			$result = $this->_run($args);

			if (!$result)
			{
				$this->usage();
			}
		}
		catch (\Exception $e)
		{
			if ($this->_handleExceptions)
			{
				$error = $this->_createErrorHelper();
				$error($e->getMessage());
				$this->usage();

				// exit helper is impossible to test, it will crash phpunit
				// @codeCoverageIgnoreStart
				if ($this->_exitOnFailure)
				{
					$exit = $this->_createExitHelper();
					$exit();
				}
				// @codeCoverageIgnoreEnd

				$result = false;
			}
			else
			{
				throw $e;

			// brace will never ever be reached
			// @codeCoverageIgnoreStart
			}
			// @codeCoverageIgnoreEnd
		}

		return $result;
	}

	private function _optionAsString($options, $currentOptions)
	{
		$option = '';

		if ($currentOptions[self::OPTION_LONG])
		{
			$option .= '--';
		}

		if ($currentOptions[self::OPTION_SHORT])
		{
			$option .= '-';
		}

		$option .= $currentOptions[self::OPTION_ORIGINAL];

		if (isset($currentOptions[self::OPTION_ALIASES]))
		{
			foreach ($currentOptions[self::OPTION_ALIASES] as $alias)
			{
				$option .= '|' . $this->_optionAsString($options, $options[$alias]);
			}
		}

		if ($currentOptions[self::OPTION_VALUE])
		{
			$option .= '=';
		}

		return $option;
	}

	public function usage()
	{
		$error = $this->_createErrorHelper();
		$lines = ['Usage:'];

		foreach ($this->_commands as $command)
		{
			$line = '';

			if (!empty($command['command']))
			{
				$line .= ' ' . $command['command'];
			}

			$i = 0;
			$optional = null;

			$filteredOptions = array_values(array_filter($command['options'], function($item)
			{
				return !isset($item[self::OPTION_ALIAS]);
			}));

			foreach ($filteredOptions as $name => $options)
			{
				$line .= ' ';

				if (!$optional && $options[self::OPTION_OPTIONAL])
				{
					$optional = $options['optional'];
					$line .= '[';
				}

				$line .= $this->_optionAsString($command['options'], $options);

				if ($options[self::OPTION_OPTIONAL])
				{
					if (!isset($filteredOptions[$i + 1]) || $filteredOptions[$i + 1][self::OPTION_OPTIONAL] !== $optional)
					{
						$line .= ']';
						$optional = null;
					}
				}

				$i++;
			}

			if (!empty($command['description']))
			{
				$line .= ' -- ' . $command['description'];
			}

			$lines[] = $line;
		}

		$error(implode("\n", $lines));
	}

	private function _run($args, array $rawOptions = [])
	{
		$argsWithoutCommand = array_slice($args, 1);

		// last first
		foreach (array_reverse($this->_commands) as $command)
		{
			if ($command['command'] === null || isset($args[0]) && $command['command'] === $args[0])
			{
				$commandArgs = $command['command'] === null ? $args : $argsWithoutCommand;
				$matchedOptions = $this->_matchCommandArguments($commandArgs, $command['options']);

				if ($matchedOptions !== null)
				{
					foreach ($command['options'] as $optionName => $option)
					{
						if ($option[self::OPTION_FILTER])
						{
							$this->_filters[$option[self::OPTION_FILTER]]($matchedOptions[$optionName]);
						}
					}

					$this->_dispatch($command['action'], $commandArgs, $matchedOptions);
					return true;
				}
			}
		}

		return false;
	}

	private function _dispatch(Callable $callback, $args, $options)
	{
		$reflection = $this->_createReflectionClass($callback);
		$functionParameters = $reflection->getParameters();

		$parameterHelper = $this->_createParameterHelper([$options]);

		$args = [];
		$i = 0;
		foreach ($functionParameters as $parameter)
		{
			$name = strtolower($parameter->getName());

			switch ($name)
			{
				case 'print':    $args[$i] = $this->_createPrintHelper();    break;
				case 'error':    $args[$i] = $this->_createErrorHelper();    break;
				case 'ask':      $args[$i] = $this->_createAskHelper();      break;
				case 'confirm':  $args[$i] = $this->_createConfirmHelper();  break;
				case 'password': $args[$i] = $this->_createPasswordHelper(); break;
				case 'file':     $args[$i] = $this->_createFileHelper();     break;
				case 'exit':     $args[$i] = $this->_createExitHelper();     break;
				case 'table':    $args[$i] = $this->_createTableHelper();    break;
				default:         $args[$i] = $parameterHelper($name);        break;
			}

			++$i;
		}

		return call_user_func_array($callback, $args);
	}

	private function _addCommand($command, $options, Callable $action)
	{
		$tokenizedOptions = $this->_tokenizeOptions($options);

		$this->_commands[] = [
			'command' => $command,
			'options' => $tokenizedOptions['options'],
			'description' => $tokenizedOptions['description'],
			'action' => $action,
		];

		return $this;
	}

	/**
	 * Generate options based on our string format
	 * @param string format of options/arguments
	 *  -a          - short option
	 *  -zed        - multiple short options
	 *  --bear      - long option
	 *  run         - argument
	 *  -g=         - = indicates it should be followed by a value
	 *  [--magic]   - with square brackets its optional
	 *
	 * ex.: run -a -zed --noop
	 *
	 * @return list with option definitions
	 *       [ 'name' => [
	 *            'long' => bool,         // long option
	 *            'short' => bool,        // short option
	 *            'argument' => bool,     // option is a argument
	 *            'value' => bool,        // value is expected
	 *            'optional' => int|null, // optional
	 *            'filter' => string      // filter index
	 *            ['alias' => bool,]      // alias
	 *            ['aliases' => [],]      // list of aliases
	 *        ], ... ]
	 */
	private function _tokenizeOptions($rawOptions)
	{
		$options = $rawOptions;
		$description = null;
		$tokenizedOptions = [];

		if (preg_match('/(^| )-- (?<description>.*)$/', $options, $matches))
		{
			$description = $matches['description'];
			$options = substr($options, 0, -strlen($matches[0]));
		}

		$options = array_filter(array_map('trim', explode(' ', $options)));
		$optionalTracker = ['tracking' => false, 'index' => 0];

		while ($option = array_shift($options))
		{
			$tokenizedOption = $this->_tokenizeOption($option, $optionalTracker);

			if ($tokenizedOption['option'][self::OPTION_SHORT] && strlen($tokenizedOption['name']) > 1)
			{
				foreach (str_split($tokenizedOption['name']) as $shortOptionName)
				{
					array_unshift($options, '-' . $shortOptionName . ($tokenizedOption['option'][self::OPTION_VALUE] ? '=' : ''));
				}
			}
			else
			{
				$tokenizedOptions = $this->_mergeTokenizedOptions($tokenizedOptions, $tokenizedOption);
			}
		}

		if ($optionalTracker['tracking'])
		{
			throw new \InvalidArgumentException('Missing closing optional token');
		}

		return ['description' => $description, 'options' => $tokenizedOptions];
	}

	private function _mergeTokenizedOptions($tokenizedOptions, $tokenizedOption)
	{
		$aliases = [];

		foreach ($tokenizedOption['aliases'] as $alias => $aliasOptions)
		{
			if (isset($tokenizedOptions[$aliasOptions['name']]))
			{
				throw new \InvalidArgumentException('Duplicate option');
			}

			$aliases[] = $aliasOptions['name'];
			$aliasOptions['option'][self::OPTION_ALIAS] = $tokenizedOption['name'];
			$tokenizedOptions[$aliasOptions['name']] = $aliasOptions['option'];
		}

		if (isset($tokenizedOptions[$tokenizedOption['name']]))
		{
			throw new \InvalidArgumentException('Duplicate option');
		}

		$tokenizedOption['option'][self::OPTION_ALIASES] = $aliases;
		$tokenizedOptions[$tokenizedOption['name']] = $tokenizedOption['option'];

		return $tokenizedOptions;
	}

	private function _tokenizeOption($rawOption, &$optionalTracker)
	{
		list($option, $optional) = $this->_tokenizeOptionOptional($rawOption, $optionalTracker);
		list($option, $long)     = $this->_tokenizeOptionLong($option);
		list($option, $short)    = $this->_tokenizeOptionShort($option);
		list($option, $value)    = $this->_tokenizeOptionValue($option, $long, $short);
		list($option, $filter)   = $this->_tokenizeOptionFilter($option, $long, $short);
		list($option, $aliases)  = $this->_tokenizeOptionAliases($option);

		if (empty($option))
		{
			throw new \InvalidArgumentException('Option must have a name');
		}

		if ($long && $short)
		{
			throw new \InvalidArgumentException('Option can not be a long and a short option');
		}

		if ($short && strlen($option) > 1 && !empty($aliases))
		{
			throw new \InvalidArgumentException('No aliases allowed for multi option');
		}

		if (!$short && !$long && !empty($aliases))
		{
			throw new \InvalidArgumentException('Aliases only allowed for options');
		}

		// options can have weird values, variable don't
		// it might cause some troubles when keys become the same..
		$normalizedName = $this->_normalizedOptionName($option);

		return [
			'name' => $normalizedName,
			'option' => [
				self::OPTION_ORIGINAL => $option, // remaining option part is the name
				self::OPTION_LONG     => $long,
				self::OPTION_SHORT    => $short,
				self::OPTION_ARGUMENT => !$short && !$long,
				self::OPTION_VALUE    => $value,
				self::OPTION_OPTIONAL => $optional,
				self::OPTION_FILTER   => $filter,
			],
			'aliases' => $aliases,
		];
	}

	private function _tokenizeOptionOptional($rawOption, &$optionalTracker)
	{
		$option = $rawOption;

		if (substr($option, 0, 1) === '[')
		{
			if (!$optionalTracker['tracking'])
			{
				$optionalTracker['tracking'] = true;
				$optionalTracker['index']++;
				$option = substr($option, 1);
			}
			else
			{
				throw new \InvalidArgumentException('Nested optionals are not allowed');
			}
		}

		$optional = $optionalTracker['tracking'] ? $optionalTracker['index'] : null;

		if (substr($option, -1, 1) === ']')
		{
			if ($optionalTracker['tracking'])
			{
				$optionalTracker['tracking'] = false;
				$option = substr($option, 0, -1);
			}
			else
			{
				throw new \InvalidArgumentException('Unopened optional');
			}
		}

		return [$option, $optional];
	}

	private function _tokenizeOptionLong($option)
	{
		if (substr($option, 0, 2) === '--')
		{
			$long = true;
			$option = substr($option, 2);
		}
		else
		{
			$long = false;
		}

		return [$option, $long];
	}

	private function _tokenizeOptionShort($option)
	{
		if (substr($option, 0, 1) === '-')
		{
			$short = true;
			$option = substr($option, 1);
		}
		else
		{
			$short = false;
		}

		return [$option, $short];
	}

	private function _tokenizeOptionValue($option, $long, $short)
	{
		$value = false;

		if (substr($option, -1) === '=')
		{
			if (!$short && !$long)
			{
				throw new \InvalidArgumentException('Argument can\'t have a value');
			}

			$value = true;
			$option = substr($option, 0, -1);
		}

		return [$option, $value];
	}

	private function _tokenizeOptionFilter($option, $long, $short)
	{
		$filter = null;

		if (!$short && !$long && preg_match('/^(?<filter>\w+):(?<option>.*)$/', $option, $matches))
		{
			if (!isset($this->_filters[$matches['filter']]) || !is_callable($this->_filters[$matches['filter']]))
			{
				throw new \InvalidArgumentException('Invalid filter: ' . $matches['filter']);
			}

			$filter = $matches['filter'];
			$option = $matches['option'];
		}

		return [$option, $filter];
	}

	private function _tokenizeOptionAliases($option)
	{
		$aliases = explode('|', $option);
		$option = array_shift($aliases);

		$aliases = array_map(function($option)
		{
			list($option, $long)     = $this->_tokenizeOptionLong($option);
			list($option, $short)    = $this->_tokenizeOptionShort($option);

			$normalizedName = $this->_normalizedOptionName($option);

			return [
				'name' => $normalizedName,
				'option' => [
					self::OPTION_ORIGINAL => $option, // remaining option part is the name
					self::OPTION_LONG     => $long,
					self::OPTION_SHORT    => $short,

					// we don't care because it's an alias
					self::OPTION_ARGUMENT => false,
					self::OPTION_VALUE    => false,
					self::OPTION_OPTIONAL => null,
					self::OPTION_FILTER   => null,
				],
			];
		}, $aliases);

		return [$option, $aliases];
	}

	/**
	 * Parse command arguments against a list of possible options
	 * @param array raw arguments
	 * @param array generated options
	 */
	private function _matchCommandArguments($args, $options)
	{
		$parseOptions = true;
		$matchedOptions = [];
		$argumentValues = [];

		while ($arg = array_shift($args))
		{
			if ($parseOptions)
			{
				if ($arg === '--')
				{
					$parseOptions = false;
					continue;
				}

				$analyzed = $this->_analyzeArgvValue($options, $matchedOptions, $arg, $args);

				if ($analyzed === null)
				{
					return null;
				}
				else if ($analyzed[self::ARGUMENT_TYPE] === self::ARGUMENT_TYPE_MULTIPLE)
				{
					foreach ($analyzed[self::ARGUMENT_OPTIONS] as $optionsMultiple)
					{
						$matchedOptions[$optionsMultiple[self::ARGUMENT_NAME]] = $optionsMultiple[self::ARGUMENT_VALUE];
					}
				}
				else if ($analyzed[self::ARGUMENT_TYPE] === self::ARGUMENT_TYPE_ARGUMENT)
				{
					$argumentValues[] = $analyzed[self::ARGUMENT_VALUE];
				}
				else
				{
					$matchedOptions[$analyzed[self::ARGUMENT_NAME]] = $analyzed[self::ARGUMENT_VALUE];
				}

				if ($analyzed[self::ARGUMENT_TYPE] === self::ARGUMENT_TYPE_VALUE)
				{
					array_shift($args);
				}
			}
			else
			{
				$argumentValues[] = $arg;
			}
		}

		list($matchedArguments, $remainingArguments) = $this->_matchArguments($options, $argumentValues);
		$matchedOptions += $matchedArguments;

		// give optional options a default value
		$matchedOptions = $this->_fillDefaults($matchedOptions, $options);

		// we need to match all expected options
		if ($matchedOptions !== null && count($matchedOptions) === count($options))
		{
			$matchedOptions['remaining'] = $remainingArguments;
			return $matchedOptions;
		}
		else
		{
			return null;
		}
	}

	private function _analyzeArgvValue($options, $matchOptions, $arg, $args)
	{
		$long = false;
		$short = false;
		$name = null;

		if (strpos($arg, '--') === 0)
		{
			$long = true;
			$name = substr($arg, 2);
		}
		else if (strpos($arg, '-') === 0)
		{
			$short = true;
			$name = substr($arg, 1);
		}

		if ($name !== null)
		{
			$normalizedName = $this->_normalizedOptionName($name);

			if (isset($options[$normalizedName][self::OPTION_ALIAS]))
			{
				$name = $options[$normalizedName][self::OPTION_ALIAS];

				$long = $options[$name][self::OPTION_LONG];
				$short = $options[$name][self::OPTION_SHORT];
			}
		}

		if ($long)
		{
			return $this->_analyzeArgvValueLong($options, $name, $args);
		}
		else if ($short)
		{
			return $this->_analyzeArgvValueShort($options, $matchOptions, $name, $args);
		}
		else
		{
			return [
				self::ARGUMENT_TYPE  => self::ARGUMENT_TYPE_ARGUMENT,
				self::ARGUMENT_NAME  => null,
				self::ARGUMENT_VALUE => $arg,
			];
		}
	}

	private function _analyzeArgvValueLong($options, $rawArgName, $args)
	{
		$normalizedArgName = $this->_normalizedOptionName($rawArgName);
		$result = null;

		if (isset($options[$normalizedArgName]) &&
			$options[$normalizedArgName][self::OPTION_ORIGINAL] === $rawArgName &&
			$options[$normalizedArgName][self::OPTION_LONG])
		{
			if ($options[$normalizedArgName][self::OPTION_VALUE])
			{
				if (isset($args[0]))
				{
					$result = [
						self::ARGUMENT_TYPE  => self::ARGUMENT_TYPE_VALUE,
						self::ARGUMENT_NAME  => $normalizedArgName,
						self::ARGUMENT_VALUE => $args[0],
					];
				}
			}
			else
			{
				$result = [
					self::ARGUMENT_TYPE  => self::ARGUMENT_TYPE_SINGLE,
					self::ARGUMENT_NAME  => $normalizedArgName,
					self::ARGUMENT_VALUE => true,
				];
			}
		}

		return $result;
	}

	private function _analyzeArgvValueShort($options, $matchOptions, $rawFullArg, $args)
	{
		$normalizedFullArg = $this->_normalizedOptionName($rawFullArg);
		$result = null;

		if (strlen($normalizedFullArg) === 1 &&
			isset($options[$normalizedFullArg]) &&
			$options[$normalizedFullArg][self::OPTION_ORIGINAL] === $rawFullArg &&
		    $options[$normalizedFullArg][self::OPTION_SHORT] &&
			$options[$normalizedFullArg][self::OPTION_VALUE])
		{
			if (isset($args[0]))
			{
				$result = [
					self::ARGUMENT_TYPE  => self::ARGUMENT_TYPE_VALUE,
					self::ARGUMENT_NAME  => $normalizedFullArg,
					self::ARGUMENT_VALUE => $args[0],
				];
			}
		}
		else if (strlen($normalizedFullArg) >= 1)
		{
			$mergeMatchOptions = [];

			foreach (str_split($normalizedFullArg) as $argShort)
			{
				if (isset($options[$argShort]) &&
					$options[$argShort][self::OPTION_SHORT])
				{
					if (!isset($mergeMatchOptions[$argShort]))
					{
						$mergeMatchOptions[$argShort] = [
							self::ARGUMENT_TYPE  => self::ARGUMENT_TYPE_SINGLE,
							self::ARGUMENT_NAME  => $argShort,
							self::ARGUMENT_VALUE => (isset($matchOptions[$argShort]) ? $matchOptions[$argShort] : 0) + 1,
						];
					}
					else
					{
						$mergeMatchOptions[$argShort][self::ARGUMENT_VALUE] += 1;
					}
				}
			}

			if (!empty($mergeMatchOptions))
			{
				$result = [
					self::ARGUMENT_TYPE    => self::ARGUMENT_TYPE_MULTIPLE,
					self::ARGUMENT_OPTIONS => $mergeMatchOptions,
				];
			}
		}

		return $result;
	}

	private function _matchArguments($options, $argumentValues)
	{
		$matchedArguments = [];

		list($optionalsBefore, $optionalsBeforeTracker) =
			$this->_matchArgumentsOptionalsBefore($options, $argumentValues);

		foreach ($optionalsBefore as $mandatoryArgName => $optionals)
		{
			foreach ($optionals as $optional)
			{
				foreach ($optional as $g)
				{
					if ($g['include'])
					{
						$matchedArguments[$g['name']] = array_shift($argumentValues);
					}
				}
			}

			$matchedArguments[$mandatoryArgName] = array_shift($argumentValues);
		}

		foreach ($optionalsBeforeTracker as $p)
		{
			foreach ($p as $o)
			{
				$value = array_shift($argumentValues);

				if ($value === null)
				{
					break 2;
				}
				else
				{
					$matchedArguments[$o['name']] = $value;
				}
			}
		}

		return [$matchedArguments, $argumentValues];
	}

	private function _matchArgumentsOptionalsBefore($options, $argumentValues)
	{
		$optionalsBefore = [];
		$optionalsBeforeTracker = [];

		foreach ($options as $name => $option)
		{
			if ($option[self::OPTION_ARGUMENT])
			{
				if ($option[self::OPTION_OPTIONAL])
				{
					if (!isset($optionalsBeforeTracker[$option[self::OPTION_OPTIONAL]]))
					{
						$optionalsBeforeTracker[$option[self::OPTION_OPTIONAL]] = [];
					}
				
					$optionalsBeforeTracker[$option[self::OPTION_OPTIONAL]][] = ['include' => false, 'name' => $name];
				}
				else
				{
					$optionalsBefore[$name] = $optionalsBeforeTracker;
					$optionalsBeforeTracker = [];
				}
			}
		}

		$optionalsBefore = $this->_matchArgumentsOptionalsBeforeMatcher($optionalsBefore, $argumentValues);
		return [$optionalsBefore, $optionalsBeforeTracker];
	}

	private function _matchArgumentsOptionalsBeforeMatcher($optionalsBefore, $argumentValues)
	{
		$mandatoriesMatched = count($optionalsBefore);
		$total = 0;

		foreach ($optionalsBefore as &$optionals)
		{
			uksort($optionals, function($l, $r) { return count($r) - count($l); });
			
			foreach ($optionals as &$before)
			{
				if (($total + count($before) + $mandatoriesMatched) <= count($argumentValues))
				{
					$total += count($before);

					foreach ($before as &$val)
					{
						$val['include'] = true;
					}
				}
			}

			ksort($optionals);
		}

		return $optionalsBefore;
	}

	/**
	 * Fill all remaining options with their defaults, makes sure the grouped optional are really grouped
	 * @param array list of the currently matched options
	 * @param array config for this command
	 * @return array|null list of matched options filled with options, or null of failure
	 */
	private function _fillDefaults($matchedOptions, $options)
	{
		foreach ($options as $name => $option)
		{
			if (isset($option[self::OPTION_ALIAS]))
			{
				continue;
			}
			else if ($option[self::OPTION_OPTIONAL])
			{
				if (!isset($matchedOptions[$name]))
				{
					if ($option[self::OPTION_VALUE] || $option[self::OPTION_ARGUMENT])
					{
						$matchedOptions[$name] = self::DEFAULTS_VALUE;
					}
					else if ($option[self::OPTION_SHORT])
					{
						$matchedOptions[$name] = self::DEFAULTS_SHORT;
					}
					else
					{
						$matchedOptions[$name] = self::DEFAULTS_LONG;
					}

					$matchedOptions = $this->_fillAliases($matchedOptions, $name, $option, $options);
				}
				else
				{
					$matchedOptions = $this->_fillAliases($matchedOptions, $name, $option, $options);

					foreach ($options as $optionName => $optionOptions)
					{
						if ($optionOptions[self::OPTION_OPTIONAL] === $option[self::OPTION_OPTIONAL])
						{
							if (!isset($matchedOptions[$optionName]))
							{
								// when grouping optionals, its all or nothing
								return null;
							}
						}
					}
				}
			}
			else
			{
				$matchedOptions = $this->_fillAliases($matchedOptions, $name, $option, $options);
			}
		}

		return $matchedOptions;
	}

	private function _fillAliases($matchedOptions, $name, $option, $options)
	{
		if (isset($option[self::OPTION_ALIASES]))
		{
			foreach ($option[self::OPTION_ALIASES] as $alias)
			{
				if (array_key_exists($name, $matchedOptions))
				{
					$matchedOptions[$alias] = $matchedOptions[$name];
				}
			}
		}

		return $matchedOptions;
	}

	/**
	 * Print to a stream
	 * @param resource|Callable
	 * @param string the content
	 * @param [mixed] optional extra parameters
	 */
	private function _streamOut($stream, $content)
	{
		$args = array_slice(func_get_args(), 1);

		if (is_callable($stream))
		{
			call_user_func_array($stream, $args);
		}
		else
		{
			array_unshift($args, $stream);
			call_user_func_array('fprintf', $args);
		}
	}

	/**
	 * Get the contents of the incoming stream
	 * @return string
	 */
	private function _streamIn()
	{
		if (is_callable($this->_streams['in']))
		{
			return $this->_streams['in']();
		}
		else
		{
			return preg_replace('/(\r|\r\n|\n)$/', '', fgets($this->_streams['in']));
		}
	}

	/**
	 * Create a helper to print a message to a resource
	 * @param resource ex. STDOUT
	 */
	private function _createResourcePrinterHelper($resource)
	{
		return function($text, $overwritable = false) use ($resource)
		{
			$text .= $overwritable ? "\x0D" : "\n";
			$this->_streamOut($resource, $text);
			return true;
		};
	}

	/**
	 * Create a helper to print a message to STDOUT
	 */
	private function _createPrintHelper()
	{
		return $this->_createResourcePrinterHelper($this->_streams['out']);
	}

	/**
	 * Create a helper to print a message to STDERR
	 */
	private function _createErrorHelper()
	{
		return $this->_createResourcePrinterHelper($this->_streams['err']);
	}

	/**
	 * Create a helper to ask for input
	 */
	private function _createAskHelper()
	{
		return function($label)
		{
			$this->_streamOut($this->_streams['out'], $label);
			return $this->_streamIn();
		};
	}

	/**
	 * Create a helper to confirm something by answer with `y` or `n`
	 */
	private function _createConfirmHelper()
	{
		return function($label, $default = null)
		{
			$ask = $this->_createAskHelper();

			$yes = strcasecmp($default, 'y') === 0 ? 'Y' : 'y';
			$no = strcasecmp($default, 'n') === 0 ? 'N' : 'n';
			$label = rtrim($label) . ' [' . $yes . '/' . $no . '] ';

			do
			{
				$response = strtolower($ask($label));

				if (empty($response) && $default !== null)
				{
					$response = $default === 'y' ? 'y' : 'n';
				}
			}
			while ($response !== 'y' && $response !== 'n');

			return $response === 'y';
		};
	}

	/**
	 * Create a helper to ask for a password, password will stay hidden
	 *
	 * @codeCoverageIgnore - password helper is impossible to test, due to its dependency of a real shell
	 */
	private function _createPasswordHelper()
	{
		return function($label)
		{
			$sttyMode = shell_exec('stty -g');
			shell_exec('stty -echo');

			$this->_streamOut($this->_streams['err'], $label);
			$password = $this->_streamIn();
			$this->_streamOut($this->_streams['err'], "\n");

			shell_exec(sprintf('stty %s', $sttyMode));

			return $password;
		};
	}

	/**
	 * Create a helper to keep asking for a filename, until a existing file is given
	 */
	private function _createFileHelper()
	{
		return function($label, $errorMessage = 'Invalid file')
		{
			$ask = $this->_createAskHelper();
			$error = $this->_createErrorHelper();

			do
			{
				$filename = $ask($label);
			}
			while (!file_exists(getcwd() . '/' . $filename) && $error($errorMessage));

			return $filename;
		};
	}

	/**
	 * Create a helper to terminate your app, with an optional error message
	 * - with error message the status code is 1, without it its 0
	 *
	 * @codeCoverageIgnore - exit helper is impossible to test, it will crash phpunit
	 */
	private function _createExitHelper()
	{
		return function($errorMessage = null)
		{
			if ($errorMessage === null)
			{
				exit;
			}
			else if (is_int($errorMessage))
			{
				exit($errorMessage);
			}
			else
			{
				$error = $this->_createErrorHelper();
				$error($errorMessage);
				exit(1);
			}
		};
	}

	private function _createTableHelper()
	{
		return function($data)
		{
			if (count(call_user_func_array('array_diff', array_map('array_keys', $data))) > 0)
			{
				throw new \InvalidArgumentException('Table helper: Keys should be the same on all rows');
			}

			$output = $this->_createPrintHelper();

			$tableInfo = [];
			foreach ($data as $record)
			{
				foreach ($record as $name => $value)
				{
					$tableInfo[$name] = [
						'numeric' => (@$tableInfo[$name]['width'] ?: true) && is_numeric($value),
						'width' => max(@$tableInfo[$name]['width'] ?: 0, strlen($value)),
					];
				}
			}

			$row = function($in, $option = 'row') use ($tableInfo, $output)
			{
				$line = '|';

				foreach ($in as $name => $value)
				{
					$line .= ' ' . str_pad(
						$value,
						$tableInfo[$name]['width'],
						' ',
						$tableInfo[$name]['numeric'] && $option === 'row' ? STR_PAD_LEFT : STR_PAD_RIGHT
						) . ' |';
				}

				$output($line);

				if ($option === 'underline')
				{
					$line = '+';

					foreach ($tableInfo as $name => $info)
					{
						$line .= str_repeat('-', $info['width'] + 2) . '+';
					}

					$output($line);
				}
			};

			$row(array_combine(array_keys($tableInfo), array_keys($tableInfo)), 'underline');

			foreach ($data as $record)
			{
				$row($record);
			}
		};
	}

	/**
	 * Helper function to create a reflection(method|function) class
	 * @param Callable
	 */
	private function _createReflectionClass(Callable $callback)
	{
		if (is_array($callback))
		{
			return new ReflectionMethod($callback[0], $callback[1]);
		}
		else if (is_string($callback) && strpos($callback, '::') !== false)
		{
			list($class, $method) = explode('::', $callback);
			return new ReflectionMethod($class, $method);
		}
		else if (method_exists($callback, '__invoke'))
		{
			return new ReflectionMethod($callback, '__invoke');
		}
		else
		{
			return new ReflectionFunction($callback);
		}
	}

	/**
	 * Create a helper for a list of sources to get their value and a default
	 */
	private function _createParameterHelper(array $sources)
	{
		$combinedSources = [];

		// combine the sources
		foreach ($sources as $source)
		{
			$combinedSources += $source;
		}

		return function($name = null, $default = null) use ($combinedSources)
		{
			if (array_key_exists($name, $combinedSources))
			{
				return $combinedSources[$name];
			}
			else
			{
				return $default;
			}
		};
	}

	private function _isFile($value)
	{
		if (!file_exists(getcwd() . '/' . $value))
		{
			throw new \InvalidArgumentException('File does not exist: ' . $value);
		}

		if (!is_file(getcwd() . '/' . $value))
		{
			throw new \InvalidArgumentException('Invalid file: ' . $value);
		}
	}

	private function _isDirectory($value)
	{
		if (!file_exists(getcwd() . '/' . $value))
		{
			throw new \InvalidArgumentException('Directory does not exist: ' . $value);
		}

		if (!is_dir(getcwd() . '/' . $value))
		{
			throw new \InvalidArgumentException('Invalid directory: ' . $value);
		}
	}

	private function _normalizedOptionName($optionName)
	{
		return preg_replace('/(^[^a-z_]|[^a-z0-9_])*/i', '', $optionName);
	}
}
