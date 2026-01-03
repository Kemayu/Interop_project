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

// Géocoder une adresse avec Nominatim
function geocodeAddress($address, &$api_urls) {
    global $context;
    
    $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($address) . "&format=json&limit=1";
    $api_urls['geocodage'] = $url;
    
    try {
        $json_string = @file_get_contents($url, false, $context);
        if ($json_string !== false) {
            $data = json_decode($json_string, true);
            if (!empty($data)) {
                return array(
                    'lat' => $data[0]['lat'],
                    'lon' => $data[0]['lon'],
                    'display_name' => $data[0]['display_name']
                );
            }
        }
    } catch (Exception $e) {
    }
    
    return null;
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

// Récupérer les aires de covoiturage depuis BNLC
function getTrafficData(&$api_urls) {
    global $context;
    
    $url = "https://transport.data.gouv.fr/api/datasets/5d6eaffc8b4c417cdc452ac3";
    $api_urls['circulation'] = $url;
    $api_urls['circulation_desc'] = "Base Nationale des Lieux de Covoiturage (BNLC)";
    
    try {
        $csv_url = "https://transport.data.gouv.fr/resources/81372/download";
        $csv_data = file_get_contents($csv_url, false, $context);
        
        if ($csv_data === false) {
            throw new Exception("Erreur téléchargement");
        }
        
        $lines = explode("\n", $csv_data);
        $carpools = array();
        
        for ($i = 1; $i < count($lines) && count($carpools) < 8; $i++) {
            $line = str_getcsv($lines[$i]);
            if (count($line) > 10 && isset($line[18]) && strpos($line[18], '54') === 0) {
                $carpools[] = array(
                    'geometry' => array('coordinates' => array(
                        floatval($line[9]),
                        floatval($line[10])
                    )),
                    'fields' => array(
                        'libelle_evenement' => 'Aire: ' . ($line[2] ?: 'Covoiturage'),
                        'date_debut' => $line[3] ?: 'Non spécifié',
                        'date_fin' => 'Commune: ' . ($line[4] ?: 'Meurthe-et-Moselle')
                    )
                );
            }
        }
        
        return $carpools ?: array(
            array(
                'geometry' => array('coordinates' => array(6.16935, 48.74752)),
                'fields' => array(
                    'libelle_evenement' => 'Aire de covoiturage',
                    'date_debut' => 'Rue du Téméraire',
                    'date_fin' => 'Bouxières-aux-Dames'
                )
            )
        );
        
    } catch (Exception $e) {
        return array(
            array(
                'geometry' => array('coordinates' => array(6.16935, 48.74752)),
                'fields' => array(
                    'libelle_evenement' => 'Aire de covoiturage',
                    'date_debut' => 'Rue du Téméraire',
                    'date_fin' => 'Bouxières-aux-Dames'
                )
            )
        );
    }
}

// Récupérer données SRAS Maxeville depuis SUM'Eau
function getSRASData(&$api_urls) {
    global $context;
    
    $indicators_url = "https://www.data.gouv.fr/fr/datasets/r/2963ccb5-344d-4978-bdd3-08aaf9efe514";
    $api_urls['sras'] = "https://www.data.gouv.fr/fr/datasets/surveillance-du-sars-cov-2-dans-les-eaux-usees-sumeau/";
    $api_urls['sras_desc'] = "SUM'Eau - SRAS-CoV-2 eaux usées Maxeville";
    
    try {
        $csv_data = file_get_contents($indicators_url, false, $context);
        if ($csv_data === false) {
            throw new Exception("Erreur téléchargement");
        }
        
        $lines = explode("\n", $csv_data);
        if (count($lines) < 2) {
            throw new Exception("CSV vide");
        }
        
        $header = str_getcsv($lines[0], ';');
        $maxeville_col = array_search('MAXEVILLE', $header);
        
        if ($maxeville_col === false) {
            throw new Exception("Station MAXEVILLE non trouvée");
        }
        
        $weekly_data = array();
        $count = 0;
        
        for ($i = count($lines) - 1; $i >= 1 && $count < 7; $i--) {
            $row = str_getcsv($lines[$i], ';');
            
            if (count($row) > $maxeville_col && !empty($row[0])) {
                $week = $row[0];
                $value = str_replace(',', '.', $row[$maxeville_col]);
                
                preg_match('/(\d{4})[-]?[SW](\d+)/', $week, $matches);
                if (count($matches) >= 3) {
                    $year = intval($matches[1]);
                    $week_num = intval($matches[2]);
                    $date = date('Y-m-d', strtotime("{$year}W{$week_num}1"));
                    
                    if ($value !== 'NA' && is_numeric($value) && floatval($value) > 0) {
                        $weekly_data[] = array(
                            'fields' => array(
                                'date_prelevement' => $date,
                                'concentration' => floatval($value),
                                'commune' => 'Maxeville (Nancy)'
                            )
                        );
                        $count++;
                    }
                }
            }
        }
        
        return array_reverse($weekly_data);
        
    } catch (Exception $e) {
        return array(
            array('fields' => array('date_prelevement' => date('Y-m-d'), 'concentration' => 2.5, 'commune' => 'Maxeville'))
        );
    }
}

// Qualité de l'air Open-Meteo
function getAirQuality($lat, $lon, &$api_urls) {
    global $context;
    
    $url = "https://air-quality-api.open-meteo.com/v1/air-quality?latitude={$lat}&longitude={$lon}&current=pm10,pm2_5,european_aqi";
    $api_urls['qualite_air'] = $url;
    
    try {
        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Exception("Erreur API");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['current'])) {
            throw new Exception("Format invalide");
        }
        
        $current = $data['current'];
        $aqi = $current['european_aqi'] ?? 40;
        $pm25 = $current['pm2_5'] ?? 10;
        $pm10 = $current['pm10'] ?? 20;
        
        $quality = 'Bon';
        if ($aqi > 20 && $aqi <= 40) $quality = 'Correct';
        if ($aqi > 40 && $aqi <= 60) $quality = 'Moyen';
        if ($aqi > 60 && $aqi <= 80) $quality = 'Dégradé';
        if ($aqi > 80 && $aqi <= 100) $quality = 'Mauvais';
        if ($aqi > 100) $quality = 'Très mauvais';
        
        return array(
            'aqi' => intval($aqi),
            'quality' => $quality,
            'pm25' => round($pm25, 1),
            'pm10' => round($pm10, 1),
            'date' => date('Y-m-d H:i'),
            'source' => 'Open-Meteo Air Quality API'
        );
        
    } catch (Exception $e) {
        return array(
            'aqi' => 35,
            'quality' => 'Correct',
            'pm25' => 8,
            'pm10' => 15,
            'date' => date('Y-m-d H:i'),
            'source' => 'Données par défaut'
        );
    }
}

// Récupération des données
$client_ip = getClientIP();
$location = geolocateIP($client_ip, $api_urls);

// Si pas à Nancy, récupérer coordonnées IUT Charlemagne
if (stripos($location['city'], 'Nancy') === false) {
    $iut_coords = geocodeAddress("IUT Charlemagne, 2 ter Boulevard Charlemagne, 54000 Nancy", $api_urls);
    if ($iut_coords) {
        $location['lat'] = $iut_coords['lat'];
        $location['lon'] = $iut_coords['lon'];
        $location['city'] = 'Nancy';
    }
}

$weather_xml = getWeatherData($location['lat'], $location['lon'], $api_urls);
$weather_html = $weather_xml ? transformWeatherXML($weather_xml) : null;
$traffic_data = getTrafficData($api_urls);
$sras_data = getSRASData($api_urls);
$air_quality = getAirQuality($location['lat'], $location['lon'], $api_urls);
$extra_location = geocodeAddress("Gare de Nancy-Ville, France", $api_urls);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atmosphere - Aide à la décision</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
</head>
<body>
    <header>
        <h1>Atmosphere</h1>
        <p class="subtitle">Aide à la décision de déplacement - Nancy</p>
    </header>

    <div class="container">
        <!-- PARTIE 1: MÉTÉO -->
        <section>
            <h2>Partie 1: Conditions météorologiques</h2>
            <?php echo $weather_html; ?>
        </section>

        <!-- PARTIE 2: CIRCULATION -->
        <section>
            <h2>Partie 2: Aires de covoiturage Meurthe-et-Moselle</h2>
            <div id="map" style="height: 500px; width: 100%;"></div>
            <div class="traffic-info">
                <p><strong>Nombre d'aires:</strong> <?php echo count($traffic_data); ?></p>
                <p style="font-size: 0.9em; color: #666;">Source: BNLC - transport.data.gouv.fr</p>
            </div>
        </section>

        <!-- PARTIE 3: SRAS EAUX USÉES -->
        <section>
            <h2>Partie 3: SRAS-CoV-2 eaux usées - Maxeville (Nancy)</h2>
            <p class="info-text">Données hebdomadaires SUM'Eau - Santé Publique France</p>
            <canvas id="srasChart" width="400" height="200"></canvas>
            <div class="sras-info">
                <?php if (!empty($sras_data)): ?>
                    <p><strong>Dernière semaine:</strong> <?php echo htmlspecialchars($sras_data[0]['fields']['date_prelevement'] ?? 'N/A'); ?></p>
                    <p><strong>Indicateur:</strong> <?php echo number_format($sras_data[0]['fields']['concentration'] ?? 0, 2); ?></p>
                    <p><strong>Station:</strong> <?php echo htmlspecialchars($sras_data[0]['fields']['commune'] ?? 'Maxeville'); ?></p>
                <?php endif; ?>
            </div>
        </section>

        <!-- QUALITÉ DE L'AIR -->
        <section>
            <h2>Qualité de l'air du jour</h2>
            <div class="air-quality-card">
                <div class="aqi-value">
                    <span class="aqi-number"><?php echo $air_quality['aqi']; ?></span>
                    <span class="aqi-label">IQA Européen</span>
                </div>
                <p class="aqi-description">Qualité: <strong><?php echo $air_quality['quality']; ?></strong></p>
                <p class="aqi-detail">
                    PM2.5: <?php echo $air_quality['pm25']; ?> µg/m³ | 
                    PM10: <?php echo $air_quality['pm10']; ?> µg/m³
                </p>
                <p class="aqi-date"><?php echo $air_quality['date']; ?></p>
            </div>
        </section>

        <!-- APIs utilisées -->
        <section>
            <h2>APIs utilisées</h2>
            <ul>
                <?php foreach ($api_urls as $name => $url): 
                    if (strpos($name, '_desc') !== false) continue;
                ?>
                    <li><strong><?php echo ucfirst($name); ?>:</strong> <a href="<?php echo htmlspecialchars($url); ?>" target="_blank"><?php echo htmlspecialchars($url); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- Dépôt Git -->
        <section>
            <h2>Dépôt Git</h2>
            <p><a href="https://github.com/Kemayu/Interop_project" target="_blank">https://github.com/Kemayu/Interop_project</a></p>
        </section>
    </div>

    <footer>
        <p>Projet DWM - IUT Charlemagne Nancy</p>
    </footer>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Carte Leaflet
        const map = L.map('map').setView([<?php echo $location['lat']; ?>, <?php echo $location['lon']; ?>], 11);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);
        
        // Position client
        L.marker([<?php echo $location['lat']; ?>, <?php echo $location['lon']; ?>])
            .addTo(map)
            .bindPopup('<b>Votre position</b><br><?php echo htmlspecialchars($location['city']); ?>');
        
        <?php if ($extra_location): ?>
        // Gare de Nancy
        L.marker([<?php echo $extra_location['lat']; ?>, <?php echo $extra_location['lon']; ?>])
            .addTo(map)
            .bindPopup('<b>Gare de Nancy-Ville</b>');
        <?php endif; ?>
        
        // Aires de covoiturage
        const trafficData = <?php echo json_encode($traffic_data); ?>;
        trafficData.forEach(function(item) {
            if (item.geometry && item.geometry.coordinates) {
                const lat = item.geometry.coordinates[1];
                const lon = item.geometry.coordinates[0];
                
                L.circleMarker([lat, lon], {
                    color: 'red',
                    fillColor: '#f03',
                    fillOpacity: 0.5,
                    radius: 8
                }).addTo(map).bindPopup(
                    '<b>' + item.fields.libelle_evenement + '</b><br>' +
                    item.fields.date_debut + '<br>' +
                    item.fields.date_fin
                );
            }
        });
        
        // Graphique SRAS
        const srasData = <?php echo json_encode($sras_data); ?>;
        const dates = [];
        const concentrations = [];
        
        srasData.forEach(function(item) {
            if (item.fields.date_prelevement && item.fields.concentration) {
                dates.push(item.fields.date_prelevement);
                concentrations.push(parseFloat(item.fields.concentration));
            }
        });
        
        if (dates.length > 0) {
            const ctx = document.getElementById('srasChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: dates,
                    datasets: [{
                        label: 'Indicateur SRAS-CoV-2 Maxeville',
                        data: concentrations,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Evolution SRAS-CoV-2 - Eaux usées Maxeville'
                        }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }
    </script>
</body>
</html>
