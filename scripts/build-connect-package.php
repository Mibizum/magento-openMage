<?php
/**
 * build-connect-package.php
 *
 * Generates the **Magento Connect** `package.xml` (Magento 1.x format 2.0) for
 * the Mibizum_Sync module: it walks the module files under `src/`, computes the
 * md5 hash of each one and builds the <contents> tree grouped by the Connect
 * "targets" (mageetc, magecommunity, magedesign, magelocale, mageskin).
 *
 * It is invoked by the packager (package-mibizum-sync.sh). The Connect-compatible
 * .tgz is assembled by the bash script: package.xml at the archive root + the
 * app/ + skin/ tree (the same real paths as the install). The Magento Connect
 * Manager / `./mage install` read package.xml, resolve each target to its real
 * folder and verify the hashes.
 *
 * Usage:
 *   php build-connect-package.php /path/to/package.xml
 *
 * Compatible with PHP 5.4+.
 */

error_reporting(E_ALL);

$outPath = isset($argv[1]) ? $argv[1] : null;
if (!$outPath) {
    fwrite(STDERR, "ERROR: missing the package.xml output path\n");
    fwrite(STDERR, "Usage: php build-connect-package.php /path/package.xml\n");
    exit(1);
}

$SRC = dirname(__DIR__) . '/src';
if (!is_dir($SRC)) {
    fwrite(STDERR, "ERROR: the src/ directory does not exist at $SRC\n");
    exit(1);
}

$configXml = $SRC . '/app/code/community/Mibizum/Sync/etc/config.xml';
if (!is_file($configXml)) {
    fwrite(STDERR, "ERROR: cannot find config.xml at $configXml\n");
    exit(1);
}

// Module version (first <version> of the Mibizum_Sync block).
$cfg = file_get_contents($configXml);
if (!preg_match('#<version>([^<]+)</version>#', $cfg, $m)) {
    fwrite(STDERR, "ERROR: could not read the version from config.xml\n");
    exit(1);
}
$version = trim($m[1]);

// -----------------------------------------------------------------------------
// Mapping of Magento-root folder -> Connect target.
// Order matters: the first prefix that matches wins (app/etc before app, etc.).
// That is why they go from most specific to most generic.
// -----------------------------------------------------------------------------
$targetMap = array(
    'app/etc/'            => array('target' => 'mageetc',       'strip' => 'app/etc/'),
    'app/code/community/' => array('target' => 'magecommunity', 'strip' => 'app/code/community/'),
    'app/design/'         => array('target' => 'magedesign',    'strip' => 'app/design/'),
    'app/locale/'         => array('target' => 'magelocale',    'strip' => 'app/locale/'),
    'skin/'               => array('target' => 'mageskin',      'strip' => 'skin/'),
);

// Paths (relative to src/) that make up the module (MUST match the packager's).
// Includes the activator: Connect extracts everything atomically and then runs
// the setup, so the activator does belong here (unlike the two-phase FTP, which
// is a problem specific to a partial manual upload).
$paths = array(
    'app/etc/modules/Mibizum_Sync.xml',
    'app/code/community/Mibizum/Sync',
    'app/design/frontend/base/default/layout/mibizum_sync.xml',
    'app/design/frontend/base/default/template/mibizum_sync',
    'app/design/adminhtml/default/default/layout/mibizum_sync.xml',
    'app/design/adminhtml/default/default/template/mibizum_sync',
    'skin/adminhtml/default/default/mibizum_sync',
);

// Locale CSVs: one Mibizum_Sync.csv per language (es_ES, de_DE, fr_FR, ...),
// added at FILE granularity so we never overshadow other modules' translations.
// New languages are picked up automatically.
foreach (glob($SRC . '/app/locale/*/Mibizum_Sync.csv') as $abs) {
    $paths[] = ltrim(substr(str_replace('\\', '/', $abs), strlen($SRC)), '/');
}

/**
 * Recursively collects all files under a path (file or dir).
 * @return string[] paths relative to $SRC, with '/' as the separator.
 */
function collectFiles($srcRoot, $relPath)
{
    $abs = $srcRoot . '/' . $relPath;
    $out = array();
    if (is_file($abs)) {
        $out[] = $relPath;
        return $out;
    }
    if (!is_dir($abs)) {
        fwrite(STDERR, "ERROR: missing '$relPath' in src/ (renamed/moved?)\n");
        exit(1);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $info) {
        if (!$info->isFile()) {
            continue;
        }
        $name = $info->getFilename();
        // Exclude OS junk (same as the packager).
        if ($name === '.DS_Store' || substr($name, 0, 2) === '._' || substr($name, -4) === '.swp') {
            continue;
        }
        $full = str_replace('\\', '/', $info->getPathname());
        $rel  = ltrim(substr($full, strlen($srcRoot)), '/');
        $out[] = $rel;
    }
    return $out;
}

// Group files by target, building a nested directory tree.
$trees = array();   // target => nested tree
$count = 0;
foreach ($paths as $p) {
    foreach (collectFiles($SRC, $p) as $rel) {
        // Determine target.
        $matched = null;
        foreach ($targetMap as $prefix => $info) {
            if (strpos($rel, $prefix) === 0) {
                $matched = $info;
                break;
            }
        }
        if ($matched === null) {
            fwrite(STDERR, "ERROR: no Connect target for '$rel'\n");
            exit(1);
        }
        $target = $matched['target'];
        $inner  = substr($rel, strlen($matched['strip']));   // path inside the target
        $hash   = md5_file($SRC . '/' . $rel);

        if (!isset($trees[$target])) {
            $trees[$target] = array('dirs' => array(), 'files' => array());
        }
        // Insert into the target's tree.
        $segments = explode('/', $inner);
        $fileName = array_pop($segments);
        $node =& $trees[$target];
        foreach ($segments as $seg) {
            if (!isset($node['dirs'][$seg])) {
                $node['dirs'][$seg] = array('dirs' => array(), 'files' => array());
            }
            $node =& $node['dirs'][$seg];
        }
        $node['files'][$fileName] = $hash;
        unset($node);
        $count++;
    }
}

/** Recursive render of a tree node to <dir>/<file> XML. */
function renderNode($node, $indent)
{
    $xml = '';
    ksort($node['dirs']);
    ksort($node['files']);
    foreach ($node['dirs'] as $name => $child) {
        $xml .= $indent . '<dir name="' . htmlspecialchars($name, ENT_QUOTES) . '">' . "\n";
        $xml .= renderNode($child, $indent . '    ');
        $xml .= $indent . '</dir>' . "\n";
    }
    foreach ($node['files'] as $name => $hash) {
        $xml .= $indent . '<file name="' . htmlspecialchars($name, ENT_QUOTES) . '" hash="' . $hash . '"/>' . "\n";
    }
    return $xml;
}

// Stable order of targets in the XML.
$targetOrder = array('mageetc', 'magecommunity', 'magedesign', 'magelocale', 'mageskin');
$contentsXml = '';
foreach ($targetOrder as $target) {
    if (!isset($trees[$target])) {
        continue;
    }
    $contentsXml .= '        <target name="' . $target . '">' . "\n";
    $contentsXml .= renderNode($trees[$target], '            ');
    $contentsXml .= '        </target>' . "\n";
}

$date = gmdate('Y-m-d');
$time = gmdate('H:i:s');

$notes = 'Production-ready release (0.6.1): safe-disable hardening (observers/cron/worker '
       . 'bail out if the connection is not active), tolerance to missing tables and distribution '
       . 'through all the standard Magento 1 channels (modman, composer, two-phase FTP, Connect).';

$xml = '<?xml version="1.0"?>' . "\n"
     . '<package>' . "\n"
     . '    <name>Mibizum_Sync</name>' . "\n"
     . '    <version>' . htmlspecialchars($version, ENT_QUOTES) . '</version>' . "\n"
     . '    <stability>stable</stability>' . "\n"
     . '    <license uri="https://opensource.org/licenses/MIT">MIT</license>' . "\n"
     . '    <channel>community</channel>' . "\n"
     . '    <extends/>' . "\n"
     . '    <summary>Connects Magento 1.x / OpenMage with the Mibizum search engine.</summary>' . "\n"
     . '    <description>Mibizum_Sync indexes the Magento catalog into the Mibizum backend (observers + cron drain with a DB queue), '
     . 'overrides the Enter key on /catalogsearch/result/ toward the Mibizum engine (with a fallback to native MySQL) and injects the '
     . 'search widget snippet on the storefront. Safe-disable: when the module is off or not connected, the store falls back '
     . 'cleanly to the native Magento search.</description>' . "\n"
     . '    <notes>' . htmlspecialchars($notes, ENT_QUOTES) . '</notes>' . "\n"
     . '    <authors>' . "\n"
     . '        <author><name>Mibizum</name><user>mibizum</user><email>soporte@mibizum.io</email></author>' . "\n"
     . '    </authors>' . "\n"
     . '    <date>' . $date . '</date>' . "\n"
     . '    <time>' . $time . '</time>' . "\n"
     . '    <contents>' . "\n"
     . $contentsXml
     . '    </contents>' . "\n"
     . '    <compatible/>' . "\n"
     . '    <dependencies>' . "\n"
     . '        <required>' . "\n"
     . '            <php><min>5.4.0</min><max>7.4.99</max></php>' . "\n"
     . '        </required>' . "\n"
     . '    </dependencies>' . "\n"
     . '</package>' . "\n";

// Validate that the XML is well-formed before writing.
libxml_use_internal_errors(true);
$doc = simplexml_load_string($xml);
if ($doc === false) {
    fwrite(STDERR, "ERROR: the generated package.xml is not well-formed XML:\n");
    foreach (libxml_get_errors() as $e) {
        fwrite(STDERR, '  ' . trim($e->message) . "\n");
    }
    exit(1);
}

if (file_put_contents($outPath, $xml) === false) {
    fwrite(STDERR, "ERROR: could not write $outPath\n");
    exit(1);
}

fwrite(STDERR, "package.xml generated: $count files, version $version -> $outPath\n");
// Emit the version on stdout so the bash script can capture it if needed.
echo $version . "\n";
