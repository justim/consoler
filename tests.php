<?php

require 'vendor/autoload.php';

// used in one of the tests
class CustomClass
{
	public function __consolerInvoke()
	{
	}
}

class Test
{
	const DESIRED_FILENAME = 'sherlock.mp4';
	const DESIRED_WAIT = 'now';
	const DESIRED_WHAT = '42';

	private $_currentMethod;
	private $_currentMethodOutput;
	private $_currentMethodError;
	private $_currentMethodInput;
	private $_messages = [];
	private $_showAll;

	public function __consolerInvoke($v)
	{
		$this->_showAll = (bool) $v;

		$reflection = new ReflectionClass(get_class($this));
		$methods = /*array_reverse*/($reflection->getMethods());
		foreach ($methods as $method)
		{
			if (strpos($method->getName(), 'test') === 0)
			{
				$this->_currentMethod = $method->getName();
				$this->_currentMethodOutput = '';
				$this->_currentMethodError = '';
				$this->_currentMethodInput = '';

				// create an app without output
				$app = (new Consoler)
					->setStreams([
						'out' => function($content)
						{
							$this->_currentMethodOutput .= $content;
						},
						'err' => function($content)
						{
							$this->_currentMethodError .= $content;
						},
						'in' => function()
						{
							return $this->_currentMethodInput;
						},
					])
					->setHandleExceptions(false);

				$runner = $this->_createRunner($method, $app);

				$this->_messages[$this->_currentMethod]['success'] = $runner();

				$this->printResult($method->getName());
			}
		}

		$this->printResults();
		return $this;
	}

	private function _createRunner(ReflectionMethod $method, Consoler $app)
	{
		$parameters = $method->getParameters();
		$option = isset($parameters[1]) ? $parameters[1]->getName() : null;

		return function() use ($method, $app, $option)
		{
			try
			{
				$args = $this->{$method->getName()}($app);
				$result = $app->run($args);

				if ($option === 'nomatch' && $result)
				{
					$this->assert(false, 'Expected app not to match, but it did');
				}
				else if ($option !== 'nomatch' && !$result)
				{
					$this->assert(false, 'Expected app to match, but it didn\'t');
				}

				if ($option === 'throws')
				{
					$this->assert(false, 'Exception expected, but not thrown');
				}
			}
			catch (Exception $e)
			{
				if ($option !== 'throws')
				{
					$this->assert(false, 'No exception expected, but was thrown anyway');
				}
			}

			return empty($this->_messages[$this->_currentMethod]);
		};
	}

	public function getResults()
	{
		return $this->_messages;
	}

	private function printResult($method)
	{
		if (!empty($this->_messages[$method]['success']))
		{
			if ($this->_showAll)
			{
				fprintf(STDOUT, "[\033[0;32m√\033[0m] $method\n");
			}
		}
		else
		{
			fprintf(STDOUT, "[\033[0;31m✘\033[0m] $method\n");

			foreach ($this->_messages[$method]['errors'] as $message)
			{
				fprintf(STDOUT, "    - $message\n");
			}
		}
	}

	public function printResults()
	{
		$total = 0;
		$failed = 0;

		foreach ($this->_messages as $method => $messages)
		{
			$total++;

			if (!empty($messages['errors']))
			{
				$failed++;
			}
		}

		if ($failed > 0)
		{
			fprintf(STDOUT, "Total: %d; failure: \033[0;31m%d\033[0m; success: %d\n", $total, $failed, $total - $failed);
			exit(1);
		}
		else if ($this->_showAll)
		{
			fprintf(STDOUT, "\033[0;32mTotal: %d; failure: %d; success: %d\033[0m\n", $total, $failed, $total - $failed);
			exit(0);
		}		
	}

	private function assert($bool, $message = null)
	{
		if (!$bool)
		{
			$this->_messages[$this->_currentMethod]['errors'][] = $message ?: ('Failure on ' . $this->_currentMethod);
		}
	}

	private function assertEquals($one, $two)
	{
		$this->assert($one === $two, 'Expected: ' . $this->_showVar($one) . '; got: ' . $this->_showVar($two));
	}

	private function assertTrue($one)
	{
		$this->assertEquals(true, $one);
	}

	private function assertFalse($one)
	{
		$this->assertEquals(false, $one);
	}

	private function assertNull($one)
	{
		$this->assertEquals(null, $one);
	}

	private function assertOutput($one)
	{
		$this->assertEquals($this->_currentMethodOutput, $one);
	}

	private function assertError($one)
	{
		$this->assertEquals($this->_currentMethodError, $one);
	}

	private function assertInput($one)
	{
		$this->assertEquals($this->_currentMethodInput, $one);
	}

	private function setInput($input)
	{
		$this->_currentMethodInput = $input;
	}

	private function _showVar($var)
	{
		$rs = '(' . gettype($var) . ') "';

		if ($var === true)
		{
			$rs .= 'true';
		}
		else if ($var === false)
		{
			$rs .= 'false';
		}
		else if ($var === null)
		{
			$rs .= 'null';
		}
		else
		{
			$rs .= str_replace("\n", '\n', $var);
		}

		return $rs . '"';
	}

	public function testCommand(Consoler $app)
	{
		$app->download(function(){});

		return ['download'];
	}

	public function testCommandFalse(Consoler $app, $nomatch = true)
	{
		$app->download(function(){});

		return ['download-foo'];
	}

	public function testNoCommand(Consoler $app)
	{
		$app(function(){});

		return [];
	}

	public function testNoCommandArgument(Consoler $app)
	{
		$app('filename', function($filename)
		{
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
		});

		return [self::DESIRED_FILENAME];
	}

	public function testCommandOptionalArgumentYes(Consoler $app)
	{
		$app->download('[filename]', function($filename)
		{
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
		});

		return ['download', self::DESIRED_FILENAME];
	}

	public function testCommandOptionalArgumentNo(Consoler $app)
	{
		$app->download('[filename]', function($filename)
		{
			$this->assertNull($filename);
		});

		return ['download'];
	}

	public function testCommandShortOption(Consoler $app)
	{
		$app->download('-f', function($f)
		{
			$this->assertEquals(1, $f);
		});

		return ['download', '-f'];
	}

	public function testCommandOptionalShortOptionYes(Consoler $app)
	{
		$app->download('[-f]', function($f)
		{
			$this->assertEquals(1, $f);
		});

		return ['download', '-f'];
	}

	public function testCommandOptionalShortOptionNo(Consoler $app)
	{
		$app->download('[-f]', function($f)
		{
			$this->assertEquals(0, $f);
		});

		return ['download'];
	}

	public function testCommandMultipleShortOptionSingle(Consoler $app)
	{
		$app->download('-f', function($f)
		{
			$this->assertEquals(2, $f);
		});

		return ['download', '-ff'];
	}

	public function testCommandMultipleShortOptionMultiple(Consoler $app)
	{
		$app->download('-f', function($f)
		{
			$this->assertEquals(2, $f);
		});

		return ['download', '-f', '-f'];
	}

	public function testCommandMultipleShortOption(Consoler $app)
	{
		$app->download('-fv', function($f, $v)
		{
			$this->assertEquals(1, $f);
			$this->assertEquals(1, $v);
		});

		return ['download', '-f', '-v'];
	}

	public function testCommandOptionalShortOptionYesArgument(Consoler $app)
	{
		$app->download('[-f] filename', function($f, $filename)
		{
			$this->assertEquals(1, $f);
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
		});

		return ['download', '-f', self::DESIRED_FILENAME];
	}

	public function testCommandOptionalShortOptionNoArgument(Consoler $app)
	{
		$app->download('[-f] filename', function($f, $filename)
		{
			$this->assertEquals(0, $f);
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
		});

		return ['download', self::DESIRED_FILENAME];
	}

	public function testShortOptionValue(Consoler $app)
	{
		$app('-n=', function($n)
		{
			$this->assertEquals('19', $n);
		});

		return ['-n', '19'];
	}

	public function testShortOptionShortOption(Consoler $app)
	{
		$app('-f -v', function($f, $v)
		{
			$this->assertEquals(1, $f);
			$this->assertEquals(1, $v);
		});

		return ['-fv'];
	}

	public function testLongOptionBooleanTrue(Consoler $app)
	{
		$app('--parse', function($parse)
		{
			$this->assertTrue($parse);
		});

		return ['--parse'];
	}

	public function testOptionalLongOptionBooleanYes(Consoler $app)
	{
		$app('[--parse]', function($parse)
		{
			$this->assertTrue($parse);
		});

		return ['--parse'];
	}

	public function testOptionalLongOptionBooleanNo(Consoler $app)
	{
		$app('[--parse]', function($parse)
		{
			$this->assertFalse($parse);
		});

		return [];
	}

	public function testOptionalLongOptionValue(Consoler $app)
	{
		$app('[--filename=]', function($filename)
		{
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
		});

		return ['--filename', self::DESIRED_FILENAME];
	}

	public function testShortOptionLongOptionTripleDashInputFalse(Consoler $app, $nomatch = true)
	{
		$app('--long -s', function($long, $s)
		{
			// should not match
		});

		return ['---not-matchable'];
	}

	public function testCommandOptionalArgumentNoArgument(Consoler $app)
	{
		$app->download('[what] filename', function($what, $filename)
		{
			$this->assertNull($what);
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
		});

		return ['download', self::DESIRED_FILENAME];
	}

	public function testCommandOptionalArgumentYesArgument(Consoler $app)
	{
		$app->download('[what] filename', function($what, $filename)
		{
			$this->assertEquals(self::DESIRED_WHAT, $what);
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
		});

		return ['download', self::DESIRED_WHAT, self::DESIRED_FILENAME];
	}

	public function testCommandGroupedOptionalArgumentArgumentNoArgument(Consoler $app)
	{
		$app->download('[wait what] filename', function($wait, $what, $filename)
		{
			$this->assertNull($wait);
			$this->assertNull($what);
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
		});

		return ['download', self::DESIRED_FILENAME];
	}

	public function testCommandGroupedOptionalArgumentArgumentSplitArgument(Consoler $app)
	{
		$app->download('[wait what] filename', function($wait, $what, $filename, $remaining)
		{
			$this->assertNull($wait);
			$this->assertNull($what);
			$this->assertEquals(self::DESIRED_FILENAME, $remaining[0]);
			$this->assertEquals(self::DESIRED_WAIT, $filename);
		});

		return ['download', self::DESIRED_WAIT, self::DESIRED_FILENAME];
	}

	public function testCommandGroupedOptionalArgumentArgumentYesArgument(Consoler $app)
	{
		$app->download('[wait what] filename', function($wait, $what, $filename)
		{
			$this->assertEquals(self::DESIRED_WAIT, $wait);
			$this->assertEquals(self::DESIRED_WHAT, $what);
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
		});

		return ['download', self::DESIRED_WAIT, self::DESIRED_WHAT, self::DESIRED_FILENAME];
	}

	public function testCommandArgumentFile(Consoler $app)
	{
		$app->download('file:filename', function($filename)
		{
			$this->assertEquals(basename(__FILE__), $filename);
		});

		return ['download', basename(__FILE__)];
	}

	public function testCommandArgumentFile2(Consoler $app, $throws = true)
	{
		$app->download('file:filename', function($filename)
		{
			$this->assertEquals('lib/consoler.php', $filename);
		});

		return ['download', 'foobar.php'];
	}

	public function testCommandArgumentArgumentArgumentArgument(Consoler $app)
	{
		$app->download('[first second] thirth fourth', function($first, $second, $thirth, $fourth, $remaining)
		{
			$this->assertNull($first);
			$this->assertNull($second);
			$this->assertEquals('1', $thirth);
			$this->assertEquals('2', $fourth);
			$this->assertEquals('3', $remaining[0]);
		});

		return ['download', '1', '2', '3'];
	}

	public function testCommandArgumentArgumentArgumentArgumentArgument(Consoler $app)
	{
		$app->download('[first] [second thirth] fourth fifth', function($first, $second, $thirth, $fourth, $fifth)
		{
			$this->assertEquals('1', $first);
			$this->assertNull($second);
			$this->assertNull($thirth);
			$this->assertEquals('2', $fourth);
			$this->assertEquals('3', $fifth);
		});

		return ['download', '1', '2', '3'];
	}

	public function testCommandArgumentArgumentArgumentArgumentArgument2(Consoler $app)
	{
		$app->download('[first] [second thirth] fourth fifth', function($first, $second, $thirth, $fourth, $fifth)
		{
			$this->assertNull($first);
			$this->assertEquals('1', $second);
			$this->assertEquals('2', $thirth);
			$this->assertEquals('3', $fourth);
			$this->assertEquals('4', $fifth);
		});

		return ['download', '1', '2', '3', '4'];
	}

	public function testCommandArgumentArgumentArgumentArgumentArgument3(Consoler $app)
	{
		$app->download('[first] [second thirth] fourth [fifth] [sixth] seventh',
			function($first, $second, $thirth, $fourth, $fifth, $sixth, $seventh)
		{
			$this->assertNull($first);
			$this->assertEquals('1', $second);
			$this->assertEquals('2', $thirth);
			$this->assertEquals('3', $fourth);
			$this->assertNull($fifth);
			$this->assertNull($sixth);
			$this->assertEquals('4', $seventh);
		});

		return ['download', '1', '2', '3', '4'];
	}

	public function testCommandArgumentPrint(Consoler $app)
	{
		$app->download('filename', function($print, $filename)
		{
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
			$print($filename);
			$this->assertOutput($filename . "\n");
		});

		return ['download', self::DESIRED_FILENAME];
	}

	public function testCommandArgumentInput(Consoler $app)
	{
		$app->download('filename', function($ask, $filename)
		{
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
			$this->setInput('Tyler');
			$r = $ask('Provide your name:');
			$this->assertInput($r);
		});

		return ['download', self::DESIRED_FILENAME];
	}

	public function testConfirmYes(Consoler $app)
	{
		$app(function($confirm)
		{
			$this->setInput('y');
			$result = $confirm('Sure?');
			$this->assertTrue($result);
		});

		return [];
	}

	public function testConfirmDefaultYes(Consoler $app)
	{
		$app(function($confirm)
		{
			$result = $confirm('Sure?', 'y');
			$this->assertTrue($result);
		});

		return [];
	}

	public function testConfirmNo(Consoler $app)
	{
		$app(function($confirm)
		{
			$this->setInput('n');
			$result = $confirm('Sure?');
			$this->assertFalse($result);
		});

		return [];
	}

	public function testConfirmDefaultNo(Consoler $app)
	{
		$app(function($confirm)
		{
			$result = $confirm('Sure?', 'n');
			$this->assertFalse($result);
		});

		return [];
	}

	public function testUsageMessage(Consoler $app)
	{
		$app->download(function() use ($app)
		{
			$app->usage();
			$this->assertError("Usage:\n download\n");
		});

		return ['download'];
	}

	public function testUsageMessageShortOptions(Consoler $app)
	{
		$app->download('-v [-f]', function() use ($app)
		{
			$app->usage();
			$this->assertError("Usage:\n download -v [-f]\n");
		});

		return ['download', '-v'];
	}

	public function testUsageMessageLongOptions(Consoler $app)
	{
		$app->download('--reason= [--why]', function() use ($app)
		{
			$app->usage();
			$this->assertError("Usage:\n download --reason= [--why]\n");
		});

		return ['download', '--reason', 'because'];
	}

	public function testUsageMessageArguments(Consoler $app)
	{
		$app->download('filename', function() use ($app)
		{
			$app->usage();
			$this->assertError("Usage:\n download filename\n");
		});

		return ['download', self::DESIRED_FILENAME];
	}

	public function testUsageMessageDescription(Consoler $app)
	{
		$app->download('-- download all kind of things', function() use ($app)
		{
			$app->usage();
			$this->assertError("Usage:\n download -- download all kind of things\n");
		});

		return ['download'];
	}

	public function testUsageMessageMixed(Consoler $app)
	{
		$app->download('[-v] [-f] [--reason=] filename [target] -- download all kind of things', function() use ($app)
		{
			$app->usage();
			$this->assertError("Usage:\n download [-v] [-f] [--reason=] filename [target] -- download all kind of things\n");
		});

		return ['download', self::DESIRED_FILENAME];
	}

	public function testCommandArgumentWithDash(Consoler $app)
	{
		$app->download('filename', function($filename)
		{
			$this->assertEquals('--filename', $filename);
		});

		return ['download', '--', '--filename'];
	}

	public function testInvalidTripleDash(Consoler $app, $throws = true)
	{
		$app('---longer', function()
		{
			// throws
		});

		return [];
	}

	public function testDashedLongOption(Consoler $app)
	{
		$app('--dashed-long-option', function($dashedLongOption) use ($app)
		{
			$this->assertTrue($dashedLongOption);
		});

		return ['--dashed-long-option'];
	}

	public function testPartyDeluxe(Consoler $app)
	{
		$app->download('[-v] [-f] [--lang|-l] [--reason=] [foo bar] filename -- yay!',
			function($v, $f, $lang, $l, $reason, $foo, $bar, $filename, $remaining) use ($app)
		{
			$this->assertEquals(3, $v);
			$this->assertEquals(0, $f);
			$this->assertFalse($lang);
			$this->assertFalse($l);
			$this->assertEquals('no more', $reason);
			$this->assertNull($foo);
			$this->assertNull($bar);
			$this->assertEquals(self::DESIRED_FILENAME, $filename);
			$this->assertEquals('something', $remaining[0]);

			$app->usage();
			$this->assertError("Usage:\n download [-v] [-f] [--lang|-l] [--reason=] [foo bar] filename -- yay!\n");
		});

		return ['download', '-vv', '-v', '--reason', 'no more', self::DESIRED_FILENAME, 'something'];
	}

	public function testAlias(Consoler $app)
	{
		$app('-v|--verbose', function($v, $verbose)
		{
			$this->assertEquals(1, $v);
			$this->assertEquals(1, $verbose);
		});

		return ['-v'];
	}

	public function testAlias2(Consoler $app)
	{
		$app('-v|--verbose', function($v, $verbose)
		{
			$this->assertEquals(2, $v);
			$this->assertEquals(2, $verbose);
		});

		return ['-v', '--verbose'];
	}

	public function testCustomClass(Consoler $app)
	{
		// CustomClass has a method called `__consolerInvoke`, and is matched
		$app(new CustomClass);
		return [];
	}

	public function testTableHelper(Consoler $app)
	{
		$app(function($table)
		{
			$table([
				[
					'foo' => '1',
					'bar' => '42'
				],
				[
					'foo' => '1.100',
					'bar' => '42a'
				],
			]);

			// numbers align to right, all other to left
			$this->assertOutput(
				"| foo   | bar |\n" .
				"+-------+-----+\n" .
				"|     1 | 42  |\n" .
				"| 1.100 | 42a |\n"
				);
		});

		return [];
	}

	public function testTableHelperThrows(Consoler $app, $throws = true)
	{
		$app(function($table)
		{
			// records need to have the same structure
			$table([
				['foo' => 1],
				['bar' => 1],
			]);
		});

		return [];
	}
}

$app = new Consoler;
$app('[-v]', new Test);
$app->run();
