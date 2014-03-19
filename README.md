# Consoler [![Build Status](https://secure.travis-ci.org/justim/consoler.png)](http://travis-ci.org/justim/consoler)
> Sinatra-like application builder for the console

## Features

* No fiddling with `$argv` and friends
* Arguments filled based on their name, for easy access
* Easily plugged in your current codebase (see [Last thingies](#last-thingies))
* Grouped optionals
* Option aliases

## Requirements

* `PHP >= 5.4`

## Installation

* For Consoler to work you only need the `Consoler.php` file, download it and hack away
* Also available at [Packagist](https://packagist.org/packages/justim/consoler) (Composer)

## Workflow

Begin by creating an instance of `Consoler` — in these example we use `app.php` as filename for the script:
```php
$app = new Consoler
```

Then you can add command to it in two ways:
```php
$app('options', $callback); // available at: php app.php
$app->create('options', $callback); // available at: php app.php create
```

To these functions you can add two parameter, the options as a string, and a callback which will be called when a command is matched. The options are optional and can be skipped, the first argument will become the callback.

### Syntax of the options

* `-v`: short option with the name `v` needed (ex. `php app.php -v`)
* `--verbose`: long option with the name `verbose` needed (ex. `php app.php --verbose`)
* `filename`: argument (ex. `php app.php sherlock.php`)
* `-f=`: short option with value (ex. `php app.php -f sherlock`)
* `--filename=`: long option with value (ex. `php app.php --filename sherlock`)
* `[ .. ]`: optional options/arguments. Optional-tokens can occur anywhere in the options, as long as they are not nested and properly closed. You can group optional parts, meaning that both options/arguments should be available or both not. (ex. `php app.php` would match `[-f=]`, and so would `php app.php -f sherlock.mp4`)
* `--verbose|-v`: options can have aliases where the first one is leading (see [Return types in callback](#return-types-in-callback))
* `file:filename`: string before the colon is the name of a filter, currently only `file` and `dir` are available. (ex. [Filters example](#filters-example))

Options and/or arguments are mandatory unless specified otherwise.

### Return types in callback

* Short options give a integer: `php app.php -v -v` -> `$v === 2` (zero when optional and not provided)
* Long options give a boolean: `php app.php --verbose` -> `$verbose === true` (`false` when not provided)
* Options with a value give a string: `php app.php --filename sherlock.mp4` => `$filename === 'sherlock'` (`null` when optional and not provided)
* With aliases the leading type is used: `php app.php --verbose` -> `$verbose === 1` (with `-v|--verbose`)

## Examples

### Basic example
```php
$app = new Consoler;
$app->create('filename', function($filename)
{
	touch($filename);
});
$app->run();
```
Call with: `php app.php create sherlock.mp4`

### Options example
```php
$app = new Consoler;
$app->create('[-f|--force] filename', function($filename, $force)
{
	if ($force || !file_exists($filename))
	{
		touch($filename);
	}
});
$app->run();
```
Call with: `php app.php create -f sherlock.mp4`

### Filters example
```php
$app = new Consoler;
$app->remove('file:filename', function($filename)
{
	unlink($filename);
});
$app->run();
```
Call with: `php app.php remove sherlock.mp4` _(only matches when file exists)_

### Arguments example
```php
$app = new Consoler;
$app->remove('[foo] [bar baz] filename', function($foo, $bar, $baz, $filename)
{
	// foo = null
	// bar = '1'
	// baz = '2'
	// filename = 'sherlock.mp4'
});
$app->run();
```
Call with: `php app.php remove 1 2 sherlock.mp4` _(foo is not matched, bar & baz take precedence because they are grouped)_

### Interactive example
```php
$app = new Consoler;
$app->remove('filename', function($filename, $confirm, $print)
{
	if ($confirm('Are you sure?', 'y' /* default */))
	{
		unlink($filename);
		$print('File removed');
	}
	else
	{
		$print('File not removed');
	}
});
$app->run();
```
Call with: `php app.php remove sherlock.mp4`

### Mooooaaaarrr

Check out the tests in [tests.php](https://github.com/justim/consoler/blob/master/tests.php).

## Helpers
> Helpers are available as parameter in the callback

* `$print` -> prints to the standard out  (`STDOUT`)
* `$error` -> prints to the standard error (`STDERR`)
* `$ask` -> return data from `STDIN` — typing :)
* `$confirm` -> ask for `y` or `n` and return a `boolean`
* `$password` -> ask for a password without showing the input (on `STDERR`)
* `$file` -> ask for a valid file
* `$exit` -> helper to exit the process (`exit;`) with optional error message or code

### Last thingies

You can use one of your existing classes as a valid callback by adding `__consolerInvoke`-method.
