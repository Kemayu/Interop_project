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

// Récupération des données
$client_ip = getClientIP();
$location = geolocateIP($client_ip, $api_urls);

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
