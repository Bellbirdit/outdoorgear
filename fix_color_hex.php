<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Modules\Variation\Entities\Variation;
use Modules\Variation\Entities\VariationValue;

$colors = [
    'red' => '#ff0000',
    'blue' => '#0000ff',
    'green' => '#00ff00',
    'black' => '#000000',
    'white' => '#ffffff',
    'yellow' => '#ffff00',
    'orange' => '#ffa500',
    'purple' => '#800080',
    'pink' => '#ffc0cb',
    'brown' => '#a52a2a',
    'gray' => '#808080',
    'grey' => '#808080',
    'navy' => '#000080',
    'teal' => '#008080',
    'maroon' => '#800000',
    'olive' => '#808000',
    'aqua' => '#00ffff',
    'cyan' => '#00ffff',
    'silver' => '#c0c0c0',
    'gold' => '#ffd700',
    'beige' => '#f5f5dc',
    'coral' => '#ff7f50',
    'crimson' => '#dc143c',
    'indigo' => '#4b0082',
    'khaki' => '#f0e68c',
    'lime' => '#00ff00',
    'magenta' => '#ff00ff',
    'violet' => '#ee82ee',
    'tan' => '#d2b48c',
    'turquoise' => '#40e0d0',
];

$colorVariation = Variation::where('uid', 'color')->first();
if (!$colorVariation) { die("Color variation not found!"); }

echo "<h2>Fixing Color Values</h2><pre>";
$colorValues = VariationValue::where('variation_id', $colorVariation->id)->get();

foreach ($colorValues as $value) {
    $currentValue = trim($value->value ?? '');
    $lowerValue = strtolower($currentValue);
    
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $currentValue)) {
        echo "SKIP: '{$currentValue}' - already hex\n";
        continue;
    }
    
    if (isset($colors[$lowerValue])) {
        $hexCode = $colors[$lowerValue];
        echo "UPDATE: '{$currentValue}' => '{$hexCode}'\n";
        $value->value = $hexCode;
        $value->save();
    } else {
        echo "NO MAPPING: '{$currentValue}'\n";
    }
}
echo "</pre><h3>Done! Delete this file now.</h3>";