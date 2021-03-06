<?php

/**
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$includeDir =  ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) : "@php_dir@/XRef";
require_once "$includeDir/lib/interfaces.php";
require_once "$includeDir/lib/parsers.php";

/**
 *
 * This is a main class, it contains constant declarations, utility methods etc.
 * Actual work is done by library/plugin modules.
 */
class XRef {

    // Enums: file types
    const FILETYPE_PHP = 17;
    const FILETYPE_AS3 = 42;

    // Enums: token kinds
    const T_ONE_CHAR    = 65000; // this is for tokens containing one characker only, e.g. '(', '{' etc
    const T_PACKAGE     = 65001; // AS3 specific
    const T_NULL        = 65002;
    const T_IMPORT      = 65003;
    const T_OVERRIDE    = 65004;
    const T_IN          = 65005;
    const T_EACH        = 65006;
    const T_GET         = 65007;
    const T_SET         = 65008;
    const T_TRUE        = 65009;
    const T_FALSE       = 65010;
    const T_REGEXP      = 65011; // regexp literal for AS3

    // compat mode
    // it's possible to some extend parse PHP 5.3+ code in PHP 5.2 runtime
    // however, have to define missing constants
    public static $compatMode = array();
    static $compatConstants = array(
        "T_NAMESPACE"       => 65100,
        "T_NS_SEPARATOR"    => 65101,
        "T_USE"             => 65102,
        "T_TRAIT"           => 65103,
        "T_GOTO"            => 65104,
    );

    static $tokenNames = array(
        XRef::T_PACKAGE     => "T_PACKAGE",
        XRef::T_NULL        => "T_NULL",
        XRef::T_IMPORT      => "T_IMPORT",
        XRef::T_OVERRIDE    => "T_OVERRIDE",
        XRef::T_IN          => "T_IN",
        XRef::T_EACH        => "T_EACH",
        XRef::T_GET         => "T_GET",
        XRef::T_SET         => "T_SET",
        XRef::T_TRUE        => "T_TRUE",
        XRef::T_FALSE       => "T_FALSE",
        XRef::T_REGEXP      => "T_REGEXP",
    );

    // Enums: lint severity levels
    const NOTICE    = 1;
    const WARNING   = 2;
    const ERROR     = 3;
    const FATAL     = 4;   // e.g. can't parse file

    static $severityNames = array(
        XRef::FATAL     => "fatal",
        XRef::NOTICE    => "notice",
        XRef::WARNING   => "warning",
        XRef::ERROR     => "error",
    );

    // bitmasks for attributes fields
    // e.g. public static function ...
    const MASK_PUBLIC     = 1;
    const MASK_PROTECTED  = 2;
    const MASK_PRIVATE    = 4;
    const MASK_STATIC     = 8;
    const MASK_ABSTRACT   = 16;
    const MASK_FINAL      = 32;
    // map: token kind --> bitmask
    static $attributesMasks = array(
        T_PUBLIC    => XRef::MASK_PUBLIC,
        T_PROTECTED => XRef::MASK_PROTECTED,
        T_PRIVATE   => XRef::MASK_PRIVATE,
        T_STATIC    => XRef::MASK_STATIC,
        T_ABSTRACT  => XRef::MASK_ABSTRACT,
        T_FINAL     => XRef::MASK_FINAL,
    );


    /** error code & message for fatal "can't parse file" error */
    const ERROR_CODE_CANT_PARSE_FILE = "xr001";
    const ERROR_MESSAGE_CANT_PARSE_FILE = "Can't parse file (%s)";

    /**
     * special filename for project errors that are not specific to any file,
     * e.g. a class is defined twice in the project
     */
    const DUMMY_PROJECT_FILENAME = "(project)";

    /** constructor */
    public function __construct() {
        spl_autoload_register(array($this, "autoload"), true);

        // compat mode
        foreach (self::$compatConstants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
                self::$tokenNames[$value] = $name;
                self::$compatMode[$name] = true;
            } elseif (token_name(constant($name))=="UNKNOWN") {
                // oops, someone (e.g. phpunit) but not PHP core
                // has defined this constant
                // don't define it again to prevent "redefine" warning
                self::$tokenNames[ constant($name) ] = $name;
                self::$compatMode[$name] = true;
            }
        }
    }

    public static function version() {
        return "1.1.1";
    }

    /*----------------------------------------------------------------
     *
     * PLUGIN MANAGEMENT FUNCTIONS
     *
     ---------------------------------------------------------------*/

    /** map (file extension --> parser object), e.g. ('php' => $aPhpParser) */
    protected $parsers      = array();

    /** map (plugin id --> XRef_IPlugin object) */
    protected $plugins      = array();

    /**
     * Returns a list of plugin objects that implements given interface.
     * If no interface name is given, all registered (loaded) plugins will be returned.
     *
     * @param string $interfaceName e.g. "XRef_IDocumentationPlugin"
     * @return XRef_IPlugin[]
     */
    public function getPlugins($interfaceName = null) {
        if (is_null($interfaceName)) {
            return $this->plugins;
        } else {
            $plugins = array();
            foreach ($this->plugins as $id => $plugin) {
                if (is_a($plugin, $interfaceName)) {
                    $plugins[$id] = $plugin;
                }
            }
            return $plugins;
        }
    }

    /**
     * Internal method that registers a given plugin object.
     * Throws exception if plugin with the same ID is already registered.
     * @param XRef_IPlugin $plugin
     */
    private function addPlugin(XRef_IPlugin $plugin) {
        $pluginId = $plugin->getId();
        if (array_key_exists($pluginId, $this->plugins)) {
            throw new Exception("Plugin '$pluginId' is already registered");
        } else {
            $plugin->setXRef($this);
            $this->plugins[$pluginId] = $plugin;
        }
    }

    /**
     * Internal method; it's made public for writing unit tests only
     *
     * @internal
     * @param XRef_IFileParser $parser
     */
    public function addParser(XRef_IFileParser $parser) {
        $extensions = $parser->getSupportedFileExtensions();
        foreach ($extensions as $ext) {
            // should it be case-insensitive?
            $ext = strtolower(preg_replace("#^\\.#", "", $ext));
            if (array_key_exists($ext, $this->parsers)) {
                $p = $this->parsers[$ext];
                $old_class = get_class($p);
                $new_class = get_class($parser);
                throw new Exception("Parser for file extenstion '$ext' already exists ($old_class/$new_class)");
            } else {
                $this->parsers[$ext] = $parser;
            }
        }
    }

    /**
     * Returns a registered (loaded) plugin by its id
     *
     * @param string $pluginId
     * @return XRef_IPlugin
     */
    public function getPluginById($pluginId) {
        return $this->plugins[$pluginId];
    }

    /**
     * Method to load plugins defined in config file.
     * For the name $groupName, plugins/parsers config-defined as $groupName.plugins[] and $groupName.parsers[] will be loaded.
     *
     * @param string $groupName
     */
    public function loadPluginGroup($groupName) {
        $isGroupEmpty = true;

        foreach (self::getConfigValue("$groupName.parsers", array()) as $parserClassName) {
            $parser = new $parserClassName();
            $this->addParser($parser);
            $isGroupEmpty = false;
        }

        foreach (XRef::getConfigValue("$groupName.plugins", array()) as $pluginClassName) {
            $plugin = new $pluginClassName();
            $this->addPlugin($plugin);
            $isGroupEmpty = false;
        }

        if ($isGroupEmpty) {
            throw new Exception("Group '$groupName' is empty - no plugins or parsers are defined");
        }
    }

    /** method is needed for unit tests only to reset the list of plugins and/or re-create them */
    public function resetPlugins() {
        $this->plugins = array();
        $this->parsers = array();
        $this->storageManager = null;
    }

    /**
     * @return XRef_ISourceCodeManager
     */
    public function getSourceCodeManager() {
        $scmClass = self::getConfigValue("ci.source-code-manager");
        return new $scmClass();
    }

    private $storageManager;

    /**
     * @return XRef_IPersistentStorage
     */
    public function getStorageManager() {
        if (!isset($this->storageManager)) {
            $managerClass = self::getConfigValue("xref.storage-manager");
            $this->storageManager = new $managerClass();
        }
        return $this->storageManager;
    }

    /** directory where the cross-reference will be stored */
    public function setOutputDir($outputDir) {
        $this->outputDir = $outputDir;
        self::createDirIfNotExist($outputDir);
    }

    /**
     * Method finds parser for given fileType and returns parsed file object
     * If $content is null, it will be read from $filename
     * TODO: made fileType optional param - take it from filename
     *
     * @param string $filename
     * @param string $content
     * @return XRef_IParsedFile
     */
    public function getParsedFile($filename, $content = null) {
        $file_type = strtolower( pathinfo($filename, PATHINFO_EXTENSION) );
        $parser = isset($this->parsers[$file_type]) ? $this->parsers[$file_type] : null;
        if (!$parser) {
            throw new Exception("No parser is registered for filetype $file_type ($filename)");
        }
        if (is_null($content)) {
            $content = file_get_contents($filename);
        }

        $pf = $parser->parse( $content, $filename );
        return $pf;
    }

    /**
     * autoload handler, it's set in constructor
     */
    public function autoload($className) {
        $searchPath = self::getConfigValue("xref.plugins-dir", array());
        $searchPath[] = dirname(__FILE__) . "/lib";
        $fileName = str_replace('_', '/', $className) . ".class.php";

        foreach ($searchPath as $dirName) {
            $fullFileName = "$dirName/$fileName";
            if (file_exists($fullFileName)) {
                require_once $fullFileName;
                return;
            }
        }
        // TODO: don't use PHP autoload
        // Smarty v3 uses it too which makes proper error reporting impossible - if a plugin is not loaded,
        // maybe it's Smarty plugin and should be loaded by it. Or maybe not, who knows.
        //
        //$message = "Can't autoload class '$className': file $fileName not found in " . implode(", ", $searchPath);
        //error_log($message);
        //throw new Exception($message); // looks like exceptions don't work inside autoload functions?
    }


    /*----------------------------------------------------------------
     *
     * CROSS-REFERENCE DOCUMENTATION
     *
     ---------------------------------------------------------------*/

    protected $outputDir;


    /**
     * Utility method to create file names for given reportId/objectId report
     *
     * @param string $reportId, e.g. 'php-classes'
     * @param string $objectId, e.g. 'SomeClassName'
     * @param string $extension
     * @return tuple("php-classes/SomeClassName.html", "../")
     */
    protected function getOutputPath($reportId, $objectId, $extension = "html") {
        if ($objectId==null) {
            return array("$this->outputDir/$reportId.$extension", "");
        } else {
            $filename = $this->getFileNameForObjectID($objectId, $extension);

            $dirs = preg_split("#/#", $filename);
            $htmlRoot = '';
            $filePath = "$this->outputDir/$reportId";
            for ($i=0; $i<count($dirs); ++$i)   {
                self::createDirIfNotExist($filePath);
                $filePath = $filePath . "/" . $dirs[$i];
                $htmlRoot .= "../";
            }
            return array($filePath, $htmlRoot);
        }
    }

    /**
     * Utility method to open a file handle for given reportId/objectId; caller is responsible for closing the file.
     * See method getOutputPath above
     *
     * @param string $reportId
     * @param string $objectId
     * @param string $extension
     * @return tuple(resource $fileHanle, string $pathToReportRootDir)
     */
    public function getOutputFileHandle($reportId, $objectId, $extension = "html") {
        list ($filename, $htmlRoot) = $this->getOutputPath($reportId, $objectId, $extension);
        $fh = fopen($filename, "w");
        if (!$fh) {
            throw new Exception("Can't write to file '$filename'");
        }
        return array($fh, $htmlRoot);
    }

    /**
     * Translates object name into report file name,
     * e.g. "..\Console\Getopt.php" --> "--/Console/Getopt.php.html";
     *
     * @param string $objectId
     * @param string $extension
     * @return string
     */
    protected function getFileNameForObjectID($objectId, $extension="html") {
        // TODO: create case insensitive file names for Windows
        $objectId = preg_replace("#\\\\#", '/', $objectId);
        $objectId = preg_replace("#[^a-zA-Z0-9\\.\\-\\/]#", '-', $objectId);
        $objectId = preg_replace("#\\.\\.#", '--', $objectId);
        return "$objectId.$extension";
    }

    /**
     * utility method to create dir/throw exception on errors
     * @param string $dirName
     */
    public static function createDirIfNotExist($dirName) {
        if (!is_dir($dirName)) {
            if (!mkdir($dirName)) {
                throw new Exception("Can't create dir $dirName!");
            }
        }
    }

    /*----------------------------------------------------------------
     *
     * Cross-reference (cross-plugin support):
     * links from one object/report to another one
     *
     * ---------------------------------------------------------------*/

    /**
     * Creates a link (string) to given reportId/objectId report
     *
     * @param string $reportId, e.g. "files"
     * @param string $objectId, e.g. "Server/game/include/Some.Class.php"
     * @param string $root,     e.g. "../"
     * @param string $anchor    e.g. "line120"
     * @return string           "../files/Server/game/include/Some.Class.php.html#line120"
     */
    public function getHtmlLinkFor($reportId, $objectId, $root, $anchor=null) {
        if (isset($objectId)) {
            $filename = $this->getFileNameForObjectID($objectId);
            $link = $root . "$reportId/$filename";
        } else {
            $link = $root . "$reportId.html";
        }
        if ($anchor) {
            $link .= "#$anchor";
        }
        return $link;
    }

    // list of links from source file to other reports
    //  $linkDatabase[ $filename ][ startTokenIndex ]   = array(report data)
    //  $linkDatabase[ $filename ][ endTokenIndex ]     = 0;
    protected $linkDatabase = array();

    public function addSourceFileLink(XRef_FilePosition $fp, $reportName, $reportObjectId) {
        if (!array_key_exists($fp->fileName, $this->linkDatabase)) {
            $this->linkDatabase[$fp->fileName] = array();
        }
        // TODO: this is ugly, rewrite this data structure
        // current syntax:
        //  if element is array(report, id),    then this is an open link   <a href="report/id">
        //  if element is 0,                    then this a closing tag     </a>
        $this->linkDatabase[$fp->fileName][$fp->startIndex] = array($reportName, $reportObjectId);
        $this->linkDatabase[$fp->fileName][$fp->endIndex+1] = 0;
    }

    public function &getSourceFileLinks($fileName) {
        return $this->linkDatabase[$fileName];
    }

    /*----------------------------------------------------------------
     *
     * LINT SUPPORT CODE
     *
     * ---------------------------------------------------------------*/


    public function filterFiles($files) {
        // TODO(?): use extension list from parser plugins
        $extensions = array('php' => true );
        $filtered_files = array();

        foreach ($files as $f) {
            $ext = strtolower( pathinfo($f, PATHINFO_EXTENSION) );
            if (! isset($extensions[$ext])) {
                continue;
            }
            $filtered_files[] = $f;
        }
        return $filtered_files;
    }

    /** $lintReportLevel: XRef::ERROR, XRef::WARNING etc */
    protected $lintReportLevel = null;

    /** map error_code -> Array error_description */
    protected $lintErrorMap = null;

    /** map error_code -> true */
    protected $lintIgnoredErrors = null;

    /**
    * Affects what kind of defects the lint plugins will report.
    *
    * @param int $reportLevel - one of constants XRef::NOTICE, XRef::WARNING or XRef::ERROR
    * @return void
    */
    public function setLintReportLevel($reportLevel) {
        $this->lintReportLevel = $reportLevel;
    }


    // experimental
    public function getProjectReport(XRef_IProjectDatabase $db) {
        $plugins = $this->getPlugins("XRef_IProjectLintPlugin");
        $report = array();
        foreach ($plugins as /** @var $plugin XRef_IProjectLintPlugin */ $plugin) {
            $plugin_report = $plugin->getProjectReport($db);
            $report = array_merge_recursive($report, $plugin_report);
        }

        return $report;
    }

    /**
     * @param array $report - array(filename => list of XRef_CodeDefects)
     */
    public function sortAndFilterReport(array $report) {
        // init once
        if (is_null($this->lintIgnoredErrors)) {
            $this->lintIgnoredErrors = array();
            foreach (self::getConfigValue("lint.ignore-error", array()) as $error_code) {
                $this->lintIgnoredErrors[$error_code] = true;
            }
        }

        // also init once
        if (is_null($this->lintReportLevel)) {
            $r = XRef::getConfigValue("lint.report-level", "warning");
            if ($r == "errors" || $r == "error") {
                $reportLevel = XRef::ERROR;
            } elseif ($r == "warnings" || $r == "warning") {
                $reportLevel = XRef::WARNING;
            } elseif ($r == "notice" || $r == "notices") {
                $reportLevel = XRef::NOTICE;
            } elseif (is_numeric($r)) {
                $reportLevel = (int) $r;
            } else {
                throw new Exception("unknown value for config var 'lint.report-level': $r");
            }
            $this->lintReportLevel = $reportLevel;
        }

        $filtered_report = array();
        foreach ($report as $file_name => $defects_list) {
            $filtered_list = array();
            foreach ($defects_list as /** @var XRef_CodeDefect $d */$d) {
                if ($d->severity < $this->lintReportLevel) {
                    continue;
                }
                if (isset($this->lintIgnoredErrors[ $d->errorCode ])) {
                    continue;
                }
                $filtered_list[] = $d;
            }

            if ($filtered_list) {
                $filtered_report[$file_name] = $filtered_list;
            }

        }
        ksort($filtered_report); // sort report by file names
        foreach ($filtered_report as $file_name => $r) {
            // sort by line numbers
            usort($filtered_report[$file_name], array("XRef", "_sortLintReportByLineNumber"));
        }
        return $filtered_report;
    }

    static function _sortLintReportByLineNumber ($a, $b) {
        $la = $a->lineNumber;
        $lb = $b->lineNumber;
        if ($la==$lb) {
            return 0;
        } elseif ($la>$lb) {
            return 1;
        } else {
            return -1;
        }
    }

    /*----------------------------------------------------------------
     *
     * CONFIG FILE METHODS
     *
     * ---------------------------------------------------------------*/
    private static $config;
    private static $configFileName = null;

    public static function setConfigFileName($value = null) {
        self::$configFileName = $value;
    }

    /**
     * @return string - the name of the config file
     */
    private static function getConfigFilename() {
        $filename = self::$configFileName;

        if (!$filename) {
            // get name of config file from command-line args (-c, --config)
            if (self::$options && isset(self::$options["config"])) {
                $filename = self::$options["config"];
            }
        }

        // get config filename from environment
        if (!$filename) {
            $filename = getenv("XREF_CONFIG");
        }

        // config file in .xref dir in current dir, or in any parent dirs
        if (!$filename) {
            $dir = getcwd();
            while ($dir) {
                if (file_exists("$dir/.xref/xref.ini")) {
                    $filename = "$dir/.xref/xref.ini";
                    break;
                }
                $parent_dir = dirname($dir); // one step up
                if ($parent_dir == $dir) {
                    // top level, can't go up
                    break;
                }
                $dir = $parent_dir;
            }
        }

        // special value for filename - means don't read any config file
        if ($filename && $filename == 'default') {
            $filename = null;
        }

        return $filename;
    }

    /**
     * @return array - the key/value pairs read from config file
     */
    public static function &getConfig($forceReload = false) {
        if (self::$config && !$forceReload) {
            return self::$config;
        }

        $filename = self::getConfigFilename();

        if ($filename) {
            if (XRef::verbose()) {
                echo "Using config $filename\n";
            }
            $ini = parse_ini_file($filename, true);
            if (!$ini) {
                throw new Exception("Error: can parse ini file '$filename'");
            }
        } else {
            // if no file explicitly specified, and default config doesn't exist,
            // don't throw error and provide default config values
            if (XRef::verbose()) {
                echo "Using default config\n";
            }
            $ini = array();
        }


        $config = array();
        foreach ($ini as $sectionName => $section) {
            foreach ($section as $k => $v) {
                $config["$sectionName.$k"] = $v;
            }
        }

        // default config values are for command-line xref tool only;
        // you have to specify a real config for xref-doc, CI and web-based tools
        $defaultConfig = array(
            'project.exclude-path'  => array(),

            'xref.storage-manager'  => 'XRef_Storage_File',
            'xref.project-check'    => true,

            'doc.parsers'           => array('XRef_Parser_PHP'),
            'doc.plugins'           => array(
                'XRef_Doc_ClassesPHP',
                'XRef_Doc_MethodsPHP',
                'XRef_Doc_PropertiesPHP',
                'XRef_Doc_ConstantsPHP',
                'XRef_Doc_SourceFileDisplay',
                'XRef_Lint_UninitializedVars',
                'XRef_Lint_LowerCaseLiterals',
                'XRef_Lint_StaticThis',
                'XRef_Lint_ClosingTag',
                'XRef_Doc_LintReport',          // this plugin creates a documentation page with list of errors found by 3 lint plugins above
             ),

            'lint.color'            => 'auto',
            'lint.report-level'     => 'warnings',
            'lint.parsers'          => array('XRef_Parser_PHP'),
            'lint.plugins'          => array(
                'XRef_Lint_UninitializedVars',
                'XRef_Lint_LowerCaseLiterals',
                'XRef_Lint_StaticThis',
                'XRef_Lint_AssignmentInCondition',
                'XRef_Lint_ClosingTag',
                'XRef_ProjectLint_CheckClassAccess',
                'XRef_ProjectLint_MissedParentConstructor',
                'XRef_ProjectLint_FunctionSignature',
                'XRef_Doc_SourceFileDisplay',   // it's needed for web version of lint tool to display formatted source code
            ),
            'lint.add-constant'             => array(),
            'lint.add-function-signature'   => array(),
            'lint.add-global-var'           => array(),
            'lint.ignore-missing-class'     => array('PEAR', 'PHPUnit_Framework_TestCase'), // most commonly used library classes
            'lint.ignore-error'       => array(),
            'lint.check-global-scope' => true,

            'ci.source-code-manager'  => 'XRef_SourceCodeManager_Git',
        );
        foreach ($defaultConfig as $key => $value) {
            if (!isset($config[$key])) {
                $config[$key] = $value;
            }
        }

        // override values with -d command-line option
        if (self::$options && isset(self::$options["define"])) {
            foreach (self::$options["define"] as $d) {
                list($k, $v) = explode("=", $d, 2);
                if ($v) {
                    if ($v=="true" || $v=="on") {
                        $v = true;
                    } elseif ($v=="false" || $v=="off") {
                        $v = false;
                    }
                }

                $force_array = false;
                if (substr($k, strlen($k)-2) == '[]') {
                    $force_array = true;
                    $k = substr($k, 0, strlen($k)-2);
                }
                if ($force_array || (isset($config[$k]) && is_array($config[$k]))) {
                    if ($v) {
                        $config[$k][] = $v;
                    } else {
                        $config[$k] = array();
                    }
                } else {
                    $config[$k] = $v;
                }
            }
        }

        self::$config = $config;
        return self::$config;
    }

    public function init() {

        $cwd = getcwd();
        // create data dir
        if (!file_exists(".xref")) {
            echo "Creating dir .xref\n";
            if (!mkdir(".xref")) {
                throw new Exception("Can't create dir .xref");
            }
        }

        $config_filename = ".xref/xref.ini";
        if (file_exists($config_filename)) {
            echo "Config file $config_filename exists; won't overwrite\n";
        } else {
            echo "Creating config file $config_filename\n";
            // create config file
            $fh = fopen($config_filename, "w");
            if (!$fh) {
                throw new Exception("Can't create file $config_filename");
            }
            $config = array();

            $config[] = "[project]";
            $config[] = array("name", basename($cwd));
            $config[] = array("source-code-dir[]", realpath($cwd));
            $config[] = array(";source-url", 'https://github.com/<author>/<project>/blob/{%revision}/{%fileName}#L{%lineNumber}');
            $config[] = null;

            $config[] = "[xref]";
            $config[] = array("data-dir", realpath("$cwd/.xref"));
            $config[] = array("project-check", "true");
            $config[] = array(";smarty-class", "/path/to/Smarty.class.php");
            $config[] = array(";script-url", "http://xref.your.domain.com/bin");
            $config[] = null;

            $config[] = "[lint]";
            $config[] = array("ignore-missing-class[]", 'PHPUnit_Framework_TestCase');
            $config[] = array(";ignore-error[]", 'xr052');
            $config[] = null;

            $config[] = "[doc]";
            $config[] = array(";output-dir", "/path/for/generated/doc");
            $config[] = null;

            if (file_exists(".git")) {
                // TODO: need a better way to check that this is a git project
                // http://stackoverflow.com/questions/957928
                // git rev-parse --show-toplevel
                $config[] = "[git]";
                $config[] = array("repository-dir", realpath($cwd));
                $config[] = null;

                $config[] = "[ci]";
                $config[] = array("incremental", "true");
                $config[] = array("update-repository", "true");
                $config[] = null;

                $config[] = "[mail]";
                $config[] = array("from", "XRef Continuous Integration");
                $config[] = array(";reply-to", "you@your.domain.com");
                $config[] = array(";to[]", "{%ae}");
                $config[] = array(";to[]", "you@your.domain.com");
                $config[] = null;
            }

            foreach ($config as $line) {
                $l = null;
                if (!$line) {
                    $l = "\n";
                } elseif (!is_array($line)) {
                    $l = "$line\n";
                } else {
                    list($k, $v) = $line;
                    if (preg_match('#^\\w+$#', $v)) {
                        $l = sprintf("%-24s= %s\n", $k, $v);
                    } else {
                        $v = preg_replace('#\\\\#', '\\\\', $v);
                        $l = sprintf("%-24s= \"%s\"\n", $k, $v);
                    }
                }
                fwrite($fh, $l);
            }
            fclose($fh);

            // re-read config values from the file
            self::getConfig(true);
        }

        // index files for the first time
        $this->loadPluginGroup('lint');
        $file_provider = new XRef_FileProvider_FileSystem( $cwd );
        $lint_engine = new XRef_LintEngine_ProjectCheck($this);
        $lint_engine->setShowProgressBar(true);
        $lint_engine->setRewriteCache(true);    // re-index files and refill cache
        echo "Indexing files\n";
        $lint_engine->getReport($file_provider);
        echo "\nDone.\n";
        // done
    }

    /** basic console progress bar: 10/100 files processed */
    public static function progressBar($current, $total, $text) {
        $len = strlen($text);
        $text = ($len < 60) ? str_pad($text, 60) : "..." . substr($text, $len-57);
        echo sprintf("\r%4d/%4d %s ", $current, $total, $text);
    }

    /**
     * Returns value of given key from config if defined in config, or default value if supplied, or throws exception.
     *
     * @param string $key
     * @param mixed $defaultValue
     * @return mixed
     */
    public static function getConfigValue($key, $defaultValue=null) {
        $config = self::getConfig();
        if (isset($config[$key])) {
            return $config[$key];
        }
        if (isset($defaultValue)) {
            return $defaultValue;
        }
        throw new Exception("Value of $key is not defined in config file");
    }

    /**
     * Mostly debug/test function - to set up config params to certain values.
     * Changes are not persistent. Function is used in test suite only.
     *
     * @param string $key
     * @param mixed $value
     */
    public static function setConfigValue($key, $value) {
        $config = & self::getConfig();
        $config[$key] = $value;
    }

    /*----------------------------------------------------------------
     *
     * COMMAND-LINE OPTIONS
     *
     * ---------------------------------------------------------------*/
    private static $options;
    private static $arguments;
    private static $verbose;

    // optionsList: array of arrays (shortOpt, longOpt, usage, description, isArray)
    private static $optionsList = array(
        array('c:', 'config=',  '-c, --config=FILE',    'Path to config file',          false),
        array('v',  'verbose',  '-v, --verbose',        'Be noisy',                     false),
        array('h::','help==',   '-h, --help',
            array(
                "Print this help and exit",
                "--help=defines  show list of config values",
                "--help=errors   show list of errors and their codes",
            ),
            false
        ),
        array('d:', 'define=',  '-d, --define key=val', 'Override config file values',  true),
    );

    public static function registerCmdOption($shortName, $longName, $usage, $desc, $isArray = false) {
        self::$optionsList[] = array($shortName, $longName, $usage, $desc, $isArray);
    }

    /**
     * Parses command line-arguments and returns found options/arguments.
     *
     *  input (for tests only, by default it takes real command-line option list):
     *      array("scriptname.php", "--help", "-d", "foo=bar", "--config=xref.ini", "filename.php")
     *
     *  output:
     *      array(
     *          array( "help" => true, "define" => array("foo=bar"), "config" => "xref.ini"),
     *          array( "filename.php" )
     *      );
     *
     * @return array(array $commandLineOptions, array $commandLineArguments)
     */
    public static function getCmdOptions( $test_args = null ) {

        if (is_null($test_args)) {
            if (self::$options) {
                return array(self::$options, self::$arguments);
            }

            if (php_sapi_name() != 'cli') {
                return array(array(), array());
            }
        }

        $short_options_list = array();  // array( 'h', 'v', 'c:' )
        $long_options_list = array();   // array( 'help', 'verbose', 'config=' )
        $rename_map = array();          // array( 'h' => 'help', 'c' => 'config' )
        $is_array_map = array();        // array( 'help' => false, 'define' => true, )
        foreach (self::$optionsList as $o) {
            $short = $o[0];
            $long = $o[1];
            if ($short) {
                $short_options_list[] = $short;
            }
            if ($long) {
                $long_options_list[] = $long;
            }
            $short = preg_replace('/\W+$/', '', $short); // remove ':' and '=' at the end of specificatios
            $long = preg_replace('/\W+$/', '', $long);
            if ($short && $long) {
                $rename_map[ $short ] = $long;
            }
            $is_array_map[ ($long) ? $long : $short ] = $o[4];
        }

        // * DONE: write a better command-line parser
        // * too bad: the code below (from official Console/Getopt documentation) doesn't work in E_STRICT mode
        // * and Console_GetoptPlus is not installed by default on most systems :(
        // $error_reporting = error_reporting();
        // error_reporting($error_reporting & ~E_STRICT);
        // require_once 'Console/Getopt.php';
        // $getopt = new Console_Getopt();
        // $args = ($test_args) ? $test_args : $getopt->readPHPArgv();
        // $getoptResult = $getopt->getopt( $args, implode('', $short_options_list), $long_options_list);
        // if (PEAR::isError($getoptResult)) {
        //     throw new Exception( $getoptResult->getMessage() );
        // }
        // error_reporting($error_reporting);
        // * end of Console_Getopt dependent code

        global $argv;
        $args = ($test_args) ? $test_args : $argv;
        $getopt_result = self::myGetopt($args, implode('', $short_options_list), $long_options_list);
        if (!is_array($getopt_result)) {
            throw new Exception($getopt_result);
        }

        $options = array();
        list($opt_list, $arguments) = $getopt_result;
        foreach ($opt_list as $o) {
            list($k, $v) = $o;
            // change default value for command-line options that doesn't require a value
            if (is_null($v)) {
                $v = true;
            }
            // '-a' --> 'a', '--foo' -> 'foo'
            $k = preg_replace('#^-+#', '', $k);

            // force long option names
            if (isset($rename_map[$k])) {
                $k = $rename_map[$k];
            }

            if ($is_array_map[$k]) {
                if (!isset($options[$k])) {
                    $options[$k] = array();
                }
                $options[$k][] = $v;
            } else {
                $options[$k] = $v;
            }
        }

        self::$options = $options;
        self::$arguments = $arguments;
        return array($options, $arguments);
    }

    const
        OPT_REQ_VALUE = 1,
        OPT_MAY_VALUE = 2,
        OPT_NO_VALUE = 3;

    /**
     * This is a replacement for Console_Getopt()
     *
     * The wheel is reinvented here because other options were:
     * 1) getopt()
     *      "=" as argument/value separator is added only in php 5.3+
     * 2) Console_GetOpt
     *      recommended code from examples "if (PEAR::isError($result))"
     *      dies in strict mode in php 5.5
     * 3) Console_GetoptPlus
     *      has only PEAR distribution, which requires extra steps on MacOS
     *      (PEAR php_dir is not in "include_path" by default
     * 4) Composer's ulrichsg/getopt-php
     *      requires PHP 5.3+
     *
     * So, if you need code to parse command-line arguments that works in
     * range of php 5.2 - php 5.5, feel free to take it from here.
     * Or invent your own wheel.
     *
     * @param string $short_options - e.g. 'a:b::c'
     *      ('-a' requires value, for '-b' value is optional, '-c' takes no values)
     * @param array $long_options, e.g. array('foo=', 'bar==', 'baz')
     *      ":" and "=" are fully interchangeable
     * @param array $args
     * @return array|string - returns either
     *  string with error message or
     *  array($options, $arguments)
     */
    public static function myGetopt($args, $short_options, $long_options) {
        $options = array();
        $arguments = array();

        $s_options = array();   // map ('short option letter' => OPT_REQ_VALUE)
        $l_options = array();   // map ('long option name'  => OPT_NO_VALUE)

        // 1. parse specification for short options
        $s_chars = preg_split('##', $short_options, -1, PREG_SPLIT_NO_EMPTY);
        while ($s_chars) {
            $char = array_shift($s_chars);
            if (!preg_match('#[A-Za-z]#', $char)) {
                return "Invalid short option specification ($short_options, $char)";
            }

            $spec = self::OPT_NO_VALUE;
            if ($s_chars && ($s_chars[0] == ':' || $s_chars[0] == '=')) {
                $spec = self::OPT_REQ_VALUE;
                array_shift($s_chars);
                if ($s_chars && ($s_chars[0] == ':' || $s_chars[0] == '=')) {
                    $spec = self::OPT_MAY_VALUE;
                    array_shift($s_chars);
                }
            }
            if (isset($s_options[$char])) {
                return "Duplicate short option specification ($short_options, $char)";
            }
            $s_options[$char] = $spec;
        }

        // 2. parse long option specifications
        foreach ($long_options as $long_name) {
            if (!preg_match('#^([A-Za-z0-9_-]+)([:=]{0,2})$#', $long_name, $matches)) {
                return "Invalid long option specification: $long_name";
            }
            $name = $matches[1];
            switch (strlen($matches[2])) {
                case 0: $spec = self::OPT_NO_VALUE; break;
                case 1: $spec = self::OPT_REQ_VALUE; break;
                case 2: $spec = self::OPT_MAY_VALUE; break;
                default: return "Invalid long option specification: $long_name";
            }
            if (isset($l_options[$name])) {
                return "Duplicate long option specification ($long_name)";
            }
            $l_options[$name] = $spec;
        }

        // 3. parse command-line arguments
        array_shift($args); // remove the first argument - the script name
        while (count($args)) {
            $arg = array_shift($args);
            if ($arg == '--') {
                // -- arg
                $arguments = array_merge($arguments, $args);
                break;
            } elseif (strlen($arg) > 2 && substr($arg, 0, 2) == '--') {
                // long option:
                //  --foo
                //  --foo=bar
                //  --foo bar
                $name = substr($arg, 2);
                $value = null;
                if (preg_match('#^(.*?)=(.*)$#', $name, $matches)) {
                    // --foo=bar
                    // --foo=
                    $name = $matches[1];
                    $value = $matches[2];
                }

                if (!isset($l_options[$name])) {
                    return "Unknown option '--$name'";
                }

                if ($l_options[$name] == self::OPT_REQ_VALUE) {
                    // long option requires a value
                    // --long=value     // ok
                    // --long value     // ok
                    // --long <EOF>     // error
                    if (is_null($value)) {
                        if (!$args) {
                            return "Option '--$name' requires a value";
                        } else {
                            $value = array_shift($args);
                        }
                    }
                } elseif ($l_options[$name] == self::OPT_MAY_VALUE) {
                    // long option may have an optional value
                    // --long=value     // ok
                    // --long value     // ok
                    // --long <EOF>     // ok, default value
                    // --long --another // ok, default value
                    if (is_null($value)) {
                        if ($args && substr($args[0], 0, 1) != '-') {
                            $value = array_shift($args);
                        } else {
                            $value = true;
                        }
                    }
                } else {
                    // long option doesn't have a valuea
                    // --long
                    // --long=value // error
                    if (is_null($value)) {
                        $value = true;
                    } else {
                        return "Option '--$name' doesn't support values";
                    }
                }
                $options[] = array($name, $value);
            } elseif (strlen($arg) > 1 && substr($arg, 0, 1) == '-') {
                // short options
                // -a
                // -a foo
                // -abc

                $chars = preg_split('##', substr($arg, 1), -1, PREG_SPLIT_NO_EMPTY);
                while ($chars) {
                    $char = array_shift($chars);
                    $value = null;
                    if (!isset($s_options[$char])) {
                       return "Unknown option '$char'";
                    }
                    if ($s_options[$char] == self::OPT_REQ_VALUE) {
                        // short option requires a value:
                        // -s foo       // ok
                        // -sfoo        // ok
                        // -s <EOF>     // error
                        if ($chars) {
                            // -sfoo
                            $value = implode($chars);
                            $chars = null;
                        } else {
                            // -s foo
                            if ($args) {
                                $value = array_shift($args);
                            } else {
                                return "Option '$char' requires a value";
                            }
                        }
                    } elseif ($s_options[$char] == self::OPT_MAY_VALUE) {
                        if ($chars) {
                            // -sfoo
                            $value = implode($chars);
                        } else {
                            if (count($args) > 0 && substr($args[0], 0, 1) != '-') {
                                // -s --other-option
                                $value = array_shift($args);
                            } else {
                                // -s foo
                                $value = true;
                            }
                        }
                    } else {
                        $value = true;
                    }
                    $options[] = array($char, $value);
                }
            } else {
                // just an argument
                $arguments[] = $arg;
            }
        }

        return array($options, $arguments);
    }

    /**
     * For CLI scripts only: if -h / --help option was in command-line arguments
     *
     * @return bool
     */
    public static function needHelp() {
        return (self::$options && isset(self::$options['help'])) ? self::$options['help'] : false;
    }

    public static function showHelpScreen($tool_name, $usage_string = null) {
        global $argv;
        if (!$usage_string) {
            $usage_string = "$argv[0] [options]";
        }

        echo "$tool_name, v. " . self::version() . "\n";
        echo "Usage:\n";
        echo "    $usage_string\n";

        if (self::needHelp() === "defines" || self::needHelp() === "define") {
            // show settings that can be changes with "--define"
            echo "List of config file settings:\n";
            self::showConfigValues();
        } elseif (self::needHelp() === "errors" || self::needHelp() === "error") {
            echo "List of errors:\n";
            self::showErrors();
        } else {
            echo "Options:\n";
            foreach (self::$optionsList as $o) {
                list($shortName, $longName, $usage, $desc) = $o;
                if (!is_array($desc)) {
                    printf("    %-20s %s\n", $usage, $desc);
                } else {
                    printf("    %-20s %s\n", $usage, array_shift($desc));
                    foreach ($desc as $d) {
                        printf("        %s\n", $d);
                    }
                }
            }

            $config_file = self::getConfigFilename();
            if (!$config_file) {
                $config_file = "(not found; using default values)";
            }
            $path_to_readme     = ('@doc_dir@' == '@'.'doc_dir@') ? dirname(__FILE__) . "/README.md"    : '@doc_dir@/XRef/README.md';
            $path_to_examples   = ('@doc_dir@' == '@'.'doc_dir@') ? dirname(__FILE__) . "/examples"     : '@doc_dir@/XRef/examples';
            $path_to_webscripts = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/web-scripts"  : "@php_dir@/XRef/web-scripts";
            echo "Locations:\n";
            echo "  config file:    $config_file\n";
            echo "  readme:         $path_to_readme\n";
            echo "  examples:       $path_to_examples\n";
            echo "  web-scripts:    $path_to_webscripts\n";
        }
    }

    protected static function showErrors() {

        // map: error code => array("message" => message, "severity" => severity)
        $errors = array(
            XRef::ERROR_CODE_CANT_PARSE_FILE => array(
                "message"   => XRef::ERROR_MESSAGE_CANT_PARSE_FILE,
                "severity"  => XRef::FATAL,
            ),
        );

        $xref = new XRef();
        $xref->loadPluginGroup('lint');
        foreach ($xref->getPlugins('XRef_ILintPlugin') as /** @var XRef_ILintPlugin $plugin */ $plugin) {
            $map = $plugin->getErrorMap();
            $errors = array_merge($errors, $map);
        }
        foreach ($xref->getPlugins('XRef_IProjectLintPlugin') as /** @var XRef_IProjectLintPlugin $plugin */ $plugin) {
            $map = $plugin->getErrorMap();
            $errors = array_merge($errors, $map);
        }

        ksort($errors);

        $format = "%-6s %-10s %s\n";
        $spacer = sprintf($format, str_repeat('-', 6), str_repeat('-', 10), str_repeat('-', 50));
        printf($format, "Code", "Severity", "Message");
        echo $spacer;
        foreach ($errors as $code => $details) {
            printf($format, $code, XRef::$severityNames[ $details["severity"] ], $details["message"]);
        }
        echo $spacer;
    }

    protected static function showConfigValues() {
        $config_values = array();   // map: name => array("type descr", "required/optional/etc");
        $path_to_readme = ('@doc_dir@' == '@'.'doc_dir@') ? dirname(__FILE__) . "/README.md" : '@doc_dir@/XRef/README.md';
        if ($fh = fopen($path_to_readme, "r")) {
            while (true) {
                $line = fgets($fh);
                if ($line === false) {
                    break;
                }
                if (preg_match('#\\* \\*\\*(\\S+)\\*\\*\\s+\\((.+);\s+(.+)\\)#', $line, $matches)) {
                    $config_values[ $matches[1] ] = array($matches[2], $matches[3]);
                }
            }
            fclose($fh);
        } else {
            throw new Exception("Can't read file '$path_to_readme'");
        }
        ksort($config_values);
        $format = "%-30s %-10s %-26s %s\n";
        $spacer = sprintf($format, str_repeat('-', 30), str_repeat('-', 10), str_repeat('-', 26), str_repeat('-', 20));
        printf($format, "Name", "Req?", "Type", "Value");
        echo $spacer;
        foreach ($config_values as $name => $l) {
            list($type, $req) = $l;
            $n = preg_replace('#\\[\\]$#', '', $name);
            $value = self::getConfigValue($n, '');
            if (is_array($value)) {
                $value = "[" . implode(", ", $value) . "]";
            } elseif ($value === true || $value === "1") {
                $value = 'true';
            } elseif ($value === false) {
                $value = 'false';
            } elseif ($value) {
                $value = "'$value'";
            } else {
                $value = '';
            }
            if (strlen($value) > 30) {
                $value = substr($value, 0, 27) . "...";
            }
            printf($format, $name, $req, $type, $value);
        }
        echo $spacer;
    }

    /**
     * @return bool
     */
    public static function verbose() {
        if (! isset(self::$verbose)) {
            self::$verbose = self::$options && isset(self::$options['verbose']) && self::$options['verbose'];
        }
        return self::$verbose;
    }

    public static function setVerbose($verbose) {
        self::$verbose = $verbose;
    }

    /*----------------------------------------------------------------
     *
     * TEMPLATE (SMARTY) METHODS
     *
     * ---------------------------------------------------------------*/

    /**
     * Method fills the given template with given template params; return the resulting text
     *
     * @param string $templateName
     * @param array $templateParams
     */
    public function fillTemplate($templateName, $templateParams) {
        $smartyClassPath = self::getConfigValue("xref.smarty-class");
        require_once $smartyClassPath;

        $smartyTmpDir = self::getConfigValue("xref.data-dir");
        self::createDirIfNotExist($smartyTmpDir);
        self::createDirIfNotExist("$smartyTmpDir/smarty");
        self::createDirIfNotExist("$smartyTmpDir/smarty/templates_c");
        self::createDirIfNotExist("$smartyTmpDir/smarty/cache");
        self::createDirIfNotExist("$smartyTmpDir/smarty/configs");

        $defaultTemplateDir = ("@data_dir@" == "@"."data_dir@") ?
            dirname(__FILE__) . "/templates/smarty" : "@data_dir@/XRef/templates/smarty";
        $templateDir = self::getConfigValue("xref.template-dir", $defaultTemplateDir);

        $smarty = new Smarty();
        if (defined("Smarty::SMARTY_VERSION") ) {
            // smarty v. 3+
            $smarty->setTemplateDir($templateDir);
            $smarty->setCompileDir("$smartyTmpDir/smarty/templates_c");
            $smarty->setCacheDir("$smartyTmpDir/smarty/cache/");
            $smarty->setConfigDir("$smartyTmpDir/smarty/configs");

            // our functions
            $smarty->registerPlugin('function', 'xref_report_link', array($this, "xref_report_link"));
            $smarty->registerPlugin('function', 'xref_severity_str', array($this, "xref_severity_str"));
        } else {
            // smarty v. 2+
            $smarty->template_dir   = $templateDir;
            $smarty->compile_dir    = "$smartyTmpDir/smarty/templates_c";
            $smarty->cache_dir      = "$smartyTmpDir/smarty/cache";
            $smarty->config_dir     = "$smartyTmpDir/smarty/configs";

            // our functions
            $smarty->register_function('xref_report_link', array($this, "xref_report_link"));
            $smarty->register_function('xref_severity_str', array($this, "xref_severity_str"));
        }


        // default params
        $smarty->assign('config', self::getConfig());
        $smarty->assign('version', self::version());

        // template params
        foreach ($templateParams as $k => $v) {
            $smarty->assign($k, $v);
        }

        $result = $smarty->fetch($templateName);
        $smarty->template_objects = array(); // otherwise Smarty v3 leaks memory
        return $result;
    }

    /**
     * Modifies the error report, returned by lint engine
     * (see XRef_ILintEngine::getReport() and XRef_ILintEngine::collectReport())
     * and inserts "source_url" into errors description
     *
     * @param array $errors_report
     * @return void
     */
    public function addSourceCodeLinks($report, $revision = '', $root = '') {
        if ($revision) {
            // xref-ci report mode:
            // add links to external url (github) or to web address of current installation of xref,
            // whichever is configured
            //
            // url_template: 'https://github.com/<author>/<project>/blob/{%revision}/{%fileName}#L{%lineNumber}'
            $url_template = self::getConfigValue("project.source-url", "");
            if (!$url_template) {
                $script_url = self::getConfigValue("xref.script-url", "");
                if ($script_url) {
                    $url_template = $script_url . '/view-source.php?revision={%revision}&filename={%fileName}#{%lineNumber}';
                }
            }

            if ($url_template) {
                $search     = array('{%revision}', '{%fileName}', '{%lineNumber}');
                foreach ($report as $file_name => $errors_list) {
                    foreach ($errors_list as $e) {
                        if ($e->lineNumber) {
                            $replace = array($revision, $file_name, $e->lineNumber);
                            $e->sourceUrl = str_replace($search, $replace, $url_template);
                        }
                    }
                }
            }
        } else {
            // xref-doc report mode:
            // add links to locally generated report files
            foreach ($report as $file_name => $errors_list) {
                foreach ($errors_list as $e) {
                    if ($e->lineNumber) {
                        $params = array(
                            "reportId"  => "files",
                            "itemName"  => $file_name,
                            "root"      => $root,
                            "lineNumber"=> $e->lineNumber,
                        );
                        $e->sourceUrl = $this->xref_report_link($params);
                    }
                }
            }
        }
    }

    /**
     * Function is called from Smarty template, returns formatted URL. Usage example (smarty code):
     * <a href='{xref_report_link reportId="files" itemName=$filePos->fileName root=$root lineNumber=$filePos->lineNumber}'>...</a>
     *
     * @return string
     */
    public function xref_report_link($params, $smarty = null) {
        $itemName = isset($params['itemName']) ? $params['itemName'] : null;        // just to remove warning
        $lineNumber = isset($params['lineNumber']) ? $params['lineNumber'] : null;  // about optional params
        return $this->getHtmlLinkFor( $params['reportId'], $itemName, $params['root'], $lineNumber );
    }

    public function xref_severity_str($params, $smarty = null) {
        $str = XRef::$severityNames[ $params['severity'] ];
        return ($params['html']) ? "<span class='$str'>$str</span>" : $str;
    }

    public function getNotificationEmail($report, $branch_name, $old_rev, $current_rev) {

        $this->addSourceCodeLinks($report, $current_rev);

        $reply_to       = XRef::getConfigValue('mail.reply-to');
        $from           = XRef::getConfigValue('mail.from');
        $mail_to        = XRef::getConfigValue('mail.to');
        $project_name   = XRef::getConfigValue('project.name', '');

        // this works for git, will it work for other scms?
        $old_rev_short      = (strlen($old_rev) > 7)    ? substr($old_rev, 0, 7)     : $old_rev;
        $current_rev_short  = (strlen($current_rev) > 7)? substr($current_rev, 0, 7) : $current_rev;

        // $commitInfo: array('an'=>'igariev', 'ae'=>'igariev@9e1ac877-.', ...)
        $commitInfo = $this->getSourceCodeManager()->getRevisionInfo($current_rev);

        $subject    = "XRef CI $project_name: $branch_name/$current_rev_short";
        $headers    =
            "MIME-Version: 1.0\n".
            "Content-type: text/html\n".
            "Reply-to: $reply_to\n".
            "From: $from\n";

        $body = $this->fillTemplate("ci-email.tmpl", array(
            'branchName'        => $branch_name,
            'oldRev'            => $old_rev,
            'oldRevShort'       => $old_rev_short,
            'currentRev'        => $current_rev,
            'currentRevShort'   => $current_rev_short,
            'fileErrors'        => $report,
            'commitInfo'        => $commitInfo,
        ));

        $recipients = array();
        foreach ($mail_to as $to) {
            $to = preg_replace('#\{%(\w+)\}#e', '$commitInfo["$1"]', $to);
            $recipients[] = $to;
        }

        return array($recipients, $subject, $body, $headers);
    }

    public static function isPublic($attributes) {
        // either explicit public, or no visibility modifier at all (compat mode with php 4)
        return
            ($attributes & self::MASK_PUBLIC) != 0
            || ($attributes & self::MASK_PRIVATE) == 0 && ($attributes & self::MASK_PROTECTED) == 0;

    }

    public static function isPrivate($attributes) {
        return ($attributes & self::MASK_PRIVATE);
    }
    public static function isProtected($attributes) {
        return ($attributes & self::MASK_PROTECTED);
    }
    public static function isStatic($attributes) {
        return ($attributes & self::MASK_STATIC);
    }


}

// vim: tabstop=4 expandtab
