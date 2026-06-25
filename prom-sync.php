<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

$url = 'https://trikobakh.com/catalog-roles.xml?groups%5B%5D=23&groups%5B%5D=46&groups%5B%5D=48&groups%5B%5D=30&groups%5B%5D=7&groups%5B%5D=5&groups%5B%5D=10&groups%5B%5D=20&groups%5B%5D=31&groups%5B%5D=8&groups%5B%5D=34&groups%5B%5D=4&groups%5B%5D=6&groups%5B%5D=32&groups%5B%5D=33&groups%5B%5D=9&groups%5B%5D=13&groups%5B%5D=18';

$temp_file = __DIR__ . '/temp_trikobakh.xml';
// Змінив назву файлу, щоб він гарантовано згенерувався заново
$final_file = __DIR__ . '/prom_v3.xml'; 

if (!file_exists($final_file) || (time() - filemtime($final_file)) > 7200) {

    $fp = fopen($temp_file, 'w+');
    if ($fp) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    }

    $out = fopen($final_file, 'w+');
    if ($out) {
        fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($out, '<yml_catalog date="' . date('Y-m-d H:i') . '">' . "\n");
        fwrite($out, '<shop>' . "\n");
        fwrite($out, '<offers>' . "\n");

        $reader = new XMLReader();
        if ($reader->open($temp_file)) {
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'offer') {
                    $offer_xml = $reader->readOuterXml();
                    $offer_node = @simplexml_load_string($offer_xml);
                    if ($offer_node) {
                        $offer_id = (string)$offer_node['id'];
                        $retail_price = (float)$offer_node->price;
                        $drop_price = (float)$offer_node->price_input;

                        $available = 'false';
                        if ($drop_price > 0 && $drop_price != $retail_price) {
                            $available = 'true';
                        }

                        // РАХУЄМО НАЦІНКУ +25%
                        $final_retail_price = round($retail_price * 1.25);

                        // Тепер ми віддаємо Прому ЦІНУ З НАЦІНКОЮ та ФОТО
                        $offer_output = '    <offer id="' . $offer_id . '" available="' . $available . '">' . "\n";
                        $offer_output .= '        <price>' . $final_retail_price . '</price>' . "\n";
                        
                        if (isset($offer_node->name)) {
                            $offer_output .= '        <name>' . htmlspecialchars((string)$offer_node->name) . '</name>' . "\n";
                        }
                        
                        if (isset($offer_node->picture)) {
                            foreach ($offer_node->picture as $pic) {
                                $offer_output .= '        <picture>' . htmlspecialchars((string)$pic) . '</picture>' . "\n";
                            }
                        }
                        
                        $offer_output .= '    </offer>' . "\n";
                        
                        fwrite($out, $offer_output);
                        unset($offer_node);
                    }
                }
            }
            $reader->close();
        }
        fwrite($out, '</offers>' . "\n");
        fwrite($out, '</shop>' . "\n");
        fwrite($out, '</yml_catalog>');
        fclose($out);
    }
    @unlink($temp_file);
}

header('Content-Type: text/xml; charset=utf-8');
readfile($final_file);
exit;
?>
