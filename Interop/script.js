const API_URLS = {
    geo: 'http://ip-api.com/json/',
    weather: 'https://www.infoclimat.fr/public-api/gfs/xml?_ll=48.67103,6.15083&_auth=ARsDFFIsBCZRfFtsD3lSe1Q8ADUPeVRzBHgFZgtuAH1UMQNgUTNcPlU5VClSfVZkUn8AYVxmVW0Eb1I2WylSLgFgA25SNwRuUT1bPw83UnlUeAB9DzFUcwR4BWMLYwBhVCkDb1EzXCBVOFQoUmNWZlJnAH9cfFVsBGRSPVs1UjEBZwNkUjIEYVE6WyYPIFJjVGUAZg9mVD4EbwVhCzMAMFQzA2JRMlw5VThUKFJiVmtSZQBpXGtVbwRlUjVbKVIuARsDFFIsBCZRfFtsD3lSe1QyAD4PZA%3D%3D&_c=19f3aa7d766b6ba91191c8be71dd1ab2',
    pollution: "https://services3.arcgis.com/Is0UwT37raQYl9Jj/arcgis/rest/services/ind_grandest/FeatureServer/0/query?where=lib_zone='Nancy'&outFields=*&f=pjson",
    bikes_info: 'https://api.cyclocity.fr/contracts/nancy/gbfs/v2/station_information.json',
    bikes_status: 'https://api.cyclocity.fr/contracts/nancy/gbfs/v2/station_status.json'
};

let map;
let userPosition = { lat: 48.6921, lon: 6.1844 }; // Par défaut : Nancy
let currentConditions = {
    rain: 0,
    wind: 0,
    temp: 0,
    aqi: 0
};

document.addEventListener('DOMContentLoaded', init);

async function init() {
    try {
        await getUserLocation();
        initMap();
        await Promise.all([
            getWeatherData(),
            getPollutionData(),
            getBikeData()
        ]);
        updateDecision();
    } catch (error) {
        console.error("Erreur lors de l'initialisation :", error);
        alert("Une erreur est survenue lors du chargement des données. Vérifiez la console.");
    }
}

async function getUserLocation() {
    try {
        const response = await fetch(API_URLS.geo);
        if (!response.ok) throw new Error('Erreur IP-API');
        const data = await response.json();
        
        if (data.status === 'success') {
            userPosition.lat = data.lat;
            userPosition.lon = data.lon;
        } else {
            console.warn("Impossible de géolocaliser via IP, utilisation de la position par défaut (Nancy).");
        }
    } catch (error) {
        console.error("Erreur Géolocalisation :", error);
    }
}

function initMap() {
    map = L.map('map').setView([userPosition.lat, userPosition.lon], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    L.marker([userPosition.lat, userPosition.lon])
        .addTo(map)
        .bindPopup("<b>Vous êtes ici</b>")
        .openPopup();
}

async function getWeatherData() {
    const container = document.getElementById('weather-info');
    try {
        const response = await fetch(API_URLS.weather);
        const strXML = await response.text();
        
        const parser = new DOMParser();
        const xmlDoc = parser.parseFromString(strXML, "text/xml");

        const echeances = xmlDoc.getElementsByTagName('echeance');
        if (echeances.length === 0) throw new Error("Pas de données météo");

        // Trouver l'échéance la plus proche de maintenant
        const now = new Date();
        let current = echeances[0];
        let minDiff = Infinity;

        for (let i = 0; i < echeances.length; i++) {
            const echeance = echeances[i];
            const time = new Date(echeance.getAttribute('timestamp')).getTime();
            const diff = Math.abs(time - now.getTime());
            
            if (diff < minDiff) {
                minDiff = diff;
                current = echeance;
            }
        }

        const timestamp = current.getAttribute('timestamp');
        const datePrevision = timestamp ? new Date(timestamp).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : '';

        // Température en Kelvin -> Celsius
        const tempK = parseFloat(current.getElementsByTagName('temperature')[0].getElementsByTagName('level')[0].textContent);
        const tempC = (tempK - 273.15).toFixed(1);

        const wind = parseFloat(current.getElementsByTagName('vent_moyen')[0].getElementsByTagName('level')[0].textContent).toFixed(1);
        const rain = parseFloat(current.getElementsByTagName('pluie')[0].textContent).toFixed(1);

        container.innerHTML = `
            <div class="data-value">${tempC} °C</div>
            <div class="data-label">Vent: ${wind} km/h</div>
            <div class="data-label">Pluie ${rain} mm</div>
            <small>Prévision: ${datePrevision}</small>
        `;

        currentConditions.temp = parseFloat(tempC);
        currentConditions.wind = parseFloat(wind);
        currentConditions.rain = parseFloat(rain);

        return currentConditions;

    } catch (error) {
        container.innerHTML = "Erreur chargement météo";
        console.error("Erreur Météo (InfoClimat):", error);
        return null;
    }
}

async function getPollutionData() {
    const container = document.getElementById('pollution-info');
    try {
        const response = await fetch(API_URLS.pollution);
        const data = await response.json();

        if (!data.features || data.features.length === 0) {
            throw new Error("Pas de données pollution trouvées pour Nancy");
        }

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        data.features.sort((a, b) => a.attributes.date_ech - b.attributes.date_ech);

        let feature = data.features.find(f => {
            const d = new Date(f.attributes.date_ech);
            d.setHours(0, 0, 0, 0);
            return d.getTime() === today.getTime();
        });

        if (!feature) {
            feature = data.features.reduce((prev, curr) => {
                return (Math.abs(curr.attributes.date_ech - today.getTime()) < Math.abs(prev.attributes.date_ech - today.getTime()) ? curr : prev);
            });
        }

        const attributes = feature.attributes;
        
        const qualite = attributes.lib_qual || "Inconnue";
        const code = attributes.code_qual; 
        const dateMaj = new Date(attributes.date_ech).toLocaleDateString();

        let color = "gray";
      
        if (qualite.toLowerCase().includes("bon")) color = "#50f0e6"; 
        else if (qualite.toLowerCase().includes("moyen")) color = "#f0e641"; 
        else if (qualite.toLowerCase().includes("dégradé")) color = "#ff5050"; 
        else if (qualite.toLowerCase().includes("mauvais")) color = "#960032"; 

        container.innerHTML = `
            <div class="data-value" style="color:${color}">${qualite}</div>
            <div class="data-label">Indice ATMO Grand Est</div>
            <small>Date: ${dateMaj}</small>
        `;

        currentConditions.aqi = code ? code * 20 : (qualite === "Bon" ? 20 : 80); 
        
        return qualite;

    } catch (error) {
        container.innerHTML = "Erreur chargement pollution";
        console.error("Erreur Pollution (ATMO):", error);
        return null;
    }
}

async function getBikeData() {
    try {
        const [infoResponse, statusResponse] = await Promise.all([
            fetch(API_URLS.bikes_info),
            fetch(API_URLS.bikes_status)
        ]);

        const infoData = await infoResponse.json();
        const statusData = await statusResponse.json();

        if (!infoData.data || !infoData.data.stations || !statusData.data || !statusData.data.stations) {
            throw new Error("Format de données GBFS invalide");
        }

        const stationsInfo = infoData.data.stations;
        const stationsStatus = statusData.data.stations;

        const statusMap = new Map();
        stationsStatus.forEach(status => {
            statusMap.set(status.station_id, status);
        });

        const bikeIcon = L.icon({
            iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
            iconSize: [25, 41],
            iconAnchor: [12, 41],
            popupAnchor: [1, -34],
            shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
            shadowSize: [41, 41]
        });

        stationsInfo.forEach(station => {
            const status = statusMap.get(station.station_id);
            
            const availableBikes = status ? status.num_bikes_available : '?';
            const availableDocks = status ? status.num_docks_available : '?';
            const lastUpdate = status ? new Date(status.last_reported * 1000).toLocaleTimeString() : 'Inconnu';

            const popupContent = `
                <b>${station.name}</b><br>
                Vélos dispos : ${availableBikes}<br>
                Places libres : ${availableDocks}<br>
                <small>Mise à jour : ${lastUpdate}</small>
            `;

            L.marker([station.lat, station.lon], { icon: bikeIcon })
                .addTo(map)
                .bindPopup(popupContent);
        });

    } catch (error) {
        console.error("Erreur chargement vélos (GBFS) :", error);
    }
}

function updateDecision() {
    const decisionContainer = document.getElementById('decision-info');

    let message = "Conditions favorables";
    let cssClass = "decision-go";

    if (currentConditions.rain > 0) {
        message = "Il pleut, prenez le bus";
        cssClass = "decision-no";
    } else if (currentConditions.wind > 50) {
        message = "Vent fort, attention !";
        cssClass = "decision-no";
    } else if (currentConditions.aqi > 50) { 
        message = "Pollution élevée, évitez l'effort";
        cssClass = "decision-no";
    } else if (currentConditions.temp < 0) {
        message = "Il gèle, attention au verglas";
        cssClass = "decision-no";
    }

    decisionContainer.innerHTML = `<span class="${cssClass}">${message}</span>`;
}