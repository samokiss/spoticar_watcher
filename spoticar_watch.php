<?php

$baseUrl = "https://www.spoticar.fr/voitures-occasion";

$query = http_build_query([
                              'page' => 1,
                              'c' => 'stellantis-club',
                              'sort' => 'price_asc',
                              'filters' => [
                                  ['pointofsale' => '0000111072'],
                                  ['model' => '5008'],
                              ],
                          ]);

$url = $baseUrl . '?' . $query;

$storageFile = __DIR__ . "/data/seen_ids.json";

// Configuration Telegram
$telegramToken = "8558999978:AAFqBIOTLZsHHmigCRSlzFFmHX_d00UVdH0";
$telegramChatId = "1566884041";

function fetchHtml(string $url): string
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => 'gzip, deflate, br, zstd',
        CURLOPT_HTTPHEADER => [
            'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'accept-language: en-US,en;q=0.9',
            'dnt: 1',
            'priority: u=0, i',
            'sec-ch-ua: "Not(A:Brand";v="8", "Chromium";v="144", "Google Chrome";v="144"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "macOS"',
            'sec-fetch-dest: document',
            'sec-fetch-mode: navigate',
            'sec-fetch-site: none',
            'sec-fetch-user: ?1',
            'sec-purpose: prefetch;prerender',
            'upgrade-insecure-requests: 1',
            'user-agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
        ],
        CURLOPT_COOKIE => '_psac_gdpr_stamp=1; _psac_gdpr_banner_id=0; _psac_gdpr_consent_given=1; opncl_performance=true; opncl_advertising=true; opncl_comfort=true; opncl_general=true; opncl_essential=true;',
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    curl_close($ch);

    if ($html === false) {
        throw new Exception("Erreur cURL: $err");
    }

    if ($httpCode >= 400) {
        throw new Exception("Erreur HTTP $httpCode");
    }

    return $html;
}

function loadSeen(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveSeen(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function absolutize(string $href): string
{
    if (str_starts_with($href, "http")) return $href;
    return "https://www.spoticar.fr" . (str_starts_with($href, "/") ? $href : "/" . $href);
}

function cleanText(string $s): string
{
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

function sendTelegram(string $token, string $chatId, string $message): bool
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'false',
        ]),
        CURLOPT_TIMEOUT => 10,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/**
 * Extrait les infos depuis <div class="vehicle-card-content"> ... </div>
 * en gardant l'id à partir du wrapper vehicle-card (data-vo-id)
 */
function parseVehicles(string $html): array
{
    $vehicles = [];

    // 1) On isole chaque carte (vehicle-card) pour récupérer data-vo-id + le bloc content
    preg_match_all(
        '/<div class="vehicle-card\b[^>]*data-vo-id="(\d+)"[\s\S]*?<div class="vehicle-card-content">([\s\S]*?)<div class="vehicle-card-footer">/u',
        $html,
        $cards,
        PREG_SET_ORDER
    );

    foreach ($cards as $card) {
        $id = $card[1];
        $content = $card[2];

        // 2) URL de la fiche (a.vehicle-card-link) - href peut être avant ou après class
        $href = null;
        if (preg_match('/<a[^>]*class="vehicle-card-link"[^>]*>/u', $content, $m)) {
            $linkTag = $m[0];
            if (preg_match('/href="([^"]+)"/u', $linkTag, $m2)) {
                $href = $m2[1];
            }
        }
        if (!$href) continue;

        $url = absolutize($href);

        // 3) Titre (h3 + span version)
        $title = "Annonce Spoticar";
        if (preg_match('/<div class="vehicle-card-title">[\s\S]*?<h3>([\s\S]*?)<\/h3>/u', $content, $m)) {
            $title = cleanText($m[1]);
        }

        // 4) Tags (km, carburant, date, boite, etc.) => tous les <span class="tag">
        $tags = [];
        if (preg_match_all('/<span class="tag[^"]*">([\s\S]*?)<\/span>/u', $content, $m)) {
            foreach ($m[1] as $t) {
                $t = cleanText($t);
                if ($t !== '' && $t !== 'Niveau 1') { // tu peux garder Niveau 1 si tu veux
                    $tags[] = $t;
                }
            }
        }

        // 5) Prix public (new-public-price) + prix remisé (dernier montant en €)
        $publicPrice = null;
        if (preg_match('/<div class="new-public-price">\s*([\d\s]+€)\s*<\/div>/u', $content, $m)) {
            $publicPrice = cleanText($m[1]);
        }

        $price = null;
        if (preg_match_all('/\d[\d\s]*\s?€/u', $content, $m) && !empty($m[0])) {
            $price = trim(end($m[0])); // souvent le prix remisé
        }

        $vehicles[] = [
            "id" => $id,
            "title" => $title,
            "url" => $url,
            "tags" => $tags,                 // ex: ["10 067 km","Essence","05-2025","Automatique","SOH 99"]
            "public_price" => $publicPrice,  // ex: "32 977 €"
            "price" => $price,               // ex: "28 277 €"
        ];
    }

    return $vehicles;
}


// ---- MAIN ----
try {
    echo "URL appelée : $url\n\n";

    $html = fetchHtml($url);
    $vehicles = parseVehicles($html);

    $seen = loadSeen($storageFile);
    $new = [];
    $priceChanges = [];

    foreach ($vehicles as $v) {
        if (!isset($seen[$v["id"]])) {
            // Nouvelle annonce
            $new[] = $v;
            $seen[$v["id"]] = [
                "first_seen" => date("c"),
                "title" => $v["title"],
                "price" => $v["price"],
                "url" => $v["url"],
                "price_history" => [
                    [
                        "price" => $v["price"],
                        "date" => date("c"),
                    ],
                ],
            ];
        } else {
            // Annonce déjà vue - vérifier changement de prix
            $oldPrice = $seen[$v["id"]]["price"];
            $newPrice = $v["price"];

            if ($oldPrice !== $newPrice && $newPrice !== null) {
                $priceChanges[] = [
                    "id" => $v["id"],
                    "title" => $v["title"],
                    "url" => $v["url"],
                    "old_price" => $oldPrice,
                    "new_price" => $newPrice,
                    "tags" => $v["tags"] ?? [],
                ];

                // Mettre à jour le prix et l'historique
                $seen[$v["id"]]["price"] = $newPrice;
                if (!isset($seen[$v["id"]]["price_history"])) {
                    $seen[$v["id"]]["price_history"] = [];
                }
                $seen[$v["id"]]["price_history"][] = [
                    "price" => $newPrice,
                    "date" => date("c"),
                ];
            }
        }
    }

    saveSeen($storageFile, $seen);

    if (count($new) === 0 && count($priceChanges) === 0) {
        echo "RAS (" . date("c") . ")\n";
        exit;
    }

    // Notifications pour les nouvelles annonces
    if (count($new) > 0) {
        echo "🚗 NOUVELLES ANNONCES (" . count($new) . ")\n\n";

        foreach ($new as $v) {
            // Affichage console
            echo "• [" . $v["id"] . "] " . $v["title"];
            if ($v["price"]) {
                echo " — " . $v["price"];
            }
            echo "\n  " . $v["url"] . "\n\n";

            // Notification Telegram
            $msg = "🚗 <b>" . htmlspecialchars($v["title"]) . "</b>\n";
            if ($v["price"]) {
                $msg .= "💰 <b>" . $v["price"] . "</b>\n";
            }
            if (!empty($v["tags"])) {
                $msg .= "📍 " . implode(" • ", $v["tags"]) . "\n";
            }
            $msg .= "\n<a href=\"" . $v["url"] . "\">Voir l'annonce</a>";

            sendTelegram($telegramToken, $telegramChatId, $msg);
        }
    }

    // Notifications pour les changements de prix
    if (count($priceChanges) > 0) {
        echo "💰 CHANGEMENTS DE PRIX (" . count($priceChanges) . ")\n\n";

        foreach ($priceChanges as $v) {
            // Calcul de l'évolution
            $oldPriceNum = (int)preg_replace('/\D/', '', $v["old_price"] ?? "0");
            $newPriceNum = (int)preg_replace('/\D/', '', $v["new_price"] ?? "0");
            $diff = $newPriceNum - $oldPriceNum;
            $evolution = $diff < 0 ? "📉 baisse" : "📈 hausse";
            $diffFormatted = number_format(abs($diff), 0, ',', ' ') . " €";

            // Affichage console
            echo "• [" . $v["id"] . "] " . $v["title"];
            echo "\n  " . $v["old_price"] . " → " . $v["new_price"];
            echo " (" . $evolution . " de " . $diffFormatted . ")";
            echo "\n  " . $v["url"] . "\n\n";

            // Notification Telegram
            $msg = "💰 <b>Changement de prix!</b>\n";
            $msg .= "🚗 " . htmlspecialchars($v["title"]) . "\n\n";
            $msg .= "❌ Ancien: <s>" . $v["old_price"] . "</s>\n";
            $msg .= "✅ Nouveau: <b>" . $v["new_price"] . "</b>\n";
            $msg .= $evolution . " de <b>" . $diffFormatted . "</b>\n";
            if (!empty($v["tags"])) {
                $msg .= "\n📍 " . implode(" • ", $v["tags"]) . "\n";
            }
            $msg .= "\n<a href=\"" . $v["url"] . "\">Voir l'annonce</a>";

            sendTelegram($telegramToken, $telegramChatId, $msg);
        }
    }

} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
