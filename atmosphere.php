<?php
// Projet Interopérabilité - Atmosphere
// Intégration de plusieurs APIs de données ouvertes

// Détection du proxy pour webetu
$use_proxy = (@fsockopen('www-cache', 3128, $errno, $errstr, 1) !== false);

if ($use_proxy) {
    $opts = array(
        'http' => array(
            'proxy' => 'tcp://www-cache:3128',
            'request_fulluri' => true,
            'timeout' => 10
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false
        )
    );
} else {
    $opts = array(
        'http' => array('timeout' => 10),
        'ssl' => array('verify_peer' => false, 'verify_peer_name' => false)
    );
}

$context = stream_context_create($opts);
stream_context_set_default($opts);

$api_urls = array();

// Récupère l'IP du client
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    } else {
        return '127.0.0.1';
    }
}

// Géolocalisation IP avec ip-api.com en XML
function geolocateIP($ip, &$api_urls) {
    global $context;
    
    // Essayer ip-api.com en XML
    $url = "http://ip-api.com/xml/{$ip}";
    $api_urls['geolocalisation'] = $url;
    
    try {
        $xml_string = @file_get_contents($url, false, $context);
        if ($xml_string !== false) {
            $xml = @simplexml_load_string($xml_string);
            if ($xml && $xml->status == 'success') {
                return array(
                    'lat' => (string)$xml->lat,
                    'lon' => (string)$xml->lon,
                    'city' => (string)$xml->city,
                    'region' => (string)$xml->regionName,
                    'country' => (string)$xml->country,
                    'zip' => (string)$xml->zip
                );
            }
        }
    } catch (Exception $e) {
    }
    
    // Fallback sur ipapi.co
    $url2 = "https://ipapi.co/{$ip}/xml/";
    try {
        $xml_string = @file_get_contents($url2, false, $context);
        if ($xml_string !== false) {
            $xml = @simplexml_load_string($xml_string);
            if ($xml && isset($xml->latitude)) {
                $api_urls['geolocalisation'] .= " (fallback: $url2)";
                return array(
                    'lat' => (string)$xml->latitude,
                    'lon' => (string)$xml->longitude,
                    'city' => (string)$xml->city,
                    'region' => (string)$xml->region,
                    'country' => (string)$xml->country_name,
                    'zip' => (string)$xml->postal
                );
            }
        }
    } catch (Exception $e) {
    }
    
    // Si aucune API ne fonctionne, fallback sur Nancy - IUT Charlemagne
    return array(
        'lat' => '48.6880466',
        'lon' => '6.1778966',
        'city' => 'Nancy',
        'region' => 'Grand Est',
        'country' => 'France',
        'zip' => '54000'
    );
}

// Récupérer les données météo avec Open-Meteo API
function getWeatherData($lat, $lon, &$api_urls) {
    global $context;
    
    // Utiliser Open-Meteo API (JSON) puis convertir en XML
    $url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&hourly=temperature_2m,precipitation,windspeed_10m&timezone=Europe/Paris&forecast_days=1";
    $api_urls['meteo'] = $url;
    
    try {
        $json_string = @file_get_contents($url, false, $context);
        if ($json_string !== false) {
            $data = json_decode($json_string, true);
            
            // Créer un XML depuis les données JSON
            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><weather></weather>');
            $xml->addChild('location', $data['latitude'] . ',' . $data['longitude']);
            $xml->addChild('date', date('Y-m-d'));
            
            // Extraire les données pour matin, midi, soir
            $times = array(
                'morning' => 8,   // 8h
                'afternoon' => 14, // 14h
                'evening' => 20    // 20h
            );
            
            foreach ($times as $period => $hour) {
                $periodNode = $xml->addChild('period');
                $periodNode->addAttribute('type', $period);
                $periodNode->addChild('temperature', round($data['hourly']['temperature_2m'][$hour], 1));
                $periodNode->addChild('precipitation', round($data['hourly']['precipitation'][$hour], 1));
                $periodNode->addChild('wind', round($data['hourly']['windspeed_10m'][$hour], 1));
                
                // Ajouter une condition textuelle
                $temp = $data['hourly']['temperature_2m'][$hour];
                $precip = $data['hourly']['precipitation'][$hour];
                $condition = "";
                if ($precip > 5) {
                    $condition = "Pluvieux";
                } elseif ($precip > 0) {
                    $condition = "Quelques averses";
                } elseif ($temp < 10) {
                    $condition = "Frais";
                } else {
                    $condition = "Dégagé";
                }
                $periodNode->addChild('condition', $condition);
            }
            
            return $xml->asXML();
        }
    } catch (Exception $e) {
    }
    
    return null;
}

// Transformer XML météo avec XSL
function transformWeatherXML($xmlString) {
    // Vérifier si l'extension XSL est disponible
    if (!class_exists('XSLTProcessor')) {
        return generateWeatherHTMLFallback($xmlString);
    }
    
    try {
        $xml = new DOMDocument();
        $xml->loadXML($xmlString);
        
        $xsl = new DOMDocument();
        $xsl->load('meteo.xsl');
        
        $proc = new XSLTProcessor();
        $proc->importStyleSheet($xsl);
        
        return $proc->transformToXML($xml);
    } catch (Exception $e) {
        return generateWeatherHTMLFallback($xmlString);
    }
}

// Générer HTML météo sans XSL (fallback)
function generateWeatherHTMLFallback($xmlString) {
    try {
        $xml = simplexml_load_string($xmlString);
        
        $html = '<div class="meteo-container">';
        $html .= '<h2>Météo du jour</h2>';
        $html .= '<div class="meteo-periods">';
        
        if (isset($xml->period)) {
            $periods_fr = array(
                'morning' => 'Matin',
                'afternoon' => 'Après-midi',
                'evening' => 'Soir'
            );
            
            foreach ($xml->period as $period) {
                $type = (string)$period['type'];
                $temp = (float)$period->temperature;
                $precip = (float)$period->precipitation;
                $wind = (float)$period->wind;
                $condition = (string)$period->condition;
                
                $html .= "<div class='period'>";
                $html .= "<h3>" . ($periods_fr[$type] ?? ucfirst($type)) . "</h3>";
                $html .= "<div class='weather-info'><span class='label'>Température:</span> <span class='value'>" . round($temp) . "°C</span></div>";
                $html .= "<div class='weather-info'><span class='label'>Précipitations:</span> <span class='value'>" . round($precip, 1) . " mm</span></div>";
                $html .= "<div class='weather-info'><span class='label'>Vent:</span> <span class='value'>" . round($wind) . " km/h</span></div>";
                $html .= "<div class='weather-info'><span class='label'>Conditions:</span> <span class='value'>$condition</span></div>";
                $html .= "</div>";
            }
        }
        
        $html .= '</div>';
        
        // Alertes
        $html .= '<div class="meteo-summary"><div class="summary-alerts">';
        $has_alert = false;
        if (isset($xml->period)) {
            foreach ($xml->period as $period) {
                $temp = (float)$period->temperature;
                $precip = (float)$period->precipitation;
                $wind = (float)$period->wind;
                
                if ($temp < 5 && !$has_alert) {
                    $html .= "<div class='alert alert-cold'>Températures basses prévues</div>";
                    $has_alert = true;
                }
                if ($precip > 5 && !$has_alert) {
                    $html .= "<div class='alert alert-rain'>Prévoyez un parapluie</div>";
                    $has_alert = true;
                }
                if ($wind > 40 && !$has_alert) {
                    $html .= "<div class='alert alert-wind'>Vent fort prévu</div>";
                    $has_alert = true;
                }
            }
        }
        if (!$has_alert) {
            $html .= "<div class='alert alert-good'>Conditions météo favorables</div>";
        }
        $html .= '</div></div>';
        
        $html .= '</div>';
        return $html;
    } catch (Exception $e) {
        return "<div class='meteo-container'><h2>Météo du jour</h2><p>Erreur lors du chargement des données météo.</p></div>";
    }
}

// Récupération des données
$client_ip = getClientIP();
$location = geolocateIP($client_ip, $api_urls);
$weather_xml = getWeatherData($location['lat'], $location['lon'], $api_urls);
$weather_html = $weather_xml ? transformWeatherXML($weather_xml) : null;

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atmosphere - Interopérabilité</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <h1>Atmosphere</h1>
        <p class="subtitle">Données environnementales de Nancy</p>
    </header>

    <div class="container">
        <!-- Section Localisation -->
        <section>
            <h2>Votre localisation</h2>
            <div class="location-info">
                <p><strong>Adresse IP :</strong> <?php echo htmlspecialchars($client_ip); ?></p>
                <p><strong>Ville :</strong> <?php echo htmlspecialchars($location['city']); ?></p>
                <p><strong>Région :</strong> <?php echo htmlspecialchars($location['region']); ?></p>
                <p><strong>Pays :</strong> <?php echo htmlspecialchars($location['country']); ?></p>
                <p><strong>Code postal :</strong> <?php echo htmlspecialchars($location['zip']); ?></p>
                <p><strong>Coordonnées GPS :</strong> <?php echo htmlspecialchars($location['lat']); ?>, <?php echo htmlspecialchars($location['lon']); ?></p>
            </div>
        </section>

        <!-- Section Météo -->
        <?php if ($weather_html): ?>
        <section>
            <?php echo $weather_html; ?>
        </section>
        <?php endif; ?>

        <!-- APIs utilisées -->
        <section>
            <h2>APIs utilisées</h2>
            <ul>
                <?php foreach ($api_urls as $service => $url): ?>
                    <li><strong><?php echo ucfirst($service); ?> :</strong> <a href="<?php echo htmlspecialchars($url); ?>" target="_blank"><?php echo htmlspecialchars($url); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <footer>
        <p>Projet DWM - IUT Charlemagne Nancy</p>
        <p><a href="https://github.com/Kemayu/Interop_project" target="_blank">GitHub Repository</a></p>
    </footer>
</body>
</html>
