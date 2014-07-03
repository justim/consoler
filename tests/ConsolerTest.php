<?php

// used in one of the tests
class CustomClass
{
	public function __consolerInvoke()
	{
	}
}

class ConsolerTest extends PHPUnit_Framework_TestCase
{
	private $app;

	public function setUp()
	{
		$this->app = new Consoler;
	}

	public function testConstruct()
	{
		$app = new Consoler;

		$app = $app->setHandleExceptions(false);
		$this->assertInstanceOf('Consoler', $app);

		return $app;
	}

	public function testSetStream()
	{
		$this->app->setStreams([
			'out' => function() {},
			'err' => STDERR,
			'in' => fopen('php://stdin', 'r'),
		]);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testInvalidStream()
	{
		$this->app->setStream('out', 'some random string');
	}

	public function testStreamInUsage()
	{
		$app = $this->app;
		$question = 'What is your name?';
		$name = 'Tyler';

		$tmpFile = tempnam(sys_get_temp_dir(), 'testStreamInUsage');
		$tmpFileStream = fopen($tmpFile, 'rw+');

		$app->setStream('in', $tmpFileStream);

		$app->setStream('out', function($output) use ($question)
		{
			$this->assertEquals($question, $output);
		});

		$app(function($ask) use ($tmpFileStream, $name)
		{
			fwrite($tmpFileStream, "$name\n");
			rewind($tmpFileStream);

			$this->assertEquals($name, $ask('What is your name?'));
		});

		$app->run([]);
		unlink($tmpFile);
	}

	public function testStreamOutUsage()
	{
		$app = $this->app;
		$output = 'Party!';

		$tmpFile = tempnam(sys_get_temp_dir(), 'testStreamOutUsage');
		$tmpFileStream = fopen($tmpFile, 'rw+');

		$app->setStream('out', $tmpFileStream);

		$app(function($print) use ($tmpFileStream, $output)
		{
			$print($output);
			rewind($tmpFileStream);

			$this->assertEquals($output . "\n", fgets($tmpFileStream));
		});

		$app->run([]);
		unlink($tmpFile);
	}

	public function testDesiredFilename()
	{
		$desiredFilename = basename(__FILE__);

		$this->assertInternalType('string', $desiredFilename);

		return $desiredFilename;
	}

	public function testDesiredWhat()
	{
		$desiredWhat = '42';

		$this->assertInternalType('string', $desiredWhat);

		return $desiredWhat;
	}

	public function testDesiredWait()
	{
		$desiredWait = 'now';

		$this->assertInternalType('string', $desiredWait);

		return $desiredWait;
	}

	public function testDesiredExistingFilename()
	{
		$desiredExistingFilename = 'tests/' . basename(__FILE__);

		$this->assertInternalType('string', $desiredExistingFilename);

		return $desiredExistingFilename;
	}

	public function testCommand()
	{
		$this->app->download(function(){});

		$commandLineArgs = ['download'];

		$result = $this->app->run($commandLineArgs);

		$this->assertTrue($result);
	}

	public function testCommandFalse()
	{
		$this->app->setStream('err', function($err)
		{
			$this->assertEquals("Usage:\n download\n", $err);
		});

		$this->app->download(function(){});

		$result = $this->app->run(['download-foo']);

		$this->assertFalse($result);
	}

	/**
	 * @expectedException \BadMethodCallException
	 */
	public function testCommandInvalidAction()
	{
		$this->app->download(null);
	}

	public function testNoCommand()
	{
		$app = $this->app;
		$app(function(){});

		$result = $this->app->run([]);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testNoCommandArgument($desiredFilename)
	{
		$app = $this->app;
		$app('filename', function($filename) use ($desiredFilename)
		{
			$this->assertEquals($desiredFilename, $filename);
		});

		$result = $this->app->run([$desiredFilename]);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testCommandOptionalArgumentYes($desiredFilename)
	{
		$this->app->download('[filename]', function($filename) use ($desiredFilename)
		{
			$this->assertEquals($desiredFilename, $filename);
		});

		$result = $this->app->run(['download', $desiredFilename]);

		$this->assertTrue($result);
	}

	public function testCommandOptionalArgumentNo()
	{
		$this->app->download('[filename]', function($filename)
		{
			$this->assertNull($filename);
		});

		$result = $this->app->run(['download']);

		$this->assertTrue($result);
	}

	public function testCommandShortOption()
	{
		$this->app->download('-f', function($f)
		{
			$this->assertEquals(1, $f);
		});

		$result = $this->app->run(['download', '-f']);

		$this->assertTrue($result);
	}

	public function testCommandOptionalShortOptionYes()
	{
		$this->app->download('[-f]', function($f)
		{
			$this->assertEquals(1, $f);
		});

		$result = $this->app->run(['download', '-f']);

		$this->assertTrue($result);
	}

	public function testCommandOptionalShortOptionNo()
	{
		$this->app->download('[-f]', function($f)
		{
			$this->assertEquals(0, $f);
		});

		$result = $this->app->run(['download']);

		$this->assertTrue($result);
	}

	public function testCommandMultipleShortOptionSingle()
	{
		$this->app->download('-f', function($f)
		{
			$this->assertEquals(2, $f);
		});

		$result = $this->app->run(['download', '-ff']);

		$this->assertTrue($result);
	}

	public function testCommandMultipleShortOptionMultiple()
	{
		$this->app->download('-f', function($f)
		{
			$this->assertEquals(2, $f);
		});

		$result = $this->app->run(['download', '-f', '-f']);

		$this->assertTrue($result);
	}

	public function testCommandMultipleShortOption()
	{
		$this->app->download('-fv', function($f, $v)
		{
			$this->assertEquals(1, $f);
			$this->assertEquals(1, $v);
		});

		$result = $this->app->run(['download', '-f', '-v']);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testCommandOptionalShortOptionYesArgument($desiredFilename)
	{
		$this->app->download('[-f] filename', function($f, $filename) use ($desiredFilename)
		{
			$this->assertEquals(1, $f);
			$this->assertEquals($desiredFilename, $filename);
		});

		$result = $this->app->run(['download', '-f', $desiredFilename]);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testCommandOptionalShortOptionNoArgument($desiredFilename)
	{
		$this->app->download('[-f] filename', function($f, $filename) use ($desiredFilename)
		{
			$this->assertEquals(0, $f);
			$this->assertEquals($desiredFilename, $filename);
		});

		$result = $this->app->run(['download', $desiredFilename]);

		$this->assertTrue($result);
	}

	public function testShortOptionValue()
	{
		$app = $this->app;
		$app('-n=', function($n)
		{
			$this->assertEquals('19', $n);
		});

		$result = $this->app->run(['-n', '19']);

		$this->assertTrue($result);
	}

	public function testShortOptionShortOption()
	{
		$app = $this->app;
		$app('-f -v', function($f, $v)
		{
			$this->assertEquals(1, $f);
			$this->assertEquals(1, $v);
		});

		$result = $this->app->run(['-fv']);

		$this->assertTrue($result);
	}

	public function testLongOptionBooleanTrue()
	{
		$app = $this->app;
		$app('--parse', function($parse)
		{
			$this->assertTrue($parse);
		});

		$result = $this->app->run(['--parse']);

		$this->assertTrue($result);
	}

	public function testOptionalLongOptionBooleanYes()
	{
		$app = $this->app;
		$app('[--parse]', function($parse)
		{
			$this->assertTrue($parse);
		});

		$result = $this->app->run(['--parse']);

		$this->assertTrue($result);
	}

	public function testOptionalLongOptionBooleanNo()
	{
		$app = $this->app;
		$app('[--parse]', function($parse)
		{
			$this->assertFalse($parse);
		});

		$result = $this->app->run([]);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testOptionalLongOptionValue($desiredFilename)
	{
		$app = $this->app;
		$app('[--filename=]', function($filename) use ($desiredFilename)
		{
			$this->assertEquals($desiredFilename, $filename);
		});

		$result = $this->app->run(['--filename', $desiredFilename]);

		$this->assertTrue($result);
	}

	public function testShortOptionLongOptionTripleDashInputFalse()
	{
		$app = $this->app;

		$app->setStream('err', function($err)
		{
			$this->assertEquals("Usage:\n --long -s\n", $err);
		});

		$app('--long -s', function($long, $s)
		{
			// should not match
		});

		$result = $this->app->run(['---not-matchable']);

		$this->assertFalse($result);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testCommandOptionalArgumentNoArgument($desiredFilename)
	{
		$this->app->download('[what] filename', function($what, $filename) use ($desiredFilename)
		{
			$this->assertNull($what);
			$this->assertEquals($desiredFilename, $filename);
		});

		$result = $this->app->run(['download', $desiredFilename]);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 * @depends testDesiredWhat
	 */
	public function testCommandOptionalArgumentYesArgument($desiredFilename, $desiredWhat)
	{
		$this->app->download('[what] filename', function($what, $filename) use ($desiredFilename, $desiredWhat)
		{
			$this->assertEquals($desiredWhat, $what);
			$this->assertEquals($desiredFilename, $filename);
		});

		$result = $this->app->run(['download', $desiredWhat, $desiredFilename]);

		$this->assertTrue($result);
	}

	public function testNonMatchingOptionalGroupedOptions()
	{
		$app = $this->app;

		$app->setStream('err', function($output)
		{
			$this->assertEquals("Usage:\n [-v|--verbose filename]\n", $output);
		});

		$app('[-v|--verbose filename]', function($verbose, $filename)
		{
			$this->assertEquals(0, $verbose);
		});

		$app->run(['-v']);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testCommandGroupedOptionalArgumentArgumentNoArgument($desiredFilename)
	{
		$this->app->download('[wait what] filename', function($wait, $what, $filename) use ($desiredFilename)
		{
			$this->assertNull($wait);
			$this->assertNull($what);
			$this->assertEquals($desiredFilename, $filename);
		});

		$result = $this->app->run(['download', $desiredFilename]);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 * @depends testDesiredWhat
	 */
	public function testCommandGroupedOptionalArgumentArgumentSplitArgument($desiredFilename, $desiredWhat)
	{
		$this->app->download('[wait what] filename', function($wait, $what, $filename, $remaining) use ($desiredFilename, $desiredWhat)
		{
			$this->assertNull($wait);
			$this->assertNull($what);
			$this->assertEquals($desiredFilename, $remaining[0]);
			$this->assertEquals($desiredWhat, $filename);
		});

		$result = $this->app->run(['download', $desiredWhat, $desiredFilename]);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 * @depends testDesiredWhat
	 * @depends testDesiredWait
	 */
	public function testCommandGroupedOptionalArgumentArgumentYesArgument($desiredFilename, $desiredWhat, $desiredWait)
	{
		$this->app->download('[wait what] filename', function($wait, $what, $filename) use ($desiredFilename, $desiredWhat, $desiredWait)
		{
			$this->assertEquals($desiredWait, $wait);
			$this->assertEquals($desiredWhat, $what);
			$this->assertEquals($desiredFilename, $filename);
		});

		$result = $this->app->run(['download', $desiredWait, $desiredWhat, $desiredFilename]);

		$this->assertTrue($result);
	}

	public function testCommandArgumentFile()
	{
		$this->app->download('file:filename', function($filename)
		{
			$this->assertEquals('tests/' . basename(__FILE__), $filename);
		});

		$result = $this->app->run(['download', 'tests/' . basename(__FILE__)]);

		$this->assertTrue($result);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testCommandArgumentFileFileDoesNotExist()
	{
		$this->app->setHandleExceptions(false);

		$this->app->download('file:filename', function($filename)
		{
			// does not match
		});

		$this->app->run(['download', 'foobar.php']);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testCommandArgumentFileFileIsNotAFile()
	{
		$this->app->setHandleExceptions(false);

		$this->app->download('file:filename', function($filename)
		{
			// does not match
		});

		$this->app->run(['download', 'tests']);
	}

	public function testCommandArgumentDir()
	{
		$this->app->setHandleExceptions(false);

		$this->app->download('dir:directory', function($directory)
		{
			$this->assertEquals('tests', $directory);
		});

		$this->app->run(['download', 'tests']);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testCommandArgumentDirDirDoesNotExist()
	{
		$this->app->setHandleExceptions(false);

		$this->app->download('dir:directory', function($directory)
		{
			// does not match
		});

		$this->app->run(['download', 'foobar']);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testCommandArgumentDirDirIsNotADir()
	{
		$this->app->setHandleExceptions(false);

		$this->app->download('dir:directory', function($directory)
		{
			// does not match
		});

		$this->app->run(['download', 'tests/' . basename(__FILE__)]);
	}

	public function testCommandArgumentArgumentArgumentArgument()
	{
		$this->app->download('[first second] thirth fourth', function($first, $second, $thirth, $fourth, $remaining)
		{
			$this->assertNull($first);
			$this->assertNull($second);
			$this->assertEquals('1', $thirth);
			$this->assertEquals('2', $fourth);
			$this->assertEquals('3', $remaining[0]);
		});

		$result = $this->app->run(['download', '1', '2', '3']);

		$this->assertTrue($result);
	}

	public function testCommandArgumentArgumentArgumentArgumentArgument()
	{
		$this->app->download('[first] [second thirth] fourth fifth', function($first, $second, $thirth, $fourth, $fifth)
		{
			$this->assertEquals('1', $first);
			$this->assertNull($second);
			$this->assertNull($thirth);
			$this->assertEquals('2', $fourth);
			$this->assertEquals('3', $fifth);
		});

		$result = $this->app->run(['download', '1', '2', '3']);

		$this->assertTrue($result);
	}

	public function testCommandArgumentArgumentArgumentArgumentArgument2()
	{
		$this->app->download('[first] [second thirth] fourth fifth', function($first, $second, $thirth, $fourth, $fifth)
		{
			$this->assertNull($first);
			$this->assertEquals('1', $second);
			$this->assertEquals('2', $thirth);
			$this->assertEquals('3', $fourth);
			$this->assertEquals('4', $fifth);
		});

		$result = $this->app->run(['download', '1', '2', '3', '4']);

		$this->assertTrue($result);
	}

	public function testCommandArgumentArgumentArgumentArgumentArgument3()
	{
		$this->app->download('[first] [second thirth] fourth [fifth] [sixth] seventh',
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

		$result = $this->app->run(['download', '1', '2', '3', '4']);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testCommandArgumentPrint($desiredFilename)
	{
		$this->app->setStream('out', function($output) use ($desiredFilename)
		{
			$this->assertEquals($desiredFilename . "\n", $output);
		});

		$this->app->download('filename', function($print, $filename) use ($desiredFilename)
		{
			$this->assertEquals($desiredFilename, $filename);
			$print($filename);
		});

		$result = $this->app->run(['download', $desiredFilename]);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testCommandArgumentInput($desiredFilename)
	{
		$question = 'Provide your name:';
		$input = 'Tyler';

		$this->app->setStream('in', function() use ($input)
		{
			return $input;
		});

		$this->app->setStream('out', function($output) use ($question)
		{
			$this->assertEquals($question, $output);
		});

		$this->app->download('filename', function($ask, $filename) use ($desiredFilename, $question, $input)
		{
			$this->assertEquals($desiredFilename, $filename);
			$name = $ask($question);
			$this->assertEquals($name, $input);
		});

		$result = $this->app->run(['download', $desiredFilename]);

		$this->assertTrue($result);
	}

	public function testConfirmYes()
	{
		$app = $this->app;

		$input = 'y';

		$app->setStream('in', function() use ($input)
		{
			return $input;
		});

		$app->setStream('out', function($output)
		{
			$this->assertEquals('Sure? [y/n] ', $output);
		});

		$app(function($confirm)
		{
			$result = $confirm('Sure?');
			$this->assertTrue($result);
		});

		$result = $app->run([]);

		$this->assertTrue($result);
	}

	public function testConfirmDefaultYes()
	{
		$app = $this->app;

		$app->setStream('in', function()
		{
			return '';
		});

		$app->setStream('out', function($output)
		{
			$this->assertEquals('Sure? [Y/n] ', $output);
		});

		$app(function($confirm)
		{
			$result = $confirm('Sure?', 'y');
			$this->assertTrue($result);
		});

		$result = $app->run([]);

		$this->assertTrue($result);
	}

	public function testConfirmNo()
	{
		$app = $this->app;

		$input = 'n';

		$app->setStream('in', function() use ($input)
		{
			return $input;
		});

		$app->setStream('out', function($output)
		{
			$this->assertEquals('Sure? [y/n] ', $output);
		});

		$app(function($confirm)
		{
			$result = $confirm('Sure?');
			$this->assertFalse($result);
		});

		$result = $app->run([]);

		$this->assertTrue($result);
	}

	public function testConfirmDefaultNo()
	{
		$app = $this->app;

		$app->setStream('in', function()
		{
			return '';
		});

		$app->setStream('out', function($output)
		{
			$this->assertEquals('Sure? [y/N] ', $output);
		});

		$app(function($confirm)
		{
			$result = $confirm('Sure?', 'n');
			$this->assertFalse($result);
		});

		$result = $app->run([]);

		$this->assertTrue($result);
	}

	public function testUsageMessage()
	{
		$error = '';

		$this->app->setStream('err', function($err)
		{
			$this->assertEquals("Usage:\n download\n", $err);
		});

		$this->app->download(function() {});

		$this->app->usage();
	}

	public function testUsageMessageShortOptions()
	{
		$this->app->setStream('err', function($err)
		{
			$this->assertEquals("Usage:\n download -v [-f]\n", $err);
		});

		$this->app->download('-v [-f]', function() {});

		$this->app->usage();
	}

	public function testUsageMessageLongOptions()
	{
		$this->app->setStream('err', function($err)
		{
			$this->assertEquals("Usage:\n download --reason= [--why]\n", $err);
		});

		$this->app->download('--reason= [--why]', function() {});

		$this->app->usage();
	}

	public function testUsageMessageArguments()
	{
		$this->app->setStream('err', function($err)
		{
			$this->assertEquals("Usage:\n download filename\n", $err);
		});

		$this->app->download('filename', function() {});

		$this->app->usage();
	}

	public function testUsageMessageDescription()
	{
		$this->app->setStream('err', function($err)
		{
			$this->assertEquals("Usage:\n download -- download all kind of things\n", $err);
		});

		$this->app->download('-- download all kind of things', function() {});

		$this->app->usage();
	}

	public function testUsageMessageMixed()
	{
		$this->app->setStream('err', function($err)
		{
			$this->assertEquals("Usage:\n download [-v] [-f] [--reason=] filename [target] -- download all kind of things\n", $err);
		});

		$this->app->download('[-v] [-f] [--reason=] filename [target] -- download all kind of things', function() {});

		$this->app->usage();
	}

	public function testUsageMessageAliases()
	{
		$this->app->setStream('err', function($err)
		{
			$this->assertEquals("Usage:\n download [-v|--verbose] filename\n", $err);
		});

		$this->app->download('[-v|--verbose] filename', function() {});

		$this->app->usage();
	}

	public function testCommandArgumentWithDash()
	{
		$this->app->download('filename', function($filename)
		{
			$this->assertEquals('--filename', $filename);
		});

		$result = $this->app->run(['download', '--', '--filename']);

		$this->assertTrue($result);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testInvalidTripleDash()
	{
		$app = $this->app;

		$app('---longer', function()
		{
			// throws
		});
	}

	public function testDashedLongOption()
	{
		$app = $this->app;

		$app('--dashed-long-option', function($dashedLongOption)
		{
			$this->assertTrue($dashedLongOption);
		});

		$result = $this->app->run(['--dashed-long-option']);

		$this->assertTrue($result);
	}

	/**
	 * @depends testDesiredFilename
	 */
	public function testPartyDeluxe($desiredFilename)
	{
		$this->app->download('[-v] [-f] [--lang|-l] [--reason=] [foo bar] filename -- yay!',
			function($v, $f, $lang, $l, $reason, $foo, $bar, $filename, $remaining) use ($desiredFilename)
		{
			$this->assertEquals(3, $v);
			$this->assertEquals(0, $f);
			$this->assertFalse($lang);
			$this->assertFalse($l);
			$this->assertEquals('no more', $reason);
			$this->assertNull($foo);
			$this->assertNull($bar);
			$this->assertEquals($desiredFilename, $filename);
			$this->assertEquals('something', $remaining[0]);
		});

		$result = $this->app->run(['download', '-vv', '-v', '--reason', 'no more', $desiredFilename, 'something']);

		$this->assertTrue($result);
	}

	public function testAlias()
	{
		$app = $this->app;

		$app('-v|--verbose', function($v, $verbose)
		{
			$this->assertEquals(1, $v);
			$this->assertEquals(1, $verbose);
		});

		$result = $this->app->run(['-v']);

		$this->assertTrue($result);
	}

	public function testAlias2()
	{
		$app = $this->app;

		$app('-v|--verbose', function($v, $verbose)
		{
			$this->assertEquals(2, $v);
			$this->assertEquals(2, $verbose);
		});

		$result = $this->app->run(['-v', '--verbose']);

		$this->assertTrue($result);
	}

	public function testCustomClass()
	{
		$app = $this->app;

		// CustomClass has a method called `__consolerInvoke`, and is matched
		$app(new CustomClass);
		$result = $this->app->run([]);

		$this->assertTrue($result);
	}

	public function testTableHelper()
	{
		$app = $this->app;

		$expectedLines = [
			"| foo   | bar |\n",
			"+-------+-----+\n",
			"|     1 | 42  |\n",
			"| 1.100 | 42a |\n",
		];
		$currentLine = 0;

		$app->setStream('out', function($output) use ($expectedLines, &$currentLine)
		{
			$this->assertEquals($expectedLines[$currentLine], $output);
			$currentLine++;
		});

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
		});

		$result = $this->app->run([]);

		$this->assertTrue($result);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testTableHelperThrows()
	{
		$app = $this->app;

		$app->setHandleExceptions(false);

		$app(function($table)
		{
			// records need to have the same structure
			$table([
				['foo' => 1],
				['bar' => 1],
			]);
		});

		$app->run([]);
	}

	public function testEnvironment()
	{
		$_SERVER['argv'] = ['script_name.php', 'download'];

		$this->app->download(function(){});

		$result = $this->app->run();
		$this->assertTrue($result);
	}

	public function testExceptionHandling()
	{
		$app = $this->app;

		$app->setExitOnFailure(false);

		$expectedLines = [
			"File does not exist: foobar.php\n",
			"Usage:\n filename\n",
		];
		$currentLine = 0;

		$app->setStream('err', function($output) use ($expectedLines, &$currentLine)
		{
			$this->assertEquals($expectedLines[$currentLine], $output);
			$currentLine++;
		});

		$app('file:filename', function(){});

		$result = $app->run(['foobar.php']);
		$this->assertFalse($result);
	}

	public function testDefaultParameter()
	{
		$app = $this->app;

		$app(function($foobar)
		{
			$this->assertNull($foobar);
		});

		$result = $app->run([]);
		$this->assertTrue($result);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testMissingClosingOptionalToken()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('[filename', function(){});
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testUnopenedOptionalToken()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('filename]', function(){});
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testDuplicateOption()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('-v -v', function(){});
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testDuplicateAliasOption()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('-v --verbose|-v', function(){});
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testEmptyOption()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('-', function(){});
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testNoAliasesForMultiOption()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('-fv|--verbose', function(){});
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testNoAliasesForArguments()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('filename|file', function(){});
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testNestedOptionals()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('[filename [-v]]', function(){});
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testArgumentCantHaveValue()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('filename=', function(){});
	}

	/**
	 * @expectedException \InvalidArgumentException
	 */
	public function testInvalidFilter()
	{
		$this->app->setHandleExceptions(false);
		$this->app->download('foo:bar', function(){});
	}

	/**
	 * @depends testDesiredExistingFilename
	 */
	public function testFileHelper($desiredExistingFilename)
	{
		$app = $this->app;

		$question = 'Give filename: ';

		$app->setStream('in', function() use ($desiredExistingFilename)
		{
			return $desiredExistingFilename;
		});

		$app->setStream('out', function($output) use ($question)
		{
			$this->assertEquals($question, $output);
		});

		$app(function($file) use ($question, $desiredExistingFilename)
		{
			$filename = $file($question);
			$this->assertEquals($desiredExistingFilename, $filename);
		});

		$app->run([]);
	}

	public static function staticHelperMethod()
	{

	}

	public function testStaticCallback()
	{
		$this->app->download('ConsolerTest::staticHelperMethod');
		$result = $this->app->run(['download']);
		$this->assertTrue($result);
	}

	public function testFunctionCallback()
	{
		function functionHelperFunction(){}

		$this->app->download('functionHelperFunction');
		$result = $this->app->run(['download']);
		$this->assertTrue($result);
	}
}
