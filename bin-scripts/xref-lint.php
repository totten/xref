<?php

/**
 * lib/bin-scripts/xref-lint.php
 *
 * This is a lint (a tool to find potential bugs in source code) for PHP sources.
 * This is a command-line version
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once("$includeDir/XRef.class.php");

// command-line arguments
XRef::registerCmdOption('o:', "output=",        '-o, --output=TYPE',    "either 'text' (default) or 'json'");
XRef::registerCmdOption('r:', "report-level=",  '-r, --report-level=',  "either 'error', 'warning' or 'notice'");
XRef::registerCmdOption('',   "no-cache",       '--no-cache',           "don't use lint cache, if any");
XRef::registerCmdOption('',   "init",           '--init',               "create a config file, init cache");
XRef::registerCmdOption('',   "git",            '--git',                "git pre-commit mode: find new errors in modified tracked files");
XRef::registerCmdOption('',   "git-cached",     '--git-cached',         "git pre-commit mode: compare files cached for commit with HEAD");
XRef::registerCmdOption('',   "git-rev=",       '--git-rev=<rev> or --git-rev=<from>:<to> ',
    array("", "find errors in revision <rev>, or find errors added since rev <from> to rev <to>")
);

try {
    list ($options, $arguments) = XRef::getCmdOptions();
} catch (Exception $e) {
    error_log($e->getMessage());
    error_log("See 'xref-lint --help'");
    exit(1);
}

if (XRef::needHelp()) {
    XRef::showHelpScreen(
        "xref-lint - tool to find problems in PHP source code",
        "$argv[0] [options] [files to check]"
    );
    exit(1);
}

if (isset($options['init'])) {
    $xref = new XRef();
    $xref->init();
    exit(0);
}

//
// report-level:  errors, warnings or notices
// Option -r <value> is a shortcut for option -d lint.report-level=<value>
if (isset($options['report-level'])) {
    XRef::setConfigValue("lint.report-level", $options['report-level']);
}

//
// output-format: text or json
//
$outputFormat = 'text';
if (isset($options['output'])) {
    $outputFormat = $options['output'];
}
if ($outputFormat != 'text' && $outputFormat != 'json') {
    die("unknown output format: $outputFormat");
}

// git mode:
if ( (isset($options['git-cached']) && $options['git-cached'])
    || (isset($options['git-rev']) && $options['git-rev'])
)
{
    $options['git'] = true;
}

if (isset($options['git']) && $options['git']) {
    if ($arguments) {
        error_log("warning: filenames to be checked are ignored in git mode");
    }
}

// no internal cache - mostly for unittests
$use_cache = true;
if (isset($options['no-cache']) && $options['no-cache']) {
    $use_cache = false;
}

//
// color: on/off/auto, for text output to console only
//
$color = XRef::getConfigValue("lint.color", 'auto');
if ($color === "auto") {
    $color = function_exists('posix_isatty') && posix_isatty(STDOUT);
}
$colorMap = array(
    "fatal"     => "\033[0;31m",
    "error"     => "\033[0;31m",
    "warning"   => "\033[0;33m",
    "notice"    => "\033[0;32m",
    "_off"      => "\033[0;0m",
);

$filesWithDefects   = 0;
$numberOfNotices    = 0;
$numberOfWarnings   = 0;
$numberOfErrors     = 0;


$xref = new XRef();
$xref->loadPluginGroup("lint");

$total_report = array();

$exclude_paths = XRef::getConfigValue("project.exclude-path", array());

$lint_engine = XRef::getConfigValue("xref.project-check", true)
        ? new XRef_LintEngine_ProjectCheck($xref, $use_cache)
        : new XRef_LintEngine_Simple($xref, $use_cache);

if (isset($options['git']) && $options['git']) {
    $old_rev = null;
    $new_rev = null;
    if (isset($options['git-rev']) && $options['git-rev']) {
        // find errors in specific revision or compare two revisions
        $r = preg_split('#:#', $options['git-rev']);
        if (count($r) == 2) {
            // --git-rev=<from>:<to>
            list($old_rev, $new_rev) = $r;
        } elseif (count($r) == 1) {
            // --git-rev=<revision>
            $old_rev = $r[0];
        } else {
            throw new Exception("Invalid revision specification: " . $options['git-rev']);
        }
    } else {
        // incremental (diff) mode: find errors in files modified since HEAD revision
        // compare either HEAD:<current state of disk> or HEAD:<current state of cache>
        $old_rev = XRef_SourceCodeManager_Git::HEAD;
        $new_rev = (isset($options['git-cached']) && $options['git-cached']) ?
            XRef_SourceCodeManager_Git::CACHED : XRef_SourceCodeManager_Git::DISK;
    }
    $scm = $xref->getSourceCodeManager(); // TODO: check this is the git scm

    if ($old_rev && $new_rev) {
        // incremental mode: find errors, that are new in <$new_rev> since <$old_rev>
        $file_provider_old = $scm->getFileProvider( $old_rev );
        $file_provider_new = $scm->getFileProvider( $new_rev );
        $file_provider_old->excludePaths($exclude_paths);
        $file_provider_new->excludePaths($exclude_paths);
        $modified_files = $scm->getListOfModifiedFiles($old_rev, $new_rev);
        $total_report = $lint_engine->getIncrementalReport($file_provider_old, $file_provider_new, $modified_files);
    } else {
        // regular mode - find all errors in revision <$old_rev>
        $file_provider = $scm->getFileProvider( $old_rev );
        $file_provider->excludePaths($exclude_paths);
        $total_report = $lint_engine->getReport($file_provider);
    }
} else {
    // main loop over all files
    // list of files to check (in order):
    //  1) command-line arguments, 2) config-defined project.source-code-dir 3) current dir
    $paths = $arguments;
    if (!$paths) {
        $paths =  XRef::getConfigValue("project.source-code-dir", array());
    }
    if (!$paths) {
        $paths = array(".");
    }
    $file_provider = new XRef_FileProvider_FileSystem( $paths );
    $file_provider->excludePaths($exclude_paths);
    $total_report = $lint_engine->getReport($file_provider);
}

// calculate some stats
foreach ($total_report as $file_name => $report) {
    $filesWithDefects++;
    foreach ($report as $code_defect) {
        if ($code_defect->severity == XRef::NOTICE) {
            $numberOfNotices++;
        } elseif ($code_defect->severity == XRef::WARNING) {
            $numberOfWarnings++;
        } elseif ($code_defect->severity == XRef::ERROR || $code_defect->severity == XRef::FATAL) {
            $numberOfErrors++;
        } else {
            error_log("Unknown severity: $code_defect->severity");
        }
    }
}

// output the report
if ($outputFormat=='text') {
    foreach ($total_report as $file_name => $report) {
        echo "File: $file_name\n";
        foreach ($report as $code_defect) {
            $lineNumber     = $code_defect->lineNumber;
            $severityStr    = XRef::$severityNames[ $code_defect->severity ];
            $line = sprintf("    line %4d: %-8s (%s): %s", $lineNumber, $severityStr, $code_defect->errorCode, $code_defect->message);
            if ($color) {
                $line = $colorMap{$severityStr} . $line . $colorMap{"_off"};
            }
            echo $line . "\n";
        }
    }
    // print stats
    if (XRef::verbose()) {
        $stats = $lint_engine->getStats();
        echo("Total files:          {$stats['total_files']}\n");
        echo("Files parsed:         {$stats['parsed_files']}\n");
        echo("Cache hits:           {$stats['cache_hit']}\n");
        echo("Files with defects:   $filesWithDefects\n");
        echo("Errors:               $numberOfErrors\n");
        echo("Warnings:             $numberOfWarnings\n");
        echo("Notices:              $numberOfNotices\n");
    }
} else {
    $jsonOutput = array();
    foreach ($total_report as $file_name => $report) {
        foreach ($report as $code_defect) {
            $lineNumber     = $code_defect->lineNumber;
            $tokenText      = $code_defect->tokenText;
            $severityStr    = XRef::$severityNames[ $code_defect->severity ];
            $jsonOutput[] = array(
                'fileName'      => $file_name,
                'lineNumber'    => $code_defect->lineNumber,
                'tokenText'     => $code_defect->tokenText,
                'severityStr'   => $severityStr,
                'errorCode'     => $code_defect->errorCode,
                'message'       => $code_defect->message,
            );
        }
    }
    echo json_encode($jsonOutput); // JSON_PRETTY_PRINT is not available before php 5.4 :(
}


if ($numberOfErrors+$numberOfWarnings > 0) {
    exit(1);
} else {
    exit(0);
}


// vim: tabstop=4 expandtab

