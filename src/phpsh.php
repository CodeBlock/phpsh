#!/usr/bin/env php
<?php
// Copyright 2004-2007 Facebook. All Rights Reserved.
// this is used by phpshell.py to exec php commands and maintain state
// @author  ccheever
// @author  dcorson
// @author  warman (added multiline input support \ing)
// @date    Thu Jun 15 22:27:46 PDT 2006
//
// usage: this is only called from phpsh (the python end), as:
// phpsh.php <comm-file> <codebase-mode> [-c]
//
// use '' for default codebase-mode, define others in /etc/phpsh/rc.php
// -c turns off color

// set the TFBENV to script
$_SERVER['TFBENV'] = 16777216;

// FIXME: www/lib/thrift/packages/falcon/falcon.php is huge
//  this is probably not the right fix, but we need it for now
ini_set('memory_limit', ini_get('memory_limit') * 2 . 'M');

// we buffer the output on includes so that output that gets generated by includes
// doesn't interfere with the secret messages we pass between php and python
// we'll capture any output and show it when we construct the shell object
ob_start();

$___phpshell___codebase_mode = $argv[2];
$___phpshell___homerc = getenv('HOME').'/.phpsh/rc.php';
if (file_exists($___phpshell___homerc)) {
  require_once $___phpshell___homerc;
} else {
  require_once '/etc/phpsh/rc.php';
}

$___phpshell___do_color = true;
$___phpshell___do_autocomplete = true;
$___phpshell___options_possible = true;
foreach (array_slice($GLOBALS['argv'], 3) as $___phpshell___arg) {
  $___phpshell___did_arg = false;
  if ($___phpshell___options_possible) {
    switch ($___phpshell___arg) {
    case '-c':
      $___phpshell___do_color = false;
      $___phpshell___did_arg = true;
      break;
    case '-A':
      $___phpshell___do_autocomplete = false;
      $___phpshell___did_arg = true;
      break;
    case '--':
      $___phpshell___options_possible = false;
      $___phpshell___did_arg = true;
      break;
    }
    if ($___phpshell___did_arg) {
      continue;
    }
  }

  include_once $___phpshell___arg;
}

$___phpshell___output_from_includes = ob_get_contents();
ob_end_clean();

// unset all the variables we don't absolutely need
unset($___phpshell___arg);
unset($___phpshell___did_arg);
unset($___phpshell___options_possible);
$___phpshell___ = new ___PHPShell___($___phpshell___output_from_includes,
    $___phpshell___do_color, $___phpshell___do_autocomplete, $argv[1]);

/**
 * An instance of a phpshell interactive loop
 *
 * @author     ccheever
 * @author     dcorson
 *
 * This class mostly exists as a proxy for a namespace
 */
class ___PHPShell___ {
  var $_handle = STDIN;
  var $_comm_handle;
  var $_MAX_LINE_SIZE = 262144;

  /**
   * Constructor - actually runs the interactive loop so that all we have to do is construct it to run
   * @param    list     $extra_include     Extra files that we want to include
   *
   * @author   ccheever
   * @author   dcorson
   */
  function __construct($output_from_includes='', $do_color, $do_autocomplete,
      $comm_filename) {
    $this->_comm_handle = fopen($comm_filename, 'w');

    $this->__send_autocomplete_identifiers($do_autocomplete);

    // now it's safe to send any output the includes generated
    print $output_from_includes;
    fwrite($this->_comm_handle, "ready\n");

    $this->_interactive_loop($do_color);
  }

  /**
   * Destructor - just closes the handle to STDIN
   *
   * @author    ccheever
   */
  function __destruct() {
    fclose($this->_handle);
  }

  /**
   * Sends the list of identifiers that phpshell should know to tab-complete to python
   *
   * @author    ccheever
   */
  function __send_autocomplete_identifiers($do_autocomplete) {
    // send special string to signal that we're sending the autocomplete identifiers
    print "#start_autocomplete_identifiers\n";

    if ($do_autocomplete) {
      // send function names -- both user defined and built-in
      // globals, constants, classes, interfaces
      $defined_functions = get_defined_functions();
      $methods = array();
      foreach (($classes = get_declared_classes()) as $class) {
        foreach (get_class_methods($class) as $class_method) {
          $methods[] = $class_method;
        }
      }
      foreach (array_merge($defined_functions['user'], $defined_functions['internal'], array_keys($GLOBALS), array_keys(get_defined_constants()), $classes, get_declared_interfaces(), $methods, array('instanceof')) as $identifier) {
        // exclude the phpshell internal variables from the autocomplete list
        if ((substr($identifier, 0, 14) != "___phpshell___") && ($identifier != '___PHPShell___')) {
          print "$identifier\n";
        } else {
          unset($$identifier);
        }
      }
    }

    // string signalling the end of autocmplete identifiers
    print "#end_autocomplete_identifiers\n";
  }

  /**
   * The main interactive loop
   *
   * @author    ccheever
   * @author    dcorson
   *
   * We prefix our vars here to prevent accidental name collisions :(
   */
  function _interactive_loop($do_color) {
    extract($GLOBALS);

    $buf_len = 0;

    while (!feof($this->_handle)) {
      // indicate to phpsh (parent process) that we are ready for more input
      fwrite($this->_comm_handle, "ready\n");

      // multiline inputs are encoded to one line
      $buffer_enc = fgets($this->_handle, $this->_MAX_LINE_SIZE);
      $buffer = stripcslashes($buffer_enc);
      $buf_len = strlen($buffer);

      // evaluate what the user's entered
      if ($do_color) {
        print "\033[33m"; // yellow
      }
      try {
        $evalue = eval($buffer);
      } catch (Exception $e) {
        // unfortunately, almost all exceptions that aren't explicitly thrown
        // by users are uncatchable :(
        fwrite(STDERR, 'Uncaught exception: '.get_class($e).': '.
          $e->getMessage()."\n");
        $evalue = null;
      }

      // if any value was returned by the evaluated code, print it
      if (isset($evalue)) {
        if ($do_color) {
          print "\033[36m"; // cyan
        }
        if ($evalue === true) {
          print "true";
        } elseif ($evalue === false) {
          print "false";
        } else {
          print_r($evalue);
        }
        // set $_ to be the value of the last evaluated expression
        $_ = $evalue;
      }
      // back to normal for prompt
      if ($do_color) {
        print "\033[0m";
      }
      // newline so we end cleanly
      print "\n";
    }
  }
}
