<?php

const XSLT_PATH = __DIR__ . '/'; // Directory with the .xslt templates
$PDEPEND_METRICS = [
    // Common to <class>, <method>, <file>
  "name"      => ["label" => "Name", "desc" => "Element name (class, method, file, etc.)", "min" => "", "max" => "", "decimals"=>0],
  "fqname"    => ["label" => "Fully Qualified Name", "desc" => "Full namespace-qualified name", "min" => "", "max" => "", "decimals"=>0],


    // Methods
  "ccn"       => ["label" => "Cyclomatic Complexity", "desc" => "Number of decision paths", "min" => "", "max" => "21", "decimals"=>0],
  "npath"     => ["label" => "NPath Complexity", "desc" => "Number of acyclic paths", "min" => "", "max" => "100", "decimals"=>0],

  "mi"        => ["label" => "Maintainability Index", "desc" => "0–171 scale, higher is better", "min" => "85", "max" => "", "decimals"=>2],
  "hd"        => ["label" => "Halstead Difficulty", "desc" => "Difficulty to understand", "min" => "", "max" => "20", "decimals"=>2],
  "hbugs"     => ["label" => "Estimated Bugs", "desc" => "Estimated bugs (Halstead)", "min" => "", "max" => "", "decimals"=>4],

  "hv"        => ["label" => "Halstead Volume", "desc" => "Volume of implementation", "min" => "", "max" => 1000, "decimals"=>2],
  "ht"        => ["label" => "Halstead Time", "desc" => "Estimated implementation time", "min" => "", "max" => "", "decimals"=>2],
  "he"        => ["label" => "Halstead Effort", "desc" => "Effort required to code", "min" => "", "max" => "", "decimals"=>2],


    // Classes
  "npm"       => ["label" => "Number of Public Methods", "desc" => "Public methods in class", "min" => "", "max" => "12", "decimals"=>0],
  "nom"       => ["label" => "Number of Methods", "desc" => "", "min" => "", "max" => "24", "decimals"=>0],
  "noom"      => ["label" => "Number of Overridden Methods", "desc" => "", "min" => "", "max" => "", "decimals"=>0],
  "noam"      => ["label" => "Number of Added Methods", "desc" => "Class-specific additions", "min" => "", "max" => "", "decimals"=>0],
  "wmc"       => ["label" => "Weighted Methods per Class", "desc" => "Sum of method complexities", "min" => "", "max" => "30", "decimals"=>0],
  "wmcnp"     => ["label" => "WMC (Non-Public)", "desc" => "Non-public WMC", "min" => "", "max" => "", "decimals"=>0],
  "wmci"      => ["label" => "WMC (Inherited)", "desc" => "Inherited weighted methods", "min" => "", "max" => "", "decimals"=>0],

  "vars"      => ["label" => "Class Variables", "desc" => "", "min" => "", "max" => "", "decimals"=>0],
  "varsnp"    => ["label" => "Non-Public Vars", "desc" => "", "min" => "", "max" => "", "decimals"=>0],
  "varsi"     => ["label" => "Inherited Vars", "desc" => "Inherited variables", "min" => "", "max" => "", "decimals"=>0],


  "cr"        => ["label" => "Class Responsibility", "desc" => "Responsibility (outdated)", "min" => "", "max" => "", "decimals"=>0],
  "rcr"       => ["label" => "Relative Class Responsibility", "desc" => "", "min" => "", "max" => "", "decimals"=>0],
  "csz"       => ["label" => "Class Size", "desc" => "Size estimation", "min" => "", "max" => "", "decimals"=>0],
  "cbo"       => ["label" => "Coupling Between Objects", "desc" => "# of used classes", "min" => "", "max" => "", "decimals"=>0],
  "ce"        => ["label" => "Efferent Coupling", "desc" => "Outgoing class dependencies", "min" => "", "max" => "", "decimals"=>0],
  "ca"        => ["label" => "Afferent Coupling", "desc" => "Incoming dependencies", "min" => "", "max" => "", "decimals"=>0],

  "impl"      => ["label" => "Implemented Interfaces", "desc" => "# of implemented interfaces", "min" => "", "max" => "", "decimals"=>0],
  "cis"       => ["label" => "Class Interface Size", "desc" => "Public members exposed", "min" => "", "max" => "", "decimals"=>0],
  "dit"       => ["label" => "Depth of Inheritance Tree", "desc" => "", "min" => "", "max" => "3", "decimals"=>0],


    // From dependency_log.xml
  "I"                => ["label" => "Instability", "desc" => "0 (stable) to 1 (unstable)", "min" => "", "max" => "", "decimals"=>0],
  "D"                => ["label" => "Distance", "desc" => "Distance from ideal design", "min" => "", "max" => "", "decimals"=>0],
  "TotalClasses"     => ["label" => "Total Classes", "desc" => "Classes in package", "min" => "", "max" => "", "decimals"=>0],
  "ConcreteClasses"  => ["label" => "Concrete Classes", "desc" => "Non-abstract classes", "min" => "", "max" => "", "decimals"=>0],
  "AbstractClasses"  => ["label" => "Abstract Classes", "desc" => "", "min" => "", "max" => "", "decimals"=>0],
  "A"                => ["label" => "Abstractness", "desc" => "Proportion of abstract classes", "min" => "", "max" => "", "decimals"=>0],

  "eloc"      => ["label" => "Executable LOC", "desc" => "Executable lines of code", "min" => "", "max" => "", "decimals"=>0],
  "lloc"      => ["label" => "Logical LOC", "desc" => "Logical statements", "min" => "", "max" => "", "decimals"=>0],
  "cloc"      => ["label" => "Comment LOC", "desc" => "Lines containing comments", "min" => "", "max" => "", "decimals"=>0],
  "loc"       => ["label" => "Lines of Code (LOC)", "desc" => "Total lines of code", "min" => "", "max" => "250", "decimals"=>0],
  "ncloc"     => ["label" => "Non-Comment LOC", "desc" => "LOC minus comments", "min" => "", "max" => "", "decimals"=>0],
  "start"     => ["label" => "Start Line", "desc" => "First line of the element", "min" => "", "max" => "", "decimals"=>0],
  "end"       => ["label" => "End Line", "desc" => "Last line of the element", "min" => "", "max" => "", "decimals"=>0],
];


// The script now expects 5 arguments: script_name, report_output_path, xml_input_path, mainNamespace, packageNamespace
if($argc !== 5) {
    echo "\r\nUsage: php $argv[0] <reportOutputPath> <xmlInputPath> <mainNamespace> <packageNamespace>\r\n";
    print_r($argv);
    exit(1);
}

$reportOutputPath = rtrim($argv[1], '/\\'); // Ensure no trailing slash
$xmlInputPath = rtrim($argv[2], '/\\');     // Ensure no trailing slash
$mainNamespace = $argv[3];
$packageNamespace = $argv[4];

// Call functions with the new parameters
summary($reportOutputPath, $xmlInputPath);
dependencyLog($reportOutputPath, $xmlInputPath);
dependencies($reportOutputPath, $xmlInputPath, $mainNamespace, $packageNamespace);


/**
 * Generates an HTML dependency log report from dependency_log.xml.
 *
 * @param string $reportOutputPath Path to directory where to save the HTML.
 * @param string $xmlInputPath Path to directory containing dependency_log.xml.
 */
function dependencyLog(string $reportOutputPath, string $xmlInputPath): void {
    $xmlFile = $xmlInputPath . '/dependency_log.xml';
    $outputFile = $reportOutputPath . '/dependency-log.html';
    $xsltFile = rtrim(XSLT_PATH, '/\\') . '/pdepend-dependency-log-to-html.xslt';

    if(!file_exists($xmlFile)) {
        echo "❌ Error: XML file not found: $xmlFile\n";
        return;
    }
    if(!file_exists($xsltFile)) {
        echo "❌ Error: XSLT template not found: $xsltFile\n";
        return;
    }

    $xml = new DOMDocument();
    $xml->load($xmlFile);

    $xsl = new DOMDocument();
    $xsl->load($xsltFile);

    $proc = new XSLTProcessor();
    $proc->importStylesheet($xsl);

    $html = $proc->transformToXML($xml);

    if($html === FALSE) {
        echo "❌ Error: XSLT transformation failed.\n";
        return;
    }

    file_put_contents($outputFile, $html);
    echo "✔ Dependency log report written to: $outputFile\n";
}

/**
 * Generates an HTML summary report using an XSLT template.
 *
 * @param string $reportOutputPath Path to directory where to save the HTML.
 * @param string $xmlInputPath Path to directory containing summary.xml.
 */
function summary(string $reportOutputPath, string $xmlInputPath): void {
    global $PDEPEND_METRICS;
    $summaryFile = $xmlInputPath . '/summary.xml';
    if(!file_exists($summaryFile)) {
        echo "❌ Error: XML file not found: $summaryFile\n";
        return;
    }

    $dom = new DOMDocument();
    $dom->load($summaryFile);
    $xpath = new DOMXPath($dom);

    $localMenu = <<<HTML
<div class="localMenu">
<div><a href="#total_metrics_">Total Metrics</a></div>
<div><a href="#files_">📄 Files</a></div>
<div><a href="#classes_">🏛 Classes</a></div>
<div><a href="#methods_">🔧 Methods</a></div>
</div>
HTML;

    $report = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>PDepend Summary</title>
        <style>
            body { font-family: sans-serif; margin: 2em; }
            h2 { border-bottom: 2px solid #ddd; padding-bottom: 0.2em;margin-bottom: 0 }
            table { border-collapse: collapse; margin-bottom: 2em; }
            th, td { border: 1px solid #ccc; padding: 0.4em;  }
            th { background-color: #f0f0f0; }
            td {text-align: right;}
            .warn { color: red; }
            .center { text-align: center; }
            .lft { text-align: left; }
            .indent {padding-left: 4ch}
            .localMenu {display: inline-flex;flex-wrap: wrap;gap: 1em;justify-content: flex-start;align-items: flex-start;
                margin: 0;padding: 0 4ch;font-size: 1rem;font-weight: normal;
            }
        </style>
    </head>
    <body>
    <h1>📊 PDepend Summary Report</h1>
    HTML;

    // Summary Totals
    if ($metrics = $xpath->query('//metrics')->item(0)?->attributes) {
        $report .= "<h2 id='total_metrics_'>Total Metrics$localMenu</h2><table><thead><tr>";
        foreach ($metrics as $attr) {
            $name = $attr->nodeName;
            $label = $PDEPEND_METRICS[$name]['label'] ?? $name;
            $report .= " <th>$label</th>";
        }
        $report .= "</thead></tbody><tr>";
        foreach ($metrics as $attr) {
            $value = number_format((float)$attr->nodeValue);
            $report .= " <td>$value</td>";
        }
        $report .= "</tbody></table>";
    }

    // Files
    $report .= "<h2 id='files_'>📄 Files$localMenu</h2><table><thead><tr>";
    $fileKeys = [];
    foreach ($PDEPEND_METRICS as $key => $meta) {
        // Only include 'name' (for filename) and 'fqname' (for full path) if they exist
        if (($key === "name" || $key === "fqname") && $xpath->evaluate("boolean(//file/@$key)")) {
            $fileKeys[] = $key;
            $report .= "<th title='". htmlentities($meta['label'] ?? $key) ."'>" . htmlentities($meta['label'] ?? $key) . "</th>";
        } else if ($key !== "name" && $key !== "fqname" && $xpath->evaluate("boolean(//file/@$key)")) {
            $fileKeys[] = $key;
            $report .= "<th title='". htmlentities($meta['label'] ?? $key) ."'>" . htmlentities($key) . "</th>";
        }
    }
    $report .= "</tr></thead><tbody>";
    foreach ($xpath->query('//file') as $file) {
        $report .= "<tr>";
        foreach ($fileKeys as $key) {
            $val = $file->getAttribute($key);
            $warn = '';
            $formatted = $val;

            if ($key === 'name' || $key === 'fqname') {
                $warn = " class='lft'"; // Left align for names/paths
                // For 'name', extract just the filename
                if ($key === 'name') {
                    $formatted = basename($val);
                }
            } elseif (is_numeric($val)) {
                $decimals = (int)$PDEPEND_METRICS[$key]['decimals'] ?? 0;
                $formatted = number_format($val, $decimals, $decimals ? "." : "");
                $warn = !empty($PDEPEND_METRICS[$key]['max']) && $val > $PDEPEND_METRICS[$key]['max'] ? ' class="warn"' : '';
            }

            $report .= "<td$warn>" . htmlentities($formatted ?: '-') . "</td>";
        }
        $report .= "</tr>";
    }
    $report .= "</tbody></table>";

    // Classes
    $report .= "<h2 id='classes_'>🏛 Classes$localMenu</h2><table><thead><tr>";
    $classKeys = [];
    foreach ($PDEPEND_METRICS as $key => $meta) {
        if($key === "name")
            continue;
        if ($xpath->evaluate("boolean(//class/@$key)")) {
            $classKeys[] = $key;
            $report .= "<th title='". htmlentities($meta['label'] ?? $key) ."'>" . htmlentities($meta['label'] ?? $key) . "</th>";
        }
    }
    $report .= "</tr></thead><tbody>";
    foreach ($xpath->query('//class') as $class) {
        $fqname = $class->getAttribute('fqname');
        $safeId = str_replace('\\', '_', $fqname);
        $report .= "<tr id=\"$safeId\">";
        foreach ($classKeys as $key) {
            if($key === "name")
                continue;
            $val = $class->getAttribute($key);
            if(is_numeric($val)) {
                $decimals = (int)$PDEPEND_METRICS[$key]['decimals'] ?? 0;
                $formatted = number_format($val, $decimals, $decimals ? "." : "");
            } else
                $formatted = $val;

            if($key === "fqname")
                $warn = " class='lft'";
            else
                $warn = isset($PDEPEND_METRICS[$key]['max']) && $val > $PDEPEND_METRICS[$key]['max'] ? ' class="warn"' : '';
            $report .= "<td$warn>" . htmlentities($formatted ?: '-') . "</td>";
        }
        $report .= "</tr>";
    }
    $report .= "</tbody></table>";

    // Methods
    $report .= "<h2 id='methods_'>🔧 Methods$localMenu</h2><table><thead><tr>";
    $header = "";
    $methodKeys = [];
    foreach ($PDEPEND_METRICS as $key => $meta) {
        if ($xpath->evaluate("boolean(//method/@$key)")) {
            $methodKeys[] = $key;
            $report .= "<th title='". htmlentities($meta['label'] ?? $key) ."'>" . htmlentities($meta['label'] ?? $key) . "</th>";
            if($key !== "name")
                $header .= "<th title='". htmlentities($meta['label'] ?? $key) ."'>" . htmlentities($meta['label'] ?? $key) . "</th>";
        }
    }
    $report .= "</tr></thead><tbody>";
    $lastClass = '';
    foreach ($xpath->query('//method') as $method) {
        $parent = $method->parentNode;
        $fqClass = $parent->getAttribute('fqname');
        $safeId = str_replace('\\', '_', $fqClass);
        if ($fqClass !== $lastClass) {
            $report .= "<tr><td class='lft'><b>" . htmlentities($fqClass) . "</b>$header</tr>";
            $lastClass = $fqClass;
        }
        $report .= "<tr id=\"$safeId\">";
        foreach ($methodKeys as $key) {
            $val = $method->getAttribute($key);
            if(is_numeric($val)) {
                $decimals = (int)$PDEPEND_METRICS[$key]['decimals'] ?? 0;
                $formatted = number_format($val, $decimals, $decimals ? "." : "");
                $warn = !empty($PDEPEND_METRICS[$key]['max']) && $val > $PDEPEND_METRICS[$key]['max'] ? ' class="warn"' : '';
            } else {
                $formatted = $val;
                $warn = " class='lft indent'";
            }

            $report .= "<td$warn>" . ($formatted ?: '-') . "</td>";
        }
        $report .= "</tr>";
    }
    $report .= "</tbody></table></body></html>";
    $outputFile = $reportOutputPath . '/summary.html';
    file_put_contents($outputFile, $report);
    echo "✔ Rich summary HTML report written to: $outputFile\n";
}




/**
 * Generates an HTML dependency report using an XSLT template.
 *
 * @param string $reportOutputPath Path to directory where to save HTML.
 * @param string $xmlInputPath Path to directory containing dependencies.xml.
 * @param string $mainNamespace The root vendor namespace (e.g., 'ocallit').
 * @param string $packageNamespace The full package namespace (e.g., 'ocallit\\SqlEr').
 */
function dependencies(string $reportOutputPath, string $xmlInputPath, string $mainNamespace, string $packageNamespace): void {
    $xmlFile = $xmlInputPath . '/dependencies.xml';
    $outputFile = $reportOutputPath . '/dependencies.html';
    $xsltFile = rtrim(XSLT_PATH, '/\\') . '/pdepend-dependencies-to-html.xslt';

    if(!file_exists($xmlFile)) {
        echo "❌ Error: XML file not found: $xmlFile\n";
        return;
    }
    if(!file_exists($xsltFile)) {
        echo "❌ Error: XSLT template not found: $xsltFile\n";
        return;
    }

    // Load and modify XSLT content with injected parameters
    $xsltContent = file_get_contents($xsltFile);
    $xsltContent = preg_replace(
      [
        '/<xsl:variable name="mainNamespace" select=\'.*?\' \/>/',
        '/<xsl:variable name="packageNamespace" select=\'.*?\' \/>/',
      ],
      [
        '<xsl:variable name="mainNamespace" select="\'' . htmlentities($mainNamespace, ENT_QUOTES) . '\'" />',
        '<xsl:variable name="packageNamespace" select="\'' . htmlentities($packageNamespace, ENT_QUOTES) . '\'" />',
      ],
      $xsltContent
    );

    $tempXsltFile = tempnam(sys_get_temp_dir(), 'xslt_');
    file_put_contents($tempXsltFile, $xsltContent);

    $xml = new DOMDocument();
    $xml->load($xmlFile);

    $xsl = new DOMDocument();
    $xsl->load($tempXsltFile);

    $proc = new XSLTProcessor();
    $proc->importStylesheet($xsl);

    $html = $proc->transformToXML($xml);
    unlink($tempXsltFile);

    if($html === FALSE) {
        echo "❌ Error: XSLT transformation failed.\n";
        return;
    }

    file_put_contents($outputFile, $html);
    echo "✔ Dependency report written to: $outputFile\n";
}
