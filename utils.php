<?php
namespace Clippy;

// -----------------------------------------------------------------------------
// Assertions

/**
 * Assert that $bool is true.
 *
 * @param bool $bool
 * @param string $msg
 * @throws \Exception
 */
function assertThat($bool, $msg = '') {
  if (!$bool) {
    throw new \Exception($msg ? $msg : 'Assertion failed');
  }
}

/**
 * Assert that $value has an actual value (not null or empty-string)
 *
 * @param mixed $value
 * @param string $msg
 * @return mixed
 *   The approved value
 * @throws \Exception
 */
function assertVal($value, $msg) {
  if ($value === NULL || $value === '') {
    throw new \Exception($msg ? $msg : 'Missing expected value');
  }
  return $value;
}

function fail($msg) {
  throw new \Exception($msg ? $msg : 'Assertion failed');
}

// -----------------------------------------------------------------------------
// IO utilities

/**
 * Combine all elements of part, in order, to form a string - using path delimiters.
 * Duplicate delimiters are trimmed.
 *
 * @param array $parts
 *   A list of strings and/or arrays.
 * @return string
 */
function joinPath(...$parts) {
  $path = [];
  foreach ($parts as $part) {
    if (is_array($part)) {
      $path = array_merge($path, $part);
    }
    else {
      $path[] = $part;
    }
  }
  $result = implode(DIRECTORY_SEPARATOR, $parts);
  $both = "[\\/]";
  return preg_replace(";{$both}{$both}+;", '/', $result);
}

/**
 * Combine all elements of part, in order, to form a string - using URL delimiters.
 * Duplicate delimiters are trimmed.
 *
 * @param array $parts
 *   A list of strings and/or arrays.
 * @return string
 */
function joinUrl(...$parts) {
  $path = [];
  foreach ($parts as $part) {
    if (is_array($part)) {
      $path = array_merge($path, $part);
    }
    else {
      $path[] = $part;
    }
  }
  $result = implode('/', $parts);
  return preg_replace(';//+;', '/', $result);
}

function toJSON($data) {
  return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function fromJSON($data) {
  #!require psr/http-message: *
  if ($data instanceof \Psr\Http\Message\ResponseInterface) {
    $data = $data->getBody()->getContents();
  }
  assertThat(is_string($data));
  $result = json_decode($data, 1);
  assertThat($result !== NULL || $data === 'null', sprintf("JSON parse error:\n----\n%s\n----\n", $data));
  return $result;
}

// -----------------------------------------------------------------------------
// Array utilities

/**
 * Builds an array-tree which indexes the records in an array.
 *
 * @param string[] $keys
 *   Properties by which to index.
 * @param object|array $records
 *
 * @return array
 *   Multi-dimensional array, with one layer for each key.
 */
function index($keys, $records) {
  $final_key = array_pop($keys);

  $result = [];
  foreach ($records as $record) {
    $node = &$result;
    foreach ($keys as $key) {
      if (is_array($record)) {
        $keyvalue = isset($record[$key]) ? $record[$key] : NULL;
      }
      else {
        $keyvalue = isset($record->{$key}) ? $record->{$key} : NULL;
      }
      if (isset($node[$keyvalue]) && !is_array($node[$keyvalue])) {
        $node[$keyvalue] = [];
      }
      $node = &$node[$keyvalue];
    }
    if (is_array($record)) {
      $node[$record[$final_key]] = $record;
    }
    else {
      $node[$record->{$final_key}] = $record;
    }
  }
  return $result;
}

// -----------------------------------------------------------------------------
// High level services

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @return Container
 */
function clippy(InputInterface $input = NULL, OutputInterface $output = NULL) {
  $container = new Container();
  $container->set('container', $container);
  $container->set('input', $input ?? new \Symfony\Component\Console\Input\ArgvInput());
  $container->set('output', $output ?? new \Symfony\Component\Console\Output\ConsoleOutput());
  $container->set('io', new \Symfony\Component\Console\Style\SymfonyStyle($container['input'], $container['output']));
  return $container;
}

/**
 * Define the list of available plugins.
 *
 * @param string|array|null $name
 *   - If a string, then assigning values.
 *   - If NULL, then returning all values.
 *   - If an array, then of the named items.
 * @param string|null $callback
 * @return array
 */
function plugins($name = NULL, $callback = NULL) {
  static $plugins = [];
  if (is_string($name)) {
    if ($callback === NULL) {
      throw new \Exception("Invalid plugin declaration. Must specify callback.");
    }
    $plugins[$name] = $callback ?? [$name, 'register'];
    return NULL;
  }
  elseif (is_array($name)) {
    return array_intersect_key($plugins, array_fill_keys($name, 1));
  }
  else {
    return $plugins;
  }
}

plugins('app', ['\Clippy\ConsoleApp', 'register']);
plugins('cred', ['\Clippy\Credentials', 'register']);
plugins('guzzle', ['\Clippy\Guzzle', 'register']);
