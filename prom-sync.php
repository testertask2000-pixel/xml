<?php
// 1. Вмикаємо показ помилок для дебагу (якщо щось піде не так — побачиш причину на екрані)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 2. Знімаємо ліміти (щоб скрипт не обірвався під час скачування великого файлу)
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

// URL постачальника
$url = 'https://trikobakh.com/catalog-roles.xml?groups%5B%5D=23&groups%5B%5D=46&groups%5B%5D=48&groups%5B%5D=30&groups%5B%5D=7&groups%5B%5D=5&groups%5B%5D=10&groups%5B%5D=20&groups%5B%5D=31&groups%5B%5D=8&groups%5B%5D=34&groups%5B%5D=4&groups%5B%5D=6&groups%5B%5D=32&groups%5B%5D=33&groups%5B%5D=9&groups%5B%5D=13&groups%5B%5D=18';

// Шляхи до файлів (зберігатимуться поруч із цим скриптом)
$temp_file = __DIR__ . '/temp_trikobakh.xml';
$final_file = __DIR__ . '/prom.xml';

// --- ЕТАП 1: Скачуємо файл постачальника ---
$fp = fopen($temp_file, 'w+');
if (!$fp) {
    die('Помилка: Не вдалося створити тимчасовий файл. Перевірте права доступу до папки (мають бути 755 або 777).');
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
$curl_result = curl_exec($ch);

if ($curl_result === false) {
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    die('Помилка скачування cURL: ' . $error);
}
curl_close($ch);
fclose($fp);

// --- ЕТАП 2: Створюємо новий легкий файл для Prom.ua ---
$out = fopen($final_file, 'w+');
if (!$out) {
    die('Помилка: Не вдалося створити файл prom.xml. Перевірте права папки.');
}

// Пишемо заголовки
fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
fwrite($out, '<yml_catalog date="' . date('Y-m-d H:i') . '">' . "\n");
fwrite($out, '<shop>' . "\n");
fwrite($out, '<offers>' . "\n");

// --- ЕТАП 3: Читаємо завантажений файл по одному вузлу (економія пам'яті) ---
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
                // Логіка наявності: якщо дроп ціна є і вона не дорівнює роздрібній
                if ($drop_price > 0 && $drop_price != $retail_price) {
                    $available = 'true';
                }

                // Записуємо готовий товар у prom.xml
                $line = '    <offer id="' . $offer_id . '" available="' . $available . '"></offer>' . "\n";
                fwrite($out, $line);
                
                unset($offer_node); // Звільняємо пам'ять
            }
        }
    }
    $reader->close();
} else {
    die('Помилка: Не вдалося прочитати тимчасовий XML файл.');
}

// Закриваємо теги
fwrite($out, '</offers>' . "\n");
fwrite($out, '</shop>' . "\n");
fwrite($out, '</yml_catalog>');
fclose($out);

// --- ЕТАП 4: Прибираємо за собою ---
@unlink($temp_file);

echo "✅ Синхронізація успішна! Файл prom.xml створено та оновлено.";
?>