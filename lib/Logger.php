<?php
/**
 * Undocumented
 */
class Logger extends AbstractController
{
/**
 * Logger class is implemented a more sophisticated and usable error handling.
 *
 * Normally all error messages are sent through the APP class by using one of two
 * ways:
 *
 * 1. throwing exception, which is caught by App class (unless you catch it yourself)p
 * 2. calling $this->fatal() which would throw exception for you (useful when calling
 *  from PHP4 compatible components)
 *
 * additionally there are way to pass info and warnings by calling:
 *
 * $this->warning();
 * $this->info();
 * $this->debug();
 *
 * Debug information will reach logs only if debug mode is set.
 *
 * ==[ Controling debug info ]===================================================
 *
 * AModules3 support two ways to set debug mode. Global and Local.
 *
 * Global mode is set by setting $app->debug to true. Local mode is set by setting
 * particular object's $this->debug to true.
 *
 * In local mode only debug info generated by particular object will be sent
 * through
 *
 * This class does not manage debug modes, which is a task for App and all other
 * objects.
 *
 * $config['debug']=true; // turns on global debug mode.
 *
 *
 * ==[ Severity ]================================================================
 *
 * AModules3 have 4 severity types: debug, info, warning and fatal.
 *
 * Expected exceptions might be reported as warnings and uncaught exceptions
 * are fatal. Forms have their own validation and errors on the form is
 * completely separate system.
 *
 * Sample fatal errors:
 *  - unable to execute query
 *  - method not found
 *  - mandatory data is not specified
 * fatal errors will automatically terminate application execution.
 *
 * Warnings messages:
 *  - duplicate child added to the object with same name
 *  - method is called without specifying important argument
 *
 * Sample info messages:
 *  - user tried to access restricted page
 *  - monthly log rotation routine finished successfuly
 *  - user information is automatically converted (from format used in previous version)
 *  Info messages are passed as strings without extended information.
 *
 * Debug messages
 *  - called some function which might contain errors or being debuged
 *  - user logged in or logged out
 *  - when debuging, even more messages might be sent as debug()
 *  WARNING: please keep debug information to the minimum. Debug information is
 *  accompanied with extended information. Debug information is always saved into
 *  logs or is available on the screen.
 *
 * AModules3 core tries not to produce any debug or info messages to keep files
 * clean. Even if it does, those calls might eventually be cleaned out.
 *
 *
 * ==[ Configuring logger ]======================================================
 * Logger uses 2 output destinations, and 3 ways to restrict output information.
 *
 * Output destination: stdout (webpage), logs
 * Restrict options: 'full', 'light', null
 *
 * stdout is different for App_CLI and App_Web classes. Web output might contact
 * tags or even some AJAX elements. Logs and App_CLI uses the same output
 * format.
 *
 * Web output for info, warning and debug messages relies on templates
 * but fatal messages are template independent.
 *
 * ==[ Output restriction ]======================================================
 * null: this option will surpress all output. When used with logs, you won't
 * even need an empty directory. Web output will be clean of any messages.
 *
 * If fatal error occurs, if 'null' is used with web output, you will see
 * message (public_error_message), instead of the actual error.
 *
 *
 * light: only message is outputed. Even if debug mode is on, backtraces and
 * additional information is stripped off. This method is best if you are using
 * application for intranet and know uses or if you are doing beta testing.
 * This output won't contain any sensitive information such as table names, field
 * names, actual data)
 *
 * when used with logs, each message takes one line.
 *
 * full: this will output all the information available including:
 *  error message
 *  line/file/function where error message occured (guessed)
 *  additional information (such as last_query and error_message from DBlite)
 *  complete backtrace.
 *
 * when used with logs, each message takes several lines.
 *
 * ==[ Activation ]==================================================================
 * To start using this class in your applicaion you should:
 *
 *  $app->add('Logger');
 *
 * If you do not activate Logger, output will be similar to:
 * web_output='full';
 * log_output=null;
 *
 * ==[ Extending ]====================================================================
 *
 * You can extend this class to add additional features. Please notify me if you think
 * something essential is missing out
 *
 * romans@adevel.com
 *
 * Debug functions were contributed my mvs@adevel.com
 */

    // AModules3 compatibility
    public $owner;

    // Configuration;
    public $web_output = 'full';      // $config['logger']['web_output']
    public $log_output = null;        // $config['logger']['log_output']

    public $public_error_message = null;
    // This message will be outputed to user in case of
    // fatal error. When running in production mode, you
    // shouldn't show any debug info to user, but log them
    // instead

    public $log_dir;                // Directory where logs are created. It should be
    // used solely by AModules3. If not set, then
    // /var/log/atk4/<realm> will be used.
    //
    // You can change in $config['logger']['log_dir']

    protected $log_error_file;        // File we are currently logging errors to
    protected $log_debug_file;        // File we are currently logging errors to
    protected $log_info_file;         // File we are currently logging errors to
    public $details = array();

    private $html_stdout = false;

    private $header_sent = 0;

    public $debug_log = '';           // Will be outputed at the end of the page
    private $debug_added = false;     // no debug messages added yet

    public $filename;
    public $err_message;
    public $_current_ip;
    public $_prev_exec_time;

    public function init()
    {
        parent::init();
        $this->debug_log = session_id() ? $this->recall('debug_log', '') : '';
        if (session_id()) {
            $this->forget('debug_log');
        }
        $this->debug_log .= '[<font color=red>Debug log from '.date('d.m.Y H:m:s').' to '.
            $_SERVER['QUERY_STRING']."</font>] - debug started<br>\n";
        $this->debug_added = false;

        register_shutdown_function(array($this, 'showDebugInfo'));

        $this->log_output = $this->app->getConfig('logger/log_output', null);
        $this->web_output = $this->app->getConfig('logger/web_output', 'full');

        if (!$this->web_output) {
            $this->public_error_message = $this->app
                ->getConfig(
                    'debug_public_error_message',
                    'We were unable to deal with your request. Please retry later.'
                );
        }

        $this->log_dir = $this->app->getConfig(
            'logger/log_dir',
            '/var/log/atk4/'.$this->app->name
        );

        if ($this->log_output) {
            $this->openLogFile('error');
            $this->openLogFile('debug');
            $this->openLogFile('info');
            if (rand(1, 50) == 1) {
                $this->cleanupLogDirectory();
            }
        }

        if ($this->log_output == 'full' || $this->web_output == 'full') {
            // Full logging will require some preparations
            $this->gatherDetails();
        }

        if ($this->app instanceof App_Web) {
            $this->html_stdout = true;
        }

        $this->app->addHook('caught-exception', array($this, 'caughtException'));
        $this->app->addHook('output-fatal', array($this, 'outputFatal'));
        $this->app->addHook('output-warning', array($this, 'outputWarning'));
        $this->app->addHook('output-info', array($this, 'outputInfo'));
        $this->app->addHook('output-debug', array($this, 'outputDebug'));

        $this->app->debug('Logger is initialized');
    }
    public function showDebugInfo()
    {
        if (!$this->debug_added) {
            return;
        }
        if (@$this->app->not_html) {
            // We may not output anything, because this will screw up. Save debug output to session
            if (session_id()) {
                $this->memorize('debug_log', $this->debug_log);
            }
        } else {
            echo $this->debug_log;
        }
    }
    public function gatherDetails()
    {
        // Get IP address
        if (isset($_SERVER['REMOTE_ADDR'])) {
            //FIXME: generates warning - array_shift wants variable
            //$this->details['IP Address']=(isset($_SERVER["HTTP_X_FORWARDED_FOR"])
            //    ? array_shift(explode(',', $_SERVER["HTTP_X_FORWARDED_FOR"]))
            //    : $_SERVER["REMOTE_ADDR"]);
            null;
        }

        if (isset($_SERVER['QUERY_STRING'])) {
            $this->details['Query String'] = $_SERVER['QUERY_STRING'];
        }
        if (isset($_SERVER['REDIRECT_SCRIPT_URI'])) {
            $this->details['Original Query'] = $_SERVER['REDIRECT_SCRIPT_URI'];
        }
        if (isset($_SERVER['HTTP_REFERER'])) {
            $this->details['Referer'] = $_SERVER['HTTP_REFERER'];
        }
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $this->details['Version'] = $_SERVER['HTTP_USER_AGENT'];
        }
        if (isset($_SERVER['SERVER_PORT'])) {
            $this->details['Port'] = $_SERVER['SERVER_PORT'];
        }
    }

    public function findFrame($function_name, $shift = 0)
    {
        $backtrace = debug_backtrace();
        while ($bt = array_shift($backtrace)) {
            if ($bt['function'] == $function_name) {
                while ($shift--) {
                    $bt = array_shift($backtrace);
                }

                return $bt;
            }
        }

        return array();
    }
    public $recskip = array();
    public function showRenderTree($e, $obj)
    {
        if (in_array($obj->name, $this->recskip)) {
            echo '..recursion('.$obj.')';

            return;
        };
        $this->recskip[] = $obj;
        if ($e->owner == $obj || $e->owner->owner == $obj || $e->owner->owner->owner == $obj) {
            echo '<font color="red">'.$obj->__toString().'</font>';
        } else {
            echo $obj->__toString();
        }
        if ($obj->elements) {
            echo '<ul>';
            foreach ($obj->elements as $name => $object) {
                echo '<li>'.$name.': ';
                $this->showRenderTree($e, $object);
                echo '</li>';
            }
            echo '</ul>';
        }
    }
    public function logCaughtException($e)
    {
        if (!$this->log_output) {
            return;
        }
        if (method_exists($e, 'getMyTrace')) {
            $trace = $e->getMyTrace();
        } else {
            $trace = $e->getTrace();
        }

        if (isset($e->shift)) {
            $frame = $e->my_backtrace[$e->shift];
        } else {
            $frame = array('file' => 'unknown', 'line' => 'unknown');
        }

        $this->logLine($this->txtLine(get_class($e).': '.$e->getMessage(), $frame), 2, 'error', $trace);
        if (method_exists($e, 'getAdditionalMessage')) {
            $this->logLine($e->getAdditionalMessage(), 2, 'error');
        }

        if (isset($e->more_info)) {
            $this->logLine("Additional information:\n".
            $this->print_r($e->more_info, '', '', '* ', "\n", ' '), 2, 'error');
        }
    }
    public function caughtException($caller, $e)
    {
        // compatibility with \atk4\core\Exception
        if ($e instanceof \atk4\core\Exception) {
            $e->more_info = $e->getParams();
        }

        $this->logCaughtException($e);
        if (!$this->web_output) {
            echo $this->public_error_message;
            exit;
        }

        if (PHP_SAPI === 'cli') {
            if (posix_isatty(STDOUT)) {
                $red = "\033[0;31m";
                $yellow = "\033[1;33m";
                $end = "\033[0m";
            } else {
                $red = $end = $yellow = '';
            }

            if (isset($e->more_info)) {
                echo '==[ ';
                echo get_class($e).': '.$red.$e->getMessage().$end." ]===========\n\n";
                echo "Additional information:\n".
                    $this->print_r($e->more_info, '', '', $yellow.'* '.$end, "\n", ' ');
            } else {
                echo $red.$e->getMessage().$end."\n";
            }

            exit($e->getCode() ?: 255);
        }

        if (method_exists($e, 'getHTML')) {
            ?><!DOCTYPE html>
<html lang="en"><head>
    <title>Exception: <?php echo htmlspecialchars($e->getMessage())?></title>
<head><body>
<?php  echo $e->getHTML();
            ?>
</body></head>
<?php
            exit;
        }

        if ($_GET[$this->name.'_debug'] == 'rendertree') {
            echo '<h2>Object Tree</h2>';
            try {
                $this->showRenderTree($e, $this->app);
            } catch (Exception $e) {
                echo '<h1>Exception while trying to render tree:</h1>';
                //unset($_GET[$htis->name.'_debug']);
                //$this->app->caughtException($e);
            }
        }

        $o = '';
        $o .= '<h2>Application Error: '.htmlspecialchars($e->getMessage())."</h2>\n";
        $o .= '<p><font color=red>'.get_class($e).', code: '.$e->getCode().'</font></p>';
        if (isset($e->more_info)) {
            $o .= '<p>Additional information:';
            $o .= $this->print_r($e->more_info, '<ul>', '</ul>', '<li>', '</li>', ' ');
            $o .= '</p>';
        }
        if (method_exists($e, 'getMyFile')) {
            $o .= '<p><font color=blue>'.$e->getMyFile().':'.$e->getMyLine().'</font></p>';
        }

        if (method_exists($e, 'getMyTrace')) {
            $o .= $this->backtrace(3, $e->getMyTrace());
        } else {
            $o .= $this->backtrace(@$e->shift, $e->getTrace());
        }

        if ((isset($_POST['ajax_submit'])
            || $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest')
            && !$_GET['cut_page']
            && !$_GET['cut_object']
            && !$_GET['cut_region']
        ) {
            $this->displayError($o);
        } else {
            echo $o;
        }

        if (@$e->by_exception) {
            echo '<h3>This error was triggered by the following error:</h3>';
            $this->caughtException($caller, $e->by_exception);
        }

        echo "<p>Note: To hide this information from your users, add \$config['logger']['web_output']=false to your ".
            "config.php file. Refer to documentation on 'Logger' for alternative logging options</p>";

        exit;
    }
    public function displayError($o)
    {
        $this->app->js()->univ()->dialogError($o, array('width' => 900, 'height' => 500))->execute();
    }
    /**
     * Returns HTML formatted $key array.
     *
     * @param mixed $key
     * @param string $gs List start tag
     * @param string $ge List end tag
     * @param string $ls Item start tag
     * @param string $le Item end tag
     * @param string $ind Identation
     *
     * @return string
     */
    public function print_r($key, $gs, $ge, $ls, $le, $ind = ' ', $depth = 4)
    {
        $o = '';
        if (strlen($ind) > $depth) {
            return;
        }
        if (is_array($key)) {
            $o = $gs;
            foreach ($key as $a => $b) {
                $o .= $ind.$ls.$a.': '.$this->print_r($b, $gs, $ge, $ls, $le, $ind.' ').$le;
            }
            $o .= $ge;
        } elseif ($key instanceof Closure) {
            $o .= '[closure]';
        } elseif (is_object($key)) {
            $o .= 'object('.get_class($key).'): ';
            if (method_exists($key, '__debugInfo')) {
                $o .= $this->print_r($key->__debugInfo(), $gs, $ge, $ls, $le, $ind.' ').$le;
            }
        } else {
            $o .= $gs ? htmlspecialchars($key) : $key;
        }

        return $o;
    }
    public function outputWarning($caller, $msg, $shift = 0)
    {
        // first, let's see if we should log this
        $frame = $this->findFrame('warning', $shift);
        if ($this->log_output) {
            $this->logLine($this->txtLine("warning: $msg", $frame), 'warning', 'error');
        }
        if (!$this->web_output) {
            return true;
        } else {
            echo $this->html_stdout ?
                $this->htmlLine("$msg", $frame, 'warning') :
                $this->txtLine("$msg", $frame, 'warning');

            return true;
        }
    }
    public function outputDebug($front, $caller, $msg, $shift = 0)
    {
        // first, let's see if we should log this
        $frame = $this->findFrame('debug');
        if ($this->log_output) {
            $this->logLine($this->txtLine("info: $msg", $frame), 'fatal', 'debug');
        }
        if (!$this->web_output) {
            return true;
        } else {
            $this->debug_added = true;
            $this->debug_log .= $this->html_stdout ?
                $this->htmlLine("$msg", $frame, 'debug') :
                $this->txtLine("$msg", $frame, 'debug');

            return true;
        }
    }
    public function outputInfo($caller, $msg, $shift = 0, $nohtml = false)
    {
        if ($this->log_output) {
            $this->logLine($this->txtLine("info: $msg"), null, 'info');
        }
        if ($this->web_output && !$nohtml) {
            echo $this->html_stdout ?
                $this->htmlLine("$msg", null, 'info') :
                $this->txtLine("$msg", null, 'info');
        }

        return true;
    }
    public function outputFatal($caller, $msg, $shift = 0)
    {
        // first, let's see if we should log this
        $frame = $this->findFrame('fatal');
        if ($this->log_output) {
            $this->logLine($this->txtLine("fatal: $msg", $frame), 'fatal', 'error');
        }
        if (!$this->web_output) {
            echo $this->public_error_message;
        } else {
            if ($this->html_stdout) {
                echo "<h2>Fatal error</h2>\n";
            }
            echo $this->html_stdout ?
                $this->htmlLine("$msg", $frame, 'fatal') :
                $this->txtLine("$msg", $frame, 'fatal');

            if ($this->html_stdout) {
                echo $this->backtrace('fatal');
            } else {
                echo "Stack trace:\n";
                echo $this->txtBacktrace('fatal');
            }
        }
        exit;
    }
    public function htmlLine($msg, $frame = null, $prefix = null)
    {
        if (!$frame) {
            return "<font style='font-family: verdana;  font-size:10px'><font color=blue>warning: </font> ".
                "<font color=red><b>$msg</b></font></font><br>";
        } else {
            $errfile = dirname($frame['file']).'/<b>'.basename($frame['file']).'</b>';

            return "<font style='font-family: verdana;  font-size:10px'><font color=blue>$errfile:".$frame['line'].
                '</font> <font color=red>'.($prefix ? "$prefix: " : '')."<b>$msg</b></font></font><br>";
        }
    }
    public function txtLine($msg, $frame = null, $prefix = null)
    {
        if (!$frame) {
            return "$prefix: $msg\n";
        } else {
            return basename($frame['file']).' on line '.$frame['line'].', path: '.dirname($frame['file'])."\n\n".
                ($prefix ? "$prefix: " : '')."$msg\n\n";
        }
    }
    public function logLine($msg, $shiftfunc = null, $severity = 'info', $trace = null)
    {
        $log_file = 'log_'.$severity.'_file';
        if (!isset($this->$log_file)) {
            $this->openLogFile($severity);
        }
        if ($this->log_output === 'full' && $severity == 'error') {
            if (!$this->header_sent++) {
                fputs($this->$log_file, "\n\n".
                        "============================================================\n".
                        "$msg".
                        "------------------------------------------------------------\n".
                        'Date: '.date('d-M-Y H:i:s')."\n");
                foreach ($this->details as $key => $val) {
                    fputs($this->$log_file, "$key: $val\n");
                }
                fputs(
                    $this->$log_file,
                    "------------------------------------------------------------\n".
                    " Stack trace\n".
                    $this->txtBacktrace($shiftfunc, $trace).
                    "\n"
                );
            } else {
                fputs($this->$log_file, $msg);
            }
        } elseif ($this->log_output) {
            fputs($this->$log_file, '['.date('d-M-Y H:i:s')."] $msg");
        } else {
            return;
        }
        fflush($this->$log_file);
    }
    public function logVar($var, $msg = '', $shiftfunc = null, $severity = 'debug')
    {
        //creating an $msg from variable
        $msg .= '('.gettype($var).'):';
        if (is_array($var) || is_object($var)) {
            $msg .= print_r($var, true);
        } else {
            $msg .= $var;
        }
        $this->logLine($msg."\n", $shiftfunc, $severity);
    }
    public function logException($e)
    {
        // logs exception from the catch statement
        // contains code from Logger::caughtException(), as this code won't launch
        // if exception is caught
        $frame = $e->my_backtrace[$e->shift - 1];
        $this->logLine($this->txtLine(get_class($e).': ('.$e->getCode().') '.$e->getMessage(), $frame), 2, 'error');

        return $this;
    }
    public function openLogFile($severity = 'error')
    {
        if (!is_dir($this->log_dir)) {
            // Directory is not writable, let's first try to create it
            if (!mkdir($this->log_dir, 0750)) {
                throw new BaseException("Unable to create $this->log_dir for log output");
            }
        }

        $filename = 'am3_'.$severity.'_log';
        $full_filename = $this->log_dir.DIRECTORY_SEPARATOR.$filename;
        if (!is_writable($full_filename) && !is_writable($this->log_dir)) {
            throw new BaseException("Log file is not writable and seems I won't be able to create it: $full_filename");
        }
        if (is_link($full_filename)) {
            throw new BaseException('Log file is a symlink. Are you trying to make me overwrite somethingn?');
        }

        ini_set($severity.'_log', $full_filename);

        //$full_filename=tempnam($this->log_dir,$filename);
        $new_file = (file_exists($full_filename)) ? false : true;
        $log_file = "log_$severity".'_file';
        $this->$log_file = fopen($full_filename, 'a');
        if (!$this->$log_file) {
            throw new BaseException("Cannot open $severity log file");
        }
        if ($new_file) {
            chmod($full_filename, 0777);
        } //
    }
    public function writeLogMessage()
    {
    }
    public function backtrace($sh = null, $backtrace = null)
    {
        $output = "<div >\n";
        // TODO: allow extending backtrace option, so that
        $output .= "<b>Stack trace:</b><br />".
            "<table style='border: 1px solid black; padding: 3px; text-align: left; font-family: verdana; ".
            "font-size: 10px' width=100% cellspacing=0 cellpadding=0 border=0>\n";
        $output .= "<tr><th align='right'>File</th><th>&nbsp;</th><th>Object Name</th><th>Stack Trace</th></tr>";
        if (!isset($backtrace)) {
            $backtrace = debug_backtrace();
        }
        $sh -= 2;

        $n = 0;
        foreach ($backtrace as $bt) {
            ++$n;
            $args = '';
            if (!isset($bt['args'])) {
                continue;
            }
            foreach ($bt['args'] as $a) {
                if (!empty($args)) {
                    $args .= ', ';
                }
                switch (gettype($a)) {
                    case 'integer':
                    case 'double':
                        $args .= $a;
                        break;
                    case 'string':
                        $a = htmlspecialchars(substr($a, 0, 128)).((strlen($a) > 128) ? '...' : '');
                        $args .= "\"$a\"";
                        break;
                    case 'array':
                        $args .= 'Array('.count($a).')';
                        break;
                    case 'object':
                        $args .= 'Object('.get_class($a).')';
                        break;
                    case 'resource':
                        $args .= 'Resource('.strstr((string) $a, '#').')';
                        break;
                    case 'boolean':
                        $args .= $a ? 'True' : 'False';
                        break;
                    case 'NULL':
                        $args .= 'Null';
                        break;
                    default:
                        $args .= 'Unknown';
                }
            }

            if (($sh == null && strpos($bt['file'], '/atk4/lib/') === false)
                || (!is_int($sh) && $bt['function'] == $sh)
            ) {
                $sh = $n;
            }

            $output .= '<tr><td valign=top align=right><font color='.($sh == $n ? 'red' : 'blue').'>'.
                htmlspecialchars(dirname($bt['file'])).'/'.
                '<b>'.htmlspecialchars(basename($bt['file'])).'</b></font></td>';
            $output .= '<td valign=top nowrap><font color='.
                ($sh == $n ? 'red' : 'blue').">:{$bt['line']}</font>&nbsp;</td>";
            $name = (!isset($bt['object']->name)) ? get_class($bt['object']) : $bt['object']->name;
            $output .= '<td>'.($bt['object'] ? $name : '').'</td>';
            $output .= '<td valign=top><font color='.($sh == $n ? 'red' : 'green').'>'.get_class($bt['object']).
                "{$bt['type']}<b>{$bt['function']}</b>($args)</font></td></tr>\n";
        }
        $output .= "</table></div>\n";

        return $output;
    }
    public function cleanupLogDirectory()
    {
        // we should try to take care of our own log file cleanup
    }
    public function txtBacktrace($sh = null, $backtrace = null)
    {
        if (!isset($backtrace)) {
            $backtrace = debug_backtrace();
        }
        $output = '';
        $n = 0;
        foreach ($backtrace as $bt) {
            ++$n;
            $args = '';
            if (!isset($bt['args'])) {
                $bt['args'] = array();
            }
            foreach ($bt['args'] as $a) {
                if (!empty($args)) {
                    $args .= ', ';
                }
                switch (gettype($a)) {
                    case 'integer':
                    case 'double':
                        $args .= $a;
                        break;
                    case 'string':
                        $a = (substr($a, 0, 128)).((strlen($a) > 128) ? '...' : '');
                        $args .= "\"$a\"";
                        break;
                    case 'array':
                        $args .= 'Array('.count($a).')';
                        break;
                    case 'object':
                        $args .= 'Object('.get_class($a).')';
                        break;
                    case 'resource':
                        $args .= 'Resource('.strstr($a, '#').')';
                        break;
                    case 'boolean':
                        $args .= $a ? 'True' : 'False';
                        break;
                    case 'NULL':
                        $args .= 'Null';
                        break;
                    default:
                        $args .= 'Unknown';
                }
            }

            if ($sh) {
                if (is_int($sh)) {
                    if ($sh > 0) {
                        --$sh;
                        continue;
                    }
                } elseif ($bt['function'] != $sh) {
                    $sh = null;
                    continue;
                }
            }

            $output .= $bt['file'].':'.$bt['line'].' ';
            $output .= "{$bt['class']}{$bt['type']}{$bt['function']}($args)\n";
        }

        return $output;
    }

    /**
     * Debug functions
     */
    public function Debug($filename)
    {
        if (is_null($filename)) {
            $filename = dirname(__FILE__).DIRECTORY_SEPARATOR.'debug.log';
        }

        $this->filename = $filename;

        if (isset($_SERVER['REMOTE_ADDR'])) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $a = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $this->_current_ip = array_shift($a);
            } else {
                $this->_current_ip = $_SERVER['REMOTE_ADDR'];
            }
        }
    }

    public function _sec2time($sec)
    {
        $res = '';
        if ($sec < 0) {
            $sec = -$sec;
            $res = '-'.$res;
        }

        if ($sec != floor($sec)) {
            $msec = round(($sec - floor($sec)) * 1000);

            $msec = '.'.str_pad($msec, 3, '0', STR_PAD_LEFT);
            $sec = floor($sec);
        } else {
            $msec = '';
        }

        $hours = floor($sec / 3600);
        $min = floor(($sec - $hours * 3600) / 60);
        $sec = $sec - $hours * 3600 - $min * 60;

        if ($hours > 0) {
            $res .= str_pad($hours, 2, '0', STR_PAD_LEFT).':';
        }

        if (($hours > 0) || ($min > 0)) {
            $res .= str_pad($min, 2, '0', STR_PAD_LEFT).':';
        }

        $res .= str_pad($sec, 2, '0', STR_PAD_LEFT).$msec;

        return $res;
    }

    public function _microtime_float()
    {
        list($usec, $sec) = explode(' ', microtime());

        return (float) $usec + (float) $sec;
    }

    // print
    public function p($message, $file = null, $line = null)
    {
        $res = true;

        $time_diff_str = '';
        if (!empty($this->_prev_exec_time)) {
            $time_diff = $this->_microtime_float() - $this->_prev_exec_time;
            if ($time_diff < 1) {
                $time_diff_str = $this->_sec2time($time_diff);
            }
        }

        $details = ((empty($this->_current_ip)) ? '' : $this->_current_ip.' - ').
            ((!empty($file)) ? basename($file).' (line '.$line.')' : '');

        if (!empty($details)) {
            $details = ' ***** '.$details.' *****';
        }

        $message = '['.date('d-M-Y H:i:s').'] '.$time_diff_str.$details.
            "\n\n".$message."\n\n";

        $new_file = (file_exists($this->filename)) ? false : true;
        $fh = @fopen($this->filename, 'a');

        if (($fh !== false) && (is_resource($fh))) {
            @flock($fh, LOCK_EX);

            if (!@fwrite($fh, $message)) {
                $this->err_message = "Cannot write to file ($this->filename)";
                error_log($this->err_message.' in '.__FILE__.' on line '.__LINE__, 0);
                $res = false;
            }
            @flock($fh, LOCK_UN);
            @fclose($fh);

            if ($new_file) {
                chmod($this->filename, 0777);
            }
        } else {
            $this->err_message = 'Cannot open file ('.$this->filename.')'.
                ' in '.__FILE__.' on line '.__LINE__.' for save message: '."\n".$message;
            error_log($this->err_message, 0);
            $res = false;
        }

        $this->_prev_exec_time = $this->_microtime_float();

        return $res;
    }
}
