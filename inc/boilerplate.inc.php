<?php

/*

Boilerplate

Shell:
- switches to an alternate screen buffer
- passes all input directly to PHP (doesn't wait for Enter key)
- turns off local printing of input, and hides the cursor
These are reverted when the script exits (hopefully also on crash - if all else fails, type 'reset' and Enter if the screen seems to be back in the shell)

PHP:
Defaults are assumed and/or set:
- error reporting & logging is verbose
- timezone UTC+1
- Ctrl-C is caught and exits cleanly
- window/pane resize resets $width & $height variables, and runs display_all(), if that exists

Constants:
- LOGDIR, target path for debug.log. Same as calling script, or if it's been turned into a phar file, guessed from env defaults (somewhere under ~user/)

Public variables:
- sets $width, $height of the window/pane in characters

Set $blocking (before including this file) & $usleep as necessary
- blocking (bool true):         whether the main loop should wait for input
- usleep (microseconds 20_000): how long the main loop sleeps in case of no input (that is, if you use the default main loop code..)

These two are used by get_input() and can be adjusted. cursor keys and some others are in there already
- allowed_chars (array) [byte=>#, ..], allowed bytes that it will try to turn into allowed keys
- allowed_keys (array) [bytes=>key code, ..], the key code it returns

Public functions:
- get_input()                   returns the (name of the) key that was pressed, which can be empty string for blocking=false, and 'nop' for unrecognized input
- prompt($label)                requests some input from the user and returns it to the script (blocks until enter is pressed)
- debug($whatever)              will write to a logfile
- debugs(array [$key=>$value])  sends input to a socket. Can be viewed by calling "your_script --debug-listen" in a different window/pane.

*/


// make errors Noisy. User can re-set as wanted, but the code in *this* file has to work or yell.
error_reporting(-1);
ini_set('log_errors', '1');

// boilerplate can be included but some of these just have to be global.
global $argv, $blocking, $usleep, $key_map;

// Logging path: script location, or generic location somewhere in user home.
$_backtrace = debug_backtrace();
$_script_path = $_backtrace[0]['file'];

$_logdir = dirname($_script_path);
$_script_file = basename($_script_path);
if (Phar::running()) $_logdir = getenv('XDG_CACHE_HOME') ?: ($_SERVER['HOME'] . "/.cache/$_script_file");
define('LOGDIR', $_logdir);
if (! is_dir(LOGDIR)) mkdir(LOGDIR, 0750, true);
ini_set('error_log', LOGDIR.'/debug.log');

date_default_timezone_set('Europe/Amsterdam');

$width  = (int) `tput cols`;
$height = (int) `tput lines`;

// set these before including boilerplate to override them.
$blocking ??= true;   // enable to not wait for input, and then also add a usleep($usleep) somewhere
$usleep   ??= 20_000; // like at end of while-loop: if (empty($blocking)) usleep($usleep);

pcntl_async_signals(true); // modern version of declare(ticks=1). php can still be stuck in a syscall
                           //   like fgetc() if blocking is true
shell_exec('stty -icanon -echo'); // input won't wait for enter key, and won't be printed
echo "\e[?25l";   // don't show cursor
echo "\e[?1049h"; // alt/new screen buffer

// disabling blocking enables 'esc' as key but requires a usleep($usleep) to prevent CPU hammering
stream_set_blocking(STDIN, $blocking);

register_shutdown_function('_shut_down');
// the below catches sigint (Ctr-C) before it kills the PHP process, and tells PHP itself to exit.
// now PHP has time to do all its internal cleanup, including calling the registered shutdown function.
pcntl_signal(SIGINT, function () {
    exit;
});

// triggers (well, maybe) on window dimensions change
pcntl_signal(SIGWINCH, function () {
    global $width, $height;
    $width  = (int) `tput cols`;
    $height = (int) `tput lines`;
    if (function_exists('display_all')) display_all();
});

$key_map = [
    'up'       => "\e[A",
    'down'     => "\e[B",
    'right'    => "\e[C",
    'left'     => "\e[D",
    'pageup'   => "\e[5~",
    'pagedown' => "\e[6~",
    'enter'    => "\x0A",
    // 'enter'    => "\x0D",
    'space'    =>  " ",
    // tab
];

// if the parent script is called with --debug-listen, start this debug listener and nothing else.
// not very useful on its own, run it in a separate window/pane. Exit with Esc or q.
// it'll be expecting JSON strings of string-key arrays and try to display them in a comprensible manner.
// at some point.
// later.
// #todo
if (in_array('--debug-listen', $argv)) {
    stream_set_blocking(STDIN, false);
    _debug_listen();
    exit;
}
$_debug = in_array('--debug', $argv);

// == FUNCTIONS ==========================

function get_key(string $name='', $bytes='') {
    global $key_map;
    static $allowed_bytes = [];
    static $allowed_keys = [];
    // one exception, this should not arrive in $allowed_keys
    static $esc = false;
    static $esc_name = '';
    if (strlen($name) > 0) {
        if ($bytes === "\e" or $name === "\e") {
            $esc = true;
            $esc_name = $name;
            if ($name === "\e") trigger_error("name should be descriptive, like 'esc' or 'escape'", E_USER_NOTICE);
            return;
        }
        if (! strlen($bytes)) {
            if (strlen($name) === 1) {
                $bytes = $name;
            }elseif (isset($key_map[$name])) {
                $bytes = $key_map[$name];
            }else{
                trigger_error("unknown key name '$name'", E_USER_WARNING);
                return false;
            }
        }
        $allowed_keys[$bytes] = $name;
        foreach(str_split($bytes) as $char) {
            $allowed_bytes[$char] = $char;
        }
        return;
    }
    $input = $key = '';
    while (($byte = fgetc(STDIN)) !== false) {
        if (isset($allowed_bytes[$byte])) $input .= $byte;
        if (isset($allowed_keys[$input])) {
            $key = $allowed_keys[$input];
            break;
        }
        if (strlen($input) > 10) {
            $key = 'nop'; // unrecognized sequence, return nop..
            $meta = stream_get_meta_data(STDIN);
            stream_set_blocking(STDIN, false);
            while (fgetc(STDIN) !== false) {} // ..and clear buffer
            stream_set_blocking(STDIN, $meta['blocked']);
            break;
        }
    }
    if ($esc and $input === "\e") $key = $esc_name;
    return $key;
}

// prompt() accepts some typed input and returns it
function prompt($prompt='Value: ') {
    _shell(true);
    echo $prompt;
    $handle = fopen("php://stdin","r");
    $output = fgets($handle);
    _shell(false);
    return trim ($output);
}

// debug() dumps input to a file, timestamped & stringified
function debug(...$strs) {
    foreach ($strs as $str) {
        $ts = '['.date("Y-m-d H:i:s").'] ';
        if (! is_string($str)) $str = var_export($str, 1);
        $str = str_replace("\e", '\e', $str);
        if (strtolower(substr($str, 0, 5)) === 'error' or strtolower(substr($str, 0, 2)) === 'e:') $str = "\e[31m$str\e[0m";
        if (strtolower(substr($str, 0, 7)) === 'warning') $str = "\e[38;5;208m$str\e[0m";
        file_put_contents(LOGDIR.'/debug.log', $ts.$str."\n", FILE_APPEND);
    }
}

/*
debugs() expects a flat array where keys are period-delimited strings like "car.dimensions.width", and scalar values.
Calling your script in another window/pane with --debug-listen will display the incoming values in a navigable tree-like structure.
... Eventually.
*/
function debugs(array $key_values) {
    global $_debug;
    if (! $_debug) return; // debugging disabled, nothing to do
    static $socket = false;
    if (! $socket) {
        $socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        echo "\nCreated socket!\n";
        sleep(1);
        
        if (! $socket) return; // uhhhhhh sockets not supported??
    }
    $data = json_encode($key_values);
    if (@socket_sendto($socket, $data, strlen($data), 0, LOGDIR . '/debug.sock') === false) {
        return false;
    }
    return true;
}


// == 'PRIVATE' FUNCTIONS ================

// this runs when you call your script with --debug-listen, showing the output of debugs($data)
function _debug_listen() {
    global $argv;
    $script = basename($argv[0]);
    $socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
    $socket_file = LOGDIR . '/debug.sock';
    @unlink($socket_file);
    socket_bind($socket, $socket_file);
    socket_set_nonblock($socket);
    while (1) {
        $data = null;
        // get data
        $result = socket_recv($socket, $data, 4096, 0);
        $key = get_key();
        // debugging the debugger
        echo "\e[2J\e[H";
        echo "Time: ".date('H:i:s')."\n";
        echo "Socket Bytes: ". (int) $result. "\n";
        echo "Key: $key\n";
        echo "Data: ".var_export($data, 1)."\n";
        // handle data
        if (! is_null($data)) {
            $values = json_decode($data, 1);
            
        }
        _debug_listen_display();
        
        // sleep(1);
        usleep(100_000);
    }
}

function _debug_listen_display() {
    
}

// _shell(bool $toggle) switch, used in prompt()
function _shell($toggle) {
    if ($toggle) {
        shell_exec('stty icanon echo');   // wait for enter, print input
        echo "\e[?25h";                   // show cursor
    }else{
        echo "\e[?25l";                   // hide cursor
        shell_exec('stty -icanon -echo'); // pass all input to PHP immediately, don't print it
    }
}

// used as register_shutdown_function
function _shut_down($msg='') {
    // undo the lot above
    shell_exec('stty sane'); // act normal (I know, I know..)
    echo "\e[?25h";          // show cursor
    echo "\e[?1049l";        // switch to original screen buffer
    if (strlen($msg)) echo "$msg\n";
    exit;
}
