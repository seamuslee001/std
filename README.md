# Clippy

Clippy is a CLI framework for *scripting*  in PHP -- i.e. creating short, task-specific, standalone commands. It is heavily based on [mnapoli/silly](https://github.com/mnapoli/silly/).

*Scripting* is a different domain than, say, *full business applications*.

* In some ways, scripting is more modest: a full business application may have a wide variety of entities, screens, commands, and authors.  Dependencies and conventions among these various components must be reconciled.  By contrast, a script is generally focused on one task and has wider latitude to mix and match libraries and conventions.
* In other ways, scripting is more stringent: the naming/structure/metadata should be quite thin to allow quick improvisation, and it should be easy+safely to frequently call out to other CLI commands.  By contrast, a full business app has more value built-in -- so it needs more structure to differentiate its internal components, and it doesn't need to call-out as frequently.

Note: To simplify the workflows for dependency management, the examples use [pogo](http://github.com/totten/pogo).  `pogo` should be installed in the `PATH`.
Alternatively, you can rework the examples - instead, create a new `composer` package for each script and run `composer require <package>:<version>` has needed.

## Example (`greeter.php`)

```php
#!/usr/bin/env pogo
<?php
#!require clippy/std: 0.2.0
namespace Clippy;
use Symfony\Component\Console\Style\SymfonyStyle;

$c = clippy()->register(plugins());
$c['app']->main('yourName', function ($out, $yourName, SymfonyStyle $io) {
  $io->writeln("Hello, <comment>$yourName</comment>!");
});
```

Which one would execute as

```bash
$ ./greeter.php world
Hello, world!
```

Things to observe in this example:

* The notation s`#!/usr/bin/env pogo` and `#!require <package>: <version>` allow you to load PHP packages in the script without creating a dedicated project for the script. You don't need to separately run `composer install`.
* The script is written within the `Clippy` namespace. This makes it easier to use helper functions.
* The notation `$c = clippy()->register(plugins());` instantiates the system. It autoloads any *plugins* that have been pre-defined by other PHP packages.
* The variable `$c` is a *service-container*. You may access services with the notation `$c['myService']`, and you can define services with `$c['myService'] = function(...){...}`.
* The following services are built-in by default:
    * `$c['input']` (`\Symfony\Component\Console\Input\InputInterface`)
    * `$c['output']` (`\Symfony\Component\Console\\Output\OutputInterface`)
    * `$c['io']` (`\Symfony\Component\Console\\Style\SymfonyStyle`)
    * `$c['app']` (`\Clippy\Application` aka `\Silly\Application` aka `\Symfony\Component\Console\Application`)
    * `$c['container']` (`\Clippy\Container` aka `\Pimple\Container`; the full container)
* The method `main($signature, $callback)`  is shorthand to defining a single-purpose script. 
    * The `$signature` defines CLI options accepted by this command. The signature is used for (a) checking required inputs, (b) parsing inputs, and (c) generating in-line help screens (`myscript --help`).
    * The `$callback` defines the logic of the command. Parameters are matched by name - by looking (first) at the list of CLI inputs and (second) at the list of services in `$c`. In this case, the command accepts one mandatory input, `yourName`.
    * If the script should have multiple subcommands, then use more fine-grained methods, `$c['app']->register($signature, $callback)` and `$c['app']->run()`.

Let's extend the example just a little bit - allowing the user to optionally direct output to an alternative file. CLI commands often accept an option like `-o <file>` or `--out=<file>`.

```bash
$ ./greeter.php world -o /tmp/greeting.txt
$ ./greeter.php --out=/tmp/greeting.txt world
```

Revised example:

```php
#!/usr/bin/env pogo
<?php
#!require clippy/std: 0.2.0
namespace Clippy;
use Symfony\Component\Console\Style\SymfonyStyle;

$c = clippy()->register(plugins());
$c['writeFile()'] = function($file, $content, SymfonyStyle $io) {
  if (file_exists($file)) {
    $io->warning("The file $file already exists!");
    if (!$io->confirm("Would you like to overwrite it?")) {
      exit(1);
    }
  }
  file_put_contents($file, $content);
};
$c['app']->main('[-o|--out=] yourName', function ($out, $yourName, SymfonyStyle $io, $writeFile) {
  if ($out) {
    $writeFile($out, "Hello, $yourName!\n");
  }
  else {
    $io->writeln("Hello, <comment>$yourName</comment>!");
  }
});
```

Things to note:

* The command signature changed:
    * Was: `$c['app']->main('yourName', function($yourName...))`
    * Now: `$c['app']->main('[-o|--out=] yourName', function($out, $yourName...))`
* `writeFile()` is a *service-method*. It is like a service -- it is stored in the container (`$c`), and you can inject services (like `$io`). However, it is also a like a function -- you can pass in runtime data to taste (`$file`, `$content`).

## Technical Structure

Clippy is built on:

* [Symfony Console](https://symfony.com/doc/current/components/console.html): Defines console *commands* which accept *inputs*, generate *outputs*, and have builtin *help* screens.
* [silly](https://github.com/mnapoli/silly/): Extends Symfony Console with pithy command-signatures and auto-injection
* [php-di/invoker](https://github.com/PHP-DI/Invoker): Defines *auto-injection* - when executing a function, it can automatically pass in parameters from the container.
* [pimple](https://pimple.symfony.com/): A service container

Clippy also adds/extends/changes a few things:

* Silly allows you to inject services as *function-parameters* -- e.g. `function($input) {...}`. But this only works with `Command` objects. Clippy extends this to *all services and factories*.
* Silly allows you to inject common CLI services `InputInterface $input`, `OutputInterface $output`, `SymfonyStyle $io`. But these are only available to `Command` functions. Clippy extends them to be available to *all services and factories*.
* Silly works with any PSR-11 container. Clippy specifically uses `\Clippy\Container`.
* Clippy's `Container` is closely modeled on Pimple's `Container`, but it differs in that it uses *auto-wiring of services as function-parameters*. Thus, instead of saying `function($c) { $c['myService']->doFoo(); }`, you would say `function($myService) { $myService->doFoo(); }`. In this notation, type-hinting is more useful.
* Clippy's `Container` supports *service-methods*. These are functions which support *both* service-injection and runtime data-passing. In the following example, the first parameter to `getPassword` is passed at runtime (`$domain` e.g. `example.com`); the second parameter is injected automatically.
   ```php
   $c['getPassword()'] = function ($domain, SymfonyStyle $io) {
     if (getenv('PASSWORD')) return getenv('PASSWORD');
     return $io->askHidden("What is the password for <comment>$domain</comment>?");
   }
   $c['app']->main('', function($getPassword) {
     $pass = $getPassword('example.com');
   });
   ```
* Clippy defines a *plugin* construct. Plugins may register new services. A plugin is simply a package which declares itself in `$GLOBALS['plugins']`. Plugins are autoloaded via convention `$c = clippy()->register(plugins())`, but they may (per perference) be loaded incrementally or piecemeal.

## Plugins: Usage

It's expected that typical usage would like:

```php
#!require foo/barplugin: 1.2.3
$c = clippy()->register(plugins());
```

By default, the `plugins()` function will a list of all plugins defined in `$GLOBALS['plugins']`.

If you need to pick and choose plugins (e.g.  to simulate different configurations in a testing environment), then simply pass in the list of desired plugins:

```php
#!require foo/barplugin: 1.2.3
$c = clippy()->register(plugins(['bar', ...]));
```

## Plugins: Definition

A plugin is a `composer` package with a PHP file (e.g. `plugin.php`) which defines some services. In
brief, the package needs a file which says:

```php
$GLOBALS['plugins']['myplugin'] = function($container) {
  $container['myservice'] = function() {
    return new MyService();
  };
};
```

For a fuller consideration, you will need to create standard boilerplate:

```
mkdir myplugin
git init
composer init
vi composer.json
```

In the `composer.json`, be sure to depend on `clippy/std` and
define an `autoload` section:

```json
{
    "name": "me/myplugin",
    "require": {
        "clippy/std": ">=0.2.0",
    },
    "autoload": {
        "files": ["plugin.php"],
        "psr-4": {"Clippy\\": "src/"}
    }
}
```

Use the `plugin.php` to define services, and use `src/**.php` to define new classes.

Then post the code to Github/Packagist/etc and tag a release.
