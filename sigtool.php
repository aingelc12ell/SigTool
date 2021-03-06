<?php
/**
 * SigTool v0.2.0 (last modified: 2018.01.20).
 * Generates signatures for phpMussel using main.cvd and daily.cvd from ClamAV.
 *
 * Package location: GitHub <https://github.com/phpMussel/SigTool>.
 * Author: Caleb M (Maikuolan) <https://github.com/Maikuolan>.
 */

/**
 * SigTool class contains any relevant non-core methods (class functions)
 * required by SigTool, mostly (but not entirely) adapted from the closures
 * available in the ./vault/functions.php file of the phpMussel package, used
 * for handling YAML data, signature shorthand, etc.
 */
class SigTool
{
    /** Script version. */
    public $Ver = '0.2.0';

    /** Script user agent. */
    public $UA = 'SigTool v%s (https://github.com/phpMussel/SigTool)';

    /** SigTool YAML data post-processed data array. */
    public $Arr = [];

    /** SigTool YAML data pre-processed raw data. */
    public $Raw = '';

    /** Safe file chunk size for when reading files. */
    public $SafeReadSize = 131072;

    /** Fix variables. */
    public function __construct()
    {
        $this->UA = sprintf($this->UA, $this->Ver);
    }

    /**
     * Normalises values defined by the YAML closure.
     *
     * @param string|int|bool $Value The value to be normalised.
     * @param int $ValueLen The length of the value to be normalised.
     * @param string|int|bool $ValueLow The value to be normalised, lowercased.
     */
    private function normalise(&$Value, int $ValueLen, $ValueLow)
    {
        if (substr($Value, 0, 1) === '"' && substr($Value, $ValueLen - 1) === '"') {
            $Value = substr($Value, 1, $ValueLen - 2);
        } elseif (substr($Value, 0, 1) === '\'' && substr($Value, $ValueLen - 1) === '\'') {
            $Value = substr($Value, 1, $ValueLen - 2);
        } elseif ($ValueLow === 'true' || $ValueLow === 'y') {
            $Value = true;
        } elseif ($ValueLow === 'false' || $ValueLow === 'n') {
            $Value = false;
        } elseif (substr($Value, 0, 2) === '0x' && ($HexTest = substr($Value, 2)) && !preg_match('/[^a-f0-9]/i', $HexTest) && !($ValueLen % 2)) {
            $Value = hex2bin($HexTest);
        } else {
            $ValueInt = (int)$Value;
            if (strlen($ValueInt) === $ValueLen && $Value == $ValueInt && $ValueLen > 1) {
                $Value = $ValueInt;
            }
        }
        if (!$Value) {
            $Value = false;
        }
    }

    /**
     * A simplified YAML-like parser. Note: This is intended to adequately serve
     * the needs of this package in a way that should feel familiar to users of
     * YAML, but it isn't a true YAML implementation and it doesn't adhere to any
     * specifications, official or otherwise.
     *
     * @param string $In The data to parse.
     * @param array $Arr Where to save the results.
     * @param int $Depth Tab depth (inherited through recursion; ignore it).
     * @return bool Returns false if errors are encountered, and true otherwise.
     */
    public function read(string $In, &$Arr, int $Depth = 0)
    {
        if (!is_array($Arr)) {
            $Arr = [];
        }
        if (!substr_count($In, "\n")) {
            return false;
        }
        $In = str_replace("\r", '', $In);
        $Key = $Value = $SendTo = '';
        $TabLen = $SoL = 0;
        while ($SoL !== false) {
            if (($EoL = strpos($In, "\n", $SoL)) === false) {
                $ThisLine = substr($In, $SoL);
            } else {
                $ThisLine = substr($In, $SoL, $EoL - $SoL);
            }
            $SoL = ($EoL === false) ? false : $EoL + 1;
            $ThisLine = preg_replace(["/#.*$/", "/\x20+$/"], '', $ThisLine);
            if (empty($ThisLine) || $ThisLine === "\n") {
                continue;
            }
            $ThisTab = 0;
            while (($Chr = substr($ThisLine, $ThisTab, 1)) && ($Chr === ' ' || $Chr === "\t")) {
                $ThisTab++;
            }
            if ($ThisTab > $Depth) {
                if ($TabLen === 0) {
                    $TabLen = $ThisTab;
                }
                $SendTo .= $ThisLine . "\n";
                continue;
            } elseif ($ThisTab < $Depth) {
                return false;
            } elseif (!empty($SendTo)) {
                if (empty($Key)) {
                    return false;
                }
                if (!isset($Arr[$Key])) {
                    $Arr[$Key] = false;
                }
                if (!$this->read($SendTo, $Arr[$Key], $TabLen)) {
                    return false;
                }
                $SendTo = '';
            }
            if (substr($ThisLine, -1) === ':') {
                $Key = substr($ThisLine, $ThisTab, -1);
                $KeyLen = strlen($Key);
                $KeyLow = strtolower($Key);
                $this->normalise($Key, $KeyLen, $KeyLow);
                if (!isset($Arr[$Key])) {
                    $Arr[$Key] = false;
                }
            } elseif (substr($ThisLine, $ThisTab, 2) === '- ') {
                $Value = substr($ThisLine, $ThisTab + 2);
                $ValueLen = strlen($Value);
                $ValueLow = strtolower($Value);
                $this->normalise($Value, $ValueLen, $ValueLow);
                if ($ValueLen > 0) {
                    $Arr[] = $Value;
                }
            } elseif (($DelPos = strpos($ThisLine, ': ')) !== false) {
                $Key = substr($ThisLine, $ThisTab, $DelPos - $ThisTab);
                $KeyLen = strlen($Key);
                $KeyLow = strtolower($Key);
                $this->normalise($Key, $KeyLen, $KeyLow);
                if (!$Key) {
                    return false;
                }
                $Value = substr($ThisLine, $ThisTab + $KeyLen + 2);
                $ValueLen = strlen($Value);
                $ValueLow = strtolower($Value);
                $this->normalise($Value, $ValueLen, $ValueLow);
                if ($ValueLen > 0) {
                    $Arr[$Key] = $Value;
                }
            } elseif (strpos($ThisLine, ':') === false && strlen($ThisLine) > 1) {
                $Key = $ThisLine;
                $KeyLen = strlen($Key);
                $KeyLow = strtolower($Key);
                $this->normalise($Key, $KeyLen, $KeyLow);
                if (!isset($Arr[$Key])) {
                    $Arr[$Key] = false;
                }
            }
        }
        if (!empty($SendTo) && !empty($Key)) {
            if (!isset($Arr[$Key])) {
                $Arr[$Key] = [];
            }
            if (!$this->read($SendTo, $Arr[$Key], $TabLen)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Parse locally.
     */
    public function readIn()
    {
        $Arr = &$this->Arr;
        $Raw = $this->Raw;
        return $this->read($Raw, $Arr);
    }

    /**
     * Set raw data.
     */
    public function setRaw(string $Raw)
    {
        $this->Raw = $Raw;
    }

    /**
     * Reconstruct level.
     */
    private function inner(array $Arr, string &$Out, int $Depth = 0)
    {
        foreach ($Arr as $Key => $Value) {
            if ($Key === '---' && $Value === false) {
                $Out .= "---\n";
                continue;
            }
            if (!isset($List)) {
                $List = ($Key === 0);
            }
            $Out .= str_repeat(' ', $Depth) . (($List && is_int($Key)) ? '-' : $Key . ':');
            if (is_array($Value)) {
                $Depth++;
                $Out .= "\n";
                $this->inner($Value, $Out, $Depth);
                $Depth--;
                continue;
            }
            if ($Value === true) {
                $Out .= ' true';
            } elseif ($Value === false) {
                $Out .= ' false';
            } else {
                $Out .= ' ' . $Value;
            }
            $Out .= "\n";
        }
    }

    /**
     * Reconstruct new raw data from data array.
     */
    public function reconstruct()
    {
        $Arr = $this->Arr;
        $New = '';
        $this->inner($Arr, $New);
        return $New . "\n";
    }

    /** Use cURL to fetch files. */
    public function fetch($URI, $Timeout = 600) {

        /** Initialise the cURL session. */
        $Request = curl_init($URI);

        $LCURI = strtolower($URI);
        $SSL = (substr($LCURI, 0, 6) === 'https:');

        curl_setopt($Request, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($Request, CURLOPT_HEADER, false);
        curl_setopt($Request, CURLOPT_POST, false);
        if ($SSL) {
            curl_setopt($Request, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($Request, CURLOPT_SSL_VERIFYPEER, false);
        }
        curl_setopt($Request, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($Request, CURLOPT_MAXREDIRS, 1);
        curl_setopt($Request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($Request, CURLOPT_TIMEOUT, $Timeout);
        curl_setopt($Request, CURLOPT_USERAGENT, $this->UA);

        /** Execute and get the response. */
        $Response = curl_exec($Request);

        /** Close the cURL session. */
        curl_close($Request);

        /** Return the results of the request. */
        return $Response;
    }

    /** Apply shorthand to signature names and remove any unwanted lines. */
    public function shorthand(&$Data) {
        while (true) {
            $Check = md5($Data) . ':' . strlen($Data);
            foreach ([
                ["\x11", 'Win'],
                ["\x12", 'W(?:in)?32'],
                ["\x13", 'W(?:in)?64'],
                ["\x14", '(?:ELF|Linux)'],
                ["\x15", '(?:Macho|OSX)'],
                ["\x16", 'Andr(?:oid)?'],
                ["\x17", '(?:E?mail|EML)'],
                ["\x18", '(?:Javascript|JS|Jscript)'],
                ["\x19", 'Java'],
                ["\x1A", 'XXE'],
                ["\x1B", '(?:Graphics|JPE?G|GIF|PNG)'],
                ["\x1C", '(?:Macro|OLE)'],
                ["\x1D", 'HTML?'],
                ["\x1E", 'RTF'],
                ["\x1F", '(?:Archive|[RT]AR|ZIP)'],
                ["\x20", 'PHP'],
                ["\x21", 'XML'],
                ["\x22", 'ASPX?'],
                ["\x23", 'VB[SEX]?'],
                ["\x24", 'BAT'],
                ["\x25", 'PDF'],
                ["\x26", 'SWF'],
                ["\x27", 'W97M?'],
                ["\x28", 'X97M?'],
                ["\x29", 'O97M?'],
                ["\x2A", 'ASCII'],
                ["\x2B", 'Unix'],
                ["\x2C", 'Py(?:thon)?'],
                ["\x2D", 'Perl'],
                ["\x2E", 'Ruby'],
                ["\x2F", '(?:CFG|IN[IF])'],
                ["\x30", 'CGI'],
            ] as $Param) {
                $Data = preg_replace([
                    "~\x10\x10" . $Param[1] . '[-.]~i',
                    "~\x10\x10([a-z0-9]+[._-])" . $Param[1] . '[-.]~i'
                ], [
                    $Param[0] . "\x10",
                    $Param[0] . "\x10\\1"
                ], $Data);
            }
            foreach ([
                ["\x11", 'Worm'],
                ["\x12", 'Tro?[jy]a?n?'],
                ["\x13", 'Ad(?:ware)?'],
                ["\x14", 'Flooder'],
                ["\x15", 'IRC(?:Bot)?'],
                ["\x16", 'Exp?l?o?i?t?'],
                ["\x17", 'VirTool'],
                ["\x18", 'Dial(?:er)?'],
                ["\x19", '(?:Joke|Hoax)'],
                ["\x1B", 'Malware'],
                ["\x1C", 'Risk(?:ware|y)?'],
                ["\x1D", '(?:Rkit|Rootkit|Root)'],
                ["\x1E", '(?:Backdoor|Back|BD|Door)'],
                ["\x1F", '(?:Hack|Hacktool|HT)'],
                ["\x20", '(?:Key)?logger'],
                ["\x21", 'Ransom(?:ware)?'],
                ["\x22", 'Spy(?:ware)?'],
                ["\x23", 'Vir(?:us)?'],
                ["\x24", 'Dropper'],
                ["\x25", 'Dropped'],
                ["\x26", '(?:Dldr|Downloader)'],
                ["\x27", 'Obfuscation'],
                ["\x28", 'Obfuscator'],
                ["\x29", 'Obfuscated'],
                ["\x2A", 'Packer'],
                ["\x2B", 'Packed'],
                ["\x2C", 'PU[AP]'],
                ["\x2D", 'Shell'],
                ["\x2E", 'Defacer'],
                ["\x2F", 'Defacement'],
                ["\x30", 'Crypt(?:ed|or)?'],
                ["\x31", 'Phish'],
                ["\x32", 'Spam'],
                ["\x33", 'Spammer'],
                ["\x34", 'Scam'],
                ["\x35", 'ZipBomb'],
                ["\x36", 'Fork(?:Bomb)?'],
                ["\x37", 'LogicBomb'],
                ["\x38", 'CyberBomb'],
                ["\x39", 'Malvertisement'],
                ["\x3D", 'Encrypted'],
                ["\x3F", 'BadURL'],
            ] as $Param) {
                $Data = preg_replace([
                    "~\x10" . $Param[1] . '[-.]~i',
                    "~\x10([a-z0-9]+[._-])" . $Param[1] . '[-.]~i'
                ], [
                    $Param[0],
                    $Param[0] . '\1'
                ], $Data);
            }
            $Data = preg_replace([
                '~([^a-z0-9])(?:Agent|General|Generic)([.-])~i', /** Let's reduce our footprint. :-) */
                '~([^a-z0-9])Downloader([.-])~i', /** CVDs use both; Let's normalise it. */
                '~^[^\:\n]+\:[^\n]+[\[\]][^\n]*$~m', /** ClamAV signature format documentation is unclear about what "[]" means. */
                '~^.*This ClamAV version has reached End of Life.*$\n~im' /** Don't need these in phpMussel signatures! */
            ], [
                '\1X\2',
                '\1Dldr\2',
                '',
                ''
            ], $Data);
            if (md5($Data) . ':' . strlen($Data) === $Check) {
                break;
            }
        }
    }

    /** Fix path (we get funky results for "__DIR__ . '/'" in some cases, on some systems). */
    public function fixPath($Path) {
        return str_replace(['\/', '\\', '/\\'], '/', $Path);
    }

}

/** Fetch arguments. */
$RunMode = !empty($argv[1]) ? strtolower($argv[1]) : '';

/** L10N. */
$L10N = [
    'Help' =>
        " SigTool v0.2.0-DEV (last modified: 2017.09.05).\n" .
        " Generates signatures for phpMussel using main.cvd and daily.cvd from ClamAV.\n\n" .
        " Syntax:\n" .
        "  \$ php sigtool.php [arguments]\n" .
        " Example:\n" .
        "  php sigtool.php xpmd\n" .
        " Arguments (all are OFF by default; include to turn ON):\n" .
        "  - No arguments: Display this help information.\n" .
        "  - x Extract signature files from daily.cvd and main.cvd.\n" .
        "  - p Process signature files for use with phpMussel.\n" .
        "  - m Download main.cvd before processing.\n" .
        "  - d Download daily.cvd before processing.\n" .
        "  - u Update SigTool (redownloads sigtool.php and dies; no checks performed).\n\n",
    'Accessing' => ' Accessing %s ...',
    'Deleting' => ' Deleting %s ...',
    'Done' => " Done!\n",
    'Downloading' => ' Downloading %s ...',
    'Failed' => " Failed!\n",
    'Processing' => ' Processing ...',
    'Sorting' => ' Sorting %s ...',
    'Writing' => ' Writing %s ...',
    '_Error0' => ' Can\'t continue (problem on line %s)!',
    '_Phase3_Step1' => ' Stripping ClamAV package header from %s ...',
    '_Phase3_Step2' => ' Decompressing %s (GZ) ...',
    '_Phase3_Step3' => ' Extracting contents from %s (TAR) to ' . __DIR__ . ' ...',
];

/** Terminate with debug information. */
$Terminate = function ($Err = '_Error0') use (&$L10N) {
    $Debug = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    die(sprintf($L10N[$Err], $Debug['line']) . "\n\n");
};

/** Display help information. */
if ($RunMode === '') {
    die($L10N['Help']);
}

/** Initialise SigTool object. */
$SigTool = new SigTool();

/**
 * We'll use Zürich time for our timezone (closest approximate to CET, and
 * required for our "Y.z.B" dates to actually make sense).
 */
date_default_timezone_set('Europe/Zurich');

/** Updating SigTool. */
if (strpos($RunMode, 'u') !== false) {
    echo sprintf($L10N['Downloading'], 'sigtool.php');
    try {
        $Data = $SigTool->fetch('https://raw.githubusercontent.com/phpMussel/SigTool/master/sigtool.php');
    } catch (\Exception $e) {
        $Terminate();
    }
    echo $L10N['Done'] . sprintf($L10N['Writing'], 'sigtool.php');
    if (file_put_contents($SigTool->fixPath(__DIR__ . '/sigtool.php'), $Data)) {
        echo $L10N['Done'];
    } else {
        $Terminate();
    }
    die;
}

/** Phase 1: Download main.cvd. */
if (strpos($RunMode, 'm') !== false) {
    echo sprintf($L10N['Downloading'], 'main.cvd');
    try {
        $Data = $SigTool->fetch('http://database.clamav.net/main.cvd');
    } catch (\Exception $e) {
        $Terminate();
    }
    echo $L10N['Done'] . sprintf($L10N['Writing'], 'main.cvd');
    if (file_put_contents($SigTool->fixPath(__DIR__ . '/main.cvd'), $Data)) {
        echo $L10N['Done'];
    } else {
        $Terminate();
    }
    unset($Data);
}

/** Phase 2: Download daily.cvd. */
if (strpos($RunMode, 'd') !== false) {
    echo sprintf($L10N['Downloading'], 'daily.cvd');
    try {
        $Data = $SigTool->fetch('http://database.clamav.net/daily.cvd');
    } catch (\Exception $e) {
        $Terminate();
    }
    echo $L10N['Done'] . sprintf($L10N['Writing'], 'daily.cvd');
    if (file_put_contents($SigTool->fixPath(__DIR__ . '/daily.cvd'), $Data)) {
        echo $L10N['Done'];
    } else {
        $Terminate();
    }
    unset($Data);
}

/** Phase 3: Extract ClamAV signature files from daily.cvd and main.cvd packages. */
if (strpos($RunMode, 'x') !== false) {
    /** Terminate if daily and main CVD files are missing. */
    if (!file_exists($SigTool->fixPath(__DIR__ . '/daily.cvd')) || !file_exists($SigTool->fixPath(__DIR__ . '/main.cvd'))) {
        $Terminate();
    }

    foreach(['daily.cvd', 'main.cvd'] as $Set) {
        echo sprintf($L10N['_Phase3_Step1'], $Set);
        $File = $SigTool->fixPath(__DIR__ . '/' . $Set);
        $Handle = [fopen($File, 'rb')];
        if (!is_resource($Handle[0])) {
            $Terminate();
        }
        fseek($Handle[0], 512);
        $Handle[1] = fopen($File . '.tmp', 'wb');
        if (!is_resource($Handle[1])) {
            $Terminate();
        }
        while (!feof($Handle[0])) {
            $FileData = fread($Handle[0], $SigTool->SafeReadSize);
            fwrite($Handle[1], $FileData);
        }
        fclose($Handle[1]);
        fclose($Handle[0]);
        unlink($File);
        rename($File . '.tmp', $File);
        echo $L10N['Done'] . sprintf($L10N['_Phase3_Step2'], $Set);
        $Handle = [gzopen($File, 'rb')];
        if (!is_resource($Handle[0])) {
            $Terminate();
        }
        $Handle[1] = fopen($File . '.tmp', 'wb');
        if (!is_resource($Handle[1])) {
            $Terminate();
        }
        while (!gzeof($Handle[0])) {
            $FileData = gzread($Handle[0], $SigTool->SafeReadSize);
            fwrite($Handle[1], $FileData);
        }
        fclose($Handle[1]);
        gzclose($Handle[0]);
        unlink($File);
        rename($File . '.tmp', $File);
        echo $L10N['Done'] . sprintf($L10N['_Phase3_Step3'], $Set);
        $Pad = str_repeat("\x00", 512 - (filesize($File) % 512));
        $Handle = fopen($File, 'ab');
        fwrite($Handle, $Pad);
        fclose($Handle);
        $Files = scandir('phar://' . $File);
        if (is_array($Files)) {
            foreach($Files as $ThisFile) {
                if (empty($ThisFile) || is_dir('phar://' . $File . '/' . $ThisFile)) {
                    continue;
                }
                $Handle = [
                    fopen($SigTool->fixPath('phar://' . $File . '/' . $ThisFile), 'rb'),
                    fopen($SigTool->fixPath(__DIR__ . '/' . $ThisFile), 'wb')
                ];
                if (!is_resource($Handle[0]) || !is_resource($Handle[1])) {
                    $Terminate();
                }
                while (!feof($Handle[0])) {
                    $FileData = fread($Handle[0], $SigTool->SafeReadSize);
                    fwrite($Handle[1], $FileData);
                }
                fclose($Handle[1]);
                fclose($Handle[0]);
            }
        }
        echo $L10N['Done'] . sprintf($L10N['Deleting'], $Set);
        echo unlink($File) ? $L10N['Done'] : $L10N['Failed'];
    }
    /** Cleanup. */
    unset($ThisFile, $Files, $Pad);
}

/** Phase 4: Process signature files for use with phpMussel. */
if (strpos($RunMode, 'p') !== false) {

    /** Check if signatures.dat exists; If so, we'll read it for updating. */
    if (is_readable($SigTool->fixPath(__DIR__ . '/signatures.dat'))) {
        echo sprintf($L10N['Accessing'], 'signatures.dat');
        $Handle = fopen($SigTool->fixPath(__DIR__ . '/signatures.dat'), 'rb');
        $SigTool->setRaw(fread($Handle, filesize($SigTool->fixPath(__DIR__ . '/signatures.dat'))));
        fclose($Handle);
        $SigTool->readIn();
        $Meta = &$SigTool->Arr;
        echo $L10N['Done'];
    }

    /** Don't need these (not currently used by this tool or by phpMussel). */
    foreach ([
        'COPYING',
        'daily.cdb',
        'daily.cfg',
        'daily.crb',
        'daily.fp',
        'daily.ftm',
        'daily.hdu',
        'daily.hsb',
        'daily.hsu',
        'daily.idb',
        'daily.ign',
        'daily.ign2',
        'daily.info',
        'daily.ldb',
        'daily.ldu',
        'daily.mdu',
        'daily.msb',
        'daily.msu',
        'daily.ndu',
        'daily.pdb',
        'daily.sfp',
        'daily.wdb',
        'main.crb',
        'main.fp',
        'main.hdu',
        'main.hsb',
        'main.info',
        'main.ldb',
        'main.msb',
        'main.sfp',
    ] as $File) {
        if (file_exists($SigTool->fixPath(__DIR__ . '/' . $File))) {
            echo sprintf($L10N['Deleting'], $File);
            echo unlink($SigTool->fixPath(__DIR__ . '/' . $File)) ? $L10N['Done'] : $L10N['Failed'];
        }
    }

    /** Main sequence. */
    foreach ([
        ['daily.hdb', 'main.hdb', '~([0-9a-f]{32}\:[0-9]+\:)([^\n]+)\n~', "\\1\x1A\x20\x10\x10\\2\n", 'clamav.hdb', "\x20", 16777216],
        ['daily.mdb', 'main.mdb', '~([0-9]+\:[0-9a-f]{32}\:)([^\n]+)\n~', "\\1\x1A\x20\x10\x10\\2\n", 'clamav.mdb', "\xA0", 16777216],
        ['daily.ndb', 'main.ndb', '~^([^:\n]+\:)~m', "\x1A\x20\x10\x10\\1", 'clamav.ndb', false, 0],
    ] as $Set) {

        /** Fetch and build. */
        if (file_exists($SigTool->fixPath(__DIR__ . '/' . $Set[0])) && file_exists($SigTool->fixPath(__DIR__ . '/' . $Set[1]))) {
            $UseMains = false;
            $FileData = '';
            $Size = 0;
            echo sprintf($L10N['Accessing'], $Set[0]);
            if (!is_resource($Handle = fopen($SigTool->fixPath(__DIR__ . '/' . $Set[0]), 'rb'))) {
                echo $L10N['Failed'];
            } else {
                $Size += filesize($SigTool->fixPath(__DIR__ . '/' . $Set[0]));
                if ($Set[6] > 0 && $Size > $Set[6]) {
                    fseek($Handle, $Size - $Set[6]);
                } else {
                    $UseMains = true;
                }
                while (!feof($Handle)) {
                    $FileData .= fread($Handle, $SigTool->SafeReadSize);
                }
                fclose($Handle);
                echo $L10N['Done'];
            }
            if ($UseMains) {
                echo sprintf($L10N['Accessing'], $Set[1]);
                if (!is_resource($Handle = fopen($SigTool->fixPath(__DIR__ . '/' . $Set[1]), 'rb'))) {
                    echo $L10N['Failed'];
                } else {
                    $Size += filesize($SigTool->fixPath(__DIR__ . '/' . $Set[1]));
                    if ($Set[6] > 0 && $Size > $Set[6]) {
                        fseek($Handle, $Size - $Set[6]);
                    }
                    $RemSize = $Set[6] ? $Set[6] : $Size;
                    while (!feof($Handle) && $RemSize > 0) {
                        $RemSize -= $SigTool->SafeReadSize;
                        $FileData = fread($Handle, $SigTool->SafeReadSize) . $FileData;
                    }
                    if ($RemSize < 1 && substr($FileData, -1, 1) !== "\n" && ($EoF = strrpos($FileData, "\n")) !== false) {
                        $FileData = substr($FileData, 0, $EoF) . "\n";
                    }
                    fclose($Handle);
                    echo $L10N['Done'];
                }
            } elseif (($EoL = strpos($FileData, "\n")) !== false) {
                $FileData = substr($FileData, $EoL + 1);
            }
            echo sprintf($L10N['Writing'], $Set[4]);
            if ($Set[5]) {
                $FileData = 'phpMussel' . $Set[5] . "\n" . $FileData;
            }
            $FileData = preg_replace($Set[2], $Set[3], $FileData);

            /** Apply shorthand to signature names and remove any unwanted lines. */
            $SigTool->shorthand($FileData);

            /** Remove erroneous lines. */
            $FileData = preg_replace('~^(?!phpMussel|\n)[^\x1A\n]+$\n~im', '', $FileData);

            /** Write to file. */
            if (!is_resource($Handle = fopen($SigTool->fixPath(__DIR__ . '/' . $Set[4]), 'wb'))) {
                $Terminate();
            }
            fwrite($Handle, $FileData);
            fclose($Handle);
            if ($Set[5]) {
                if (!is_resource($Handle = gzopen($SigTool->fixPath(__DIR__ . '/' . $Set[4] . '.gz'), 'wb'))) {
                    $Terminate();
                }
                gzwrite($Handle, $FileData);
                gzclose($Handle);
            }

            /** YAML metadata stuff here. */
            if (!empty($Set[5]) && !empty($Set[4]) && !empty($Meta[$Set[4]]['Files']['Checksum'][0]) && !empty($Meta[$Set[4]]['Version'])) {
                /** We use the format Y.z.B for signature file versioning. */
                $Meta[$Set[4]]['Version'] = date('Y.z.B', time());
                $Meta[$Set[4]]['Files']['Checksum'][0] = md5($FileData) . ':' . strlen($FileData);
            }

            echo $L10N['Done'];
            $FileData = '';
        }

        /** Don't need these anymore. */
        foreach ([$Set[0], $Set[1]] as $File) {
            if (file_exists($SigTool->fixPath(__DIR__ . '/' . $File))) {
                echo sprintf($L10N['Deleting'], $File);
                echo unlink($SigTool->fixPath(__DIR__ . '/' . $File)) ? $L10N['Done'] : $L10N['Failed'];
            }
        }

    }

    /** NDB sequence. */
    if (is_readable($SigTool->fixPath(__DIR__ . '/clamav.ndb'))) {
        echo sprintf($L10N['Accessing'], 'clamav.ndb');
        $FileData = '';
        if (!is_resource($Handle = fopen($SigTool->fixPath(__DIR__ . '/clamav.ndb'), 'rb'))) {
            echo $L10N['Failed'];
        } else {
            while (!feof($Handle)) {
                $FileData .= fread($Handle, $SigTool->SafeReadSize);
            }
            fclose($Handle);
            echo $L10N['Done'];
        }
        if (!empty($FileData)) {
            echo $L10N['Processing'];

            /** All the signature files that we're generating from our clamav.ndb file. */
            $FileSets = [
                'clamav.db' => "phpMussel0\n>!\$fileswitch>pefile>-1\n",
                'clamav_regex.db' => "phpMussel@\n>!\$fileswitch>pefile>-1\n",
                'clamav.htdb' => "phpMusselp\n>\$is_html>1>-1\n",
                'clamav_regex.htdb' => "phpMussel\x80\n>\$is_html>1>-1\n",
                'clamav.ndb' => "phpMusselP\n>!\$fileswitch>pefile>-1\n",
                'clamav_regex.ndb' => "phpMussel`\n>!\$fileswitch>pefile>-1\n",
                'clamav_elf.db' => "phpMussel0\n>\$is_elf>1>-1\n",
                'clamav_elf_regex.db' => "phpMussel@\n>\$is_elf>1>-1\n",
                'clamav_email.db' => "phpMussel0\n>\$is_email>1>-1\n",
                'clamav_email_regex.db' => "phpMussel@\n>\$is_email>1>-1\n",
                'clamav_exe.db' => "phpMussel0\n>\$is_pe>1>-1\n",
                'clamav_exe_regex.db' => "phpMussel@\n>\$is_pe>1>-1\n",
                'clamav_graphics.db' => "phpMussel0\n>\$is_graphics>1>-1\n",
                'clamav_graphics_regex.db' => "phpMussel@\n>\$is_graphics>1>-1\n",
                'clamav_java.db' => "phpMussel0\n>!\$fileswitch>pefile>-1\n",
                'clamav_java_regex.db' => "phpMussel@\n>!\$fileswitch>pefile>-1\n",
                'clamav_macho.db' => "phpMussel0\n>\$is_macho>1>-1\n",
                'clamav_macho_regex.db' => "phpMussel@\n>\$is_macho>1>-1\n",
                'clamav_ole.db' => "phpMussel0\n>\$is_ole>1>-1\n",
                'clamav_ole_regex.db' => "phpMussel@\n>\$is_ole>1>-1\n",
                'clamav_pdf.db' => "phpMussel0\n>\$is_pdf>1>-1\n",
                'clamav_pdf_regex.db' => "phpMussel@\n>\$is_pdf>1>-1\n",
                'clamav_swf.db' => "phpMussel0\n>\$is_swf>1>-1\n",
                'clamav_swf_regex.db' => "phpMussel@\n>\$is_swf>1>-1\n",
            ];

            $Offset = 0;
            $SigsNDB = substr_count($FileData, "\n");
            $SigsThis = 0;
            $Percent = '';

            while (($Pos = strpos($FileData, "\n", $Offset)) !== false) {
                $Last = $Percent;
                $Percent = number_format(($SigsThis / $SigsNDB) * 100, 2) . '%';
                $SigsThis++;
                if ($Percent !== $Last) {
                    echo sprintf("\r%s %s", $L10N['Processing'], $Percent);
                }
                $ThisLine = substr($FileData, $Offset, $Pos - $Offset);
                $Offset = $Pos + 1;
                if (strpos($ThisLine, ':') === false) {
                    continue;
                }
                $ThisLine = explode(':', $ThisLine);
                $SigName = empty($ThisLine[0]) ? '' : $ThisLine[0];
                $SigType = empty($ThisLine[1]) ? 0 : (int)$ThisLine[1];
                $SigOffset = empty($ThisLine[2]) ? '' : $ThisLine[2];
                $SigHex = empty($ThisLine[3]) ? '' : $ThisLine[3];
                $StartStop = '';

                /** Sort offsets. */
                if (!empty($SigOffset) && $SigOffset !== '*') {
                    $Start = $SigOffset;
                    /**
                     * Signatures with entry point offsets disregarded for now,
                     * because phpMussel hasn't been coded to handle them yet
                     * anyway (we'll get around to sorting it out eventually).
                     */
                    if (substr($SigOffset, 0, 2) === 'EP') {
                        continue;
                    }
                    if (substr($SigOffset, 0, 4) === 'EOF-') {
                        $StartStop = ':' . (int)substr($SigOffset, 3);
                    } elseif (substr($SigOffset, 0, 1) === 'S') {
                        $StartStop = ':'. $SigOffset;
                    } else {
                        /** Ignoring float shifts because we're not using them. */
                        if (($Comma = strpos($SigOffset, ',')) !== false) {
                            $Start = substr($SigOffset, 0, $Comma);
                        }
                        if ($Start !== '*') {
                            $Start = (int)$Start;
                            if ($Start === 0) {
                                $Start = 'A';
                            }
                            $StartStop = ':' . $Start;
                        }
                    }
                }

                /** Try to avoid dumping into general signatures whenever possible. */
                if ($SigType === 0) {
                    $TargetGuess = substr($SigName, 2, 1);
                    if ($TargetGuess === "\x11" || $TargetGuess === "\x12" || $TargetGuess === "\x13") {
                        $SigType = 1;
                    } elseif ($TargetGuess === "\x14") {
                        $SigType = 6;
                    } elseif ($TargetGuess === "\x15") {
                        $SigType = 9;
                    } elseif ($TargetGuess === "\x17") {
                        $SigType = 4;
                    } elseif ($TargetGuess === "\x19") {
                        $SigType = 12;
                    } elseif ($TargetGuess === "\x1B") {
                        $SigType = 5;
                    } elseif ($TargetGuess === "\x1C") {
                        $SigType = 2;
                    } elseif ($TargetGuess === "\x1D") {
                        $SigType = 3;
                    } elseif ($TargetGuess === "\x25") {
                        $SigType = 10;
                    } elseif ($TargetGuess === "\x26") {
                        $SigType = 11;
                    }
                }

                /** Assign to the appropriate signature file (regex). */
                if (preg_match('/[^a-f0-9*]/i', $SigHex)) {

                    /**
                     * Handle PCRE conversion here (ClamAV to phpMussel formats).
                     * Note that this may need to be changed/adapted in the future upon
                     * relevant changes in the source file occurring (currently accounts
                     * for what we already know about the present, but not for what may
                     * occur in or may be planned for the future).
                     */
                    $SigHex = preg_replace([
                        '~\{([0-9]+)-([0-9]+)\}~',
                        '~\{([0-9]+)-\}~',
                        '~\{-([0-9]+)\}~',
                        '~\{([0-9]+)\}~',
                    ], [
                        '(?:..){\1,\2}',
                        '(?:..){\1,}',
                        '(?:..){0,\1}',
                        '(?:..){\1}',
                    ], str_replace([
                        '*',
                        '?',
                        '{1}',
                        '{0-1}',
                        '{0-}',
                        '{1-}',
                        '(30|31|32|33|34|35|36|37|38|39)',
                        '(31|32|33|34|35|36|37|38|39)',
                        '(22|27)',
                        '(27|22)',
                    ], [
                        '.*',
                        '.',
                        '.',
                        '.?',
                        '.*',
                        '.+',
                        '3[0-9]',
                        '3[1-9]',
                        '2[27]',
                        '2[27]',
                    ], $SigHex));

                    /** Newly formatted signature line. */
                    $ThisLine = $SigName . ':' . $SigHex . $StartStop . "\n";

                    /** Add to file based on signature type (regex). */
                    if ($SigType === 0) {
                        $FileSets['clamav_regex.db'] .= $ThisLine;
                    } elseif ($SigType === 1) {
                        $FileSets['clamav_exe_regex.db'] .= $ThisLine;
                    } elseif ($SigType === 2) {
                        $FileSets['clamav_ole_regex.db'] .= $ThisLine;
                    } elseif ($SigType === 3) {
                        $FileSets['clamav_regex.htdb'] .= $ThisLine;
                    } elseif ($SigType === 4) {
                        $FileSets['clamav_email_regex.db'] .= $ThisLine;
                    } elseif ($SigType === 5) {
                        $FileSets['clamav_graphics_regex.db'] .= $ThisLine;
                    } elseif ($SigType === 6) {
                        $FileSets['clamav_elf_regex.db'] .= $ThisLine;
                    } elseif ($SigType === 7) {
                        $FileSets['clamav_regex.ndb'] .= $ThisLine;
                    } elseif ($SigType === 9) {
                        $FileSets['clamav_macho_regex.db'] .= $ThisLine;
                    } elseif ($SigType === 10) {
                        $FileSets['clamav_pdf_regex.db'] .= $ThisLine;
                    } elseif ($SigType === 11) {
                        $FileSets['clamav_swf_regex.db'] .= $ThisLine;
                    } elseif ($SigType === 12) {
                        $FileSets['clamav_java_regex.db'] .= $ThisLine;
                    }

                /** Assign to the appropriate signature file (non-regex). */
                } else {

                    /** Wildcards and other tricks. */
                    $SigHex = str_replace('*', '>', $SigHex);

                    /** Newly formatted signature line. */
                    $ThisLine = $SigName . ':' . $SigHex . $StartStop . "\n";

                    /** Add to file based on signature type (non-regex). */
                    if ($SigType === 0) {
                        $FileSets['clamav.db'] .= $ThisLine;
                    } elseif ($SigType === 1) {
                        $FileSets['clamav_exe.db'] .= $ThisLine;
                    } elseif ($SigType === 2) {
                        $FileSets['clamav_ole.db'] .= $ThisLine;
                    } elseif ($SigType === 3) {
                        $FileSets['clamav.htdb'] .= $ThisLine;
                    } elseif ($SigType === 4) {
                        $FileSets['clamav_email.db'] .= $ThisLine;
                    } elseif ($SigType === 5) {
                        $FileSets['clamav_graphics.db'] .= $ThisLine;
                    } elseif ($SigType === 6) {
                        $FileSets['clamav_elf.db'] .= $ThisLine;
                    } elseif ($SigType === 7) {
                        $FileSets['clamav.ndb'] .= $ThisLine;
                    } elseif ($SigType === 9) {
                        $FileSets['clamav_macho.db'] .= $ThisLine;
                    } elseif ($SigType === 10) {
                        $FileSets['clamav_pdf.db'] .= $ThisLine;
                    } elseif ($SigType === 11) {
                        $FileSets['clamav_swf.db'] .= $ThisLine;
                    } elseif ($SigType === 12) {
                        $FileSets['clamav_java.db'] .= $ThisLine;
                    }

                }

            }

            echo "\r                                       \r" . $L10N['Processing'] . $L10N['Done'];
            foreach ($FileSets as $FileSet => $FileData) {
                echo sprintf($L10N['Writing'], $FileSet);
                if (!empty($Meta[$FileSet]['Files']['Checksum'][0]) && !empty($Meta[$FileSet]['Version'])) {
                    /** We use the format Y.z.B for signature file versioning. */
                    $Meta[$FileSet]['Version'] = date('Y.z.B', time());
                    $Meta[$FileSet]['Files']['Checksum'][0] = md5($FileData) . ':' . strlen($FileData);
                }
                file_put_contents($SigTool->fixPath(__DIR__ . '/' . $FileSet), $FileData);
                $Handle = gzopen($SigTool->fixPath(__DIR__ . '/' . $FileSet . '.gz'), 'wb');
                gzwrite($Handle, $FileData);
                gzclose($Handle);
                echo $L10N['Done'];
            }
        }
    }

    /** Update signatures.dat if necessary. */
    if (!empty($Meta)) {
        echo sprintf($L10N['Writing'], 'signatures.dat');
        $NewMeta = $SigTool->reconstruct();
        $Handle = fopen($SigTool->fixPath(__DIR__ . '/signatures.dat'), 'wb');
        fwrite($Handle, $NewMeta);
        fclose($Handle);
        echo $L10N['Done'];
    }

}

echo "\n";
