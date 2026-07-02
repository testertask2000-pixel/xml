<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

$url = 'https://trikobakh.com/catalog-roles.xml?groups%5B%5D=23';

$temp_file = __DIR__ . '/temp_trikobakh.xml';
$final_file = __DIR__ . '/prom_final_v3.xml'; 
$history_file = __DIR__ . '/trikobakh_history.json';

// Завантажуємо історію товарів, якщо вона існує
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true) ?: [];
}

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

        $current_ids = []; // Сюди збираємо ID, які є в поточному файлі постачальника

        $reader = new XMLReader();
        if ($reader->open($temp_file)) {
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'offer') {
                    $offer_xml = $reader->readOuterXml();
                    $offer_node = @simplexml_load_string($offer_xml);
                    if ($offer_node) {
                        $offer_id = (string)$offer_node['id'];
                        $group_id = isset($offer_node['group_id']) ? (string)$offer_node['group_id'] : '';
                        
                        $retail_price = (float)$offer_node->price;
                        $drop_price = (float)$offer_node->price_input;

                        $available = 'false';
                        if ($drop_price > 0 && $drop_price != $retail_price) {
                            $available = 'true';
                        }

                        $final_retail_price = round($retail_price * 1.25);
                        $item_name = isset($offer_node->name) ? (string)$offer_node->name : '';

                        // Фіксуємо, що цей товар зараз є в наявності
                        $current_ids[$offer_id] = true;

                        // Оновлюємо або додаємо товар в історію
                        $history[$offer_id] = [
                            'group_id' => $group_id,
                            'price' => $final_retail_price,
                            'name' => $item_name
                        ];

                        // Формуємо XML для поточного товару
                        $offer_output = '    <offer id="' . $offer_id . '" available="' . $available . '"';
                        if ($group_id !== '') {
                            $offer_output .= ' group_id="' . $group_id . '"';
                        }
                        $offer_output .= '>' . "\n";
                        $offer_output .= '        <price>' . $final_retail_price . '</price>' . "\n";
                        $offer_output .= '        <vendorCode>' . $offer_id . '</vendorCode>' . "\n";
                        if ($item_name !== '') {
                            $offer_output .= '        <name>' . htmlspecialchars($item_name) . '</name>' . "\n";
                        }
                        
                        if (isset($offer_node->picture)) {
                            $pic_count = 0;
                            foreach ($offer_node->picture as $pic) {
                                if ($pic_count >= 10) break; 
                                $offer_output .= '        <picture>' . htmlspecialchars((string)$pic) . '</picture>' . "\n";
                                $pic_count++;
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

        // ВАЖЛИВА ЧАСТИНА: Перевіряємо історію. 
        // Якщо товар був раніше, але зник з поточного файлу постачальника — виводимо його як available="false"
        foreach ($history as $old_id => $data) {
            if (!isset($current_ids[$old_id])) {
                $offer_output = '    <offer id="' . $old_id . '" available="false"';
                if (!empty($data['group_id'])) {
                    $offer_output .= ' group_id="' . $data['group_id'] . '"';
                }
                $offer_output .= '>' . "\n";
                $offer_output .= '        <price>' . $data['price'] . '</price>' . "\n";
                $offer_output .= '        <vendorCode>' . $old_id . '</vendorCode>' . "\n";
                if (!empty($data['name'])) {
                    $offer_output .= '        <name>' . htmlspecialchars($data['name']) . '</name>' . "\n";
                }
                $offer_output .= '    </offer>' . "\n";
                
                fwrite($out, $offer_output);
            }
        }

        fwrite($out, '</offers>' . "\n");
        fwrite($out, '</shop>' . "\n");
        fwrite($out, '</yml_catalog>');
        fclose($out);

        // Зберігаємо оновлену історію в JSON файл
        file_put_contents($history_file, json_encode($history, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    @unlink($temp_file);
}

header('Content-Type: text/xml; charset=utf-8');
readfile($final_file);
exit;
?>
