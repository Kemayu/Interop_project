<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="html" encoding="UTF-8" indent="yes"/>
    
    <xsl:template match="/">
        <div class="meteo-container">
            <h2>Météo du jour</h2>
            <div class="meteo-periods">
                <xsl:apply-templates select="//period[@type='morning' or @type='afternoon' or @type='evening']"/>
            </div>
            
            <!-- Résumé général de la journée -->
            <div class="meteo-summary">
                <xsl:call-template name="weatherSummary"/>
            </div>
        </div>
    </xsl:template>
    
    <!-- Template pour chaque période de la journée -->
    <xsl:template match="period">
        <div class="period">
            <h3>
                <xsl:choose>
                    <xsl:when test="@type='morning'">Matin</xsl:when>
                    <xsl:when test="@type='afternoon'">Après-midi</xsl:when>
                    <xsl:when test="@type='evening'">Soir</xsl:when>
                </xsl:choose>
            </h3>
            
            <!-- Température -->
            <div class="weather-info">
                <span class="label">Température:</span>
                <xsl:call-template name="temperatureIcon">
                    <xsl:with-param name="temp" select="temperature"/>
                </xsl:call-template>
                <span class="value"><xsl:value-of select="temperature"/>°C</span>
            </div>
            
            <!-- Précipitations -->
            <xsl:if test="precipitation">
                <div class="weather-info">
                    <span class="label">Précipitations:</span>
                    <xsl:call-template name="precipitationIcon">
                        <xsl:with-param name="type" select="precipitation/@type"/>
                        <xsl:with-param name="intensity" select="precipitation"/>
                    </xsl:call-template>
                    <span class="value"><xsl:value-of select="precipitation"/> mm</span>
                </div>
            </xsl:if>
            
            <!-- Vent -->
            <xsl:if test="wind">
                <div class="weather-info">
                    <span class="label">Vent:</span>
                    <xsl:call-template name="windIcon">
                        <xsl:with-param name="speed" select="wind"/>
                    </xsl:call-template>
                    <span class="value"><xsl:value-of select="wind"/> km/h</span>
                </div>
            </xsl:if>
            
            <!-- Conditions générales -->
            <xsl:if test="condition">
                <div class="weather-info">
                    <span class="label">Conditions:</span>
                    <span class="value"><xsl:value-of select="condition"/></span>
                </div>
            </xsl:if>
        </div>
    </xsl:template>
    
    <!-- Template pour l'icône de température -->
    <xsl:template name="temperatureIcon">
        <xsl:param name="temp"/>
        <span class="weather-icon">
            <xsl:choose>
                <xsl:when test="$temp &lt; 0">Très froid</xsl:when>
                <xsl:when test="$temp &lt; 10">Froid</xsl:when>
                <xsl:when test="$temp &lt; 20">Doux</xsl:when>
                <xsl:when test="$temp &lt; 30">Chaud</xsl:when>
                <xsl:otherwise>Très chaud</xsl:otherwise>
            </xsl:choose>
        </span>
    </xsl:template>
    
    <!-- Template pour l'icône de précipitations -->
    <xsl:template name="precipitationIcon">
        <xsl:param name="type"/>
        <xsl:param name="intensity"/>
        <span class="weather-icon">
            <xsl:choose>
                <xsl:when test="$type='snow' or contains($type, 'neige')">Neige</xsl:when>
                <xsl:when test="$intensity &gt; 10">Pluie forte</xsl:when>
                <xsl:when test="$intensity &gt; 5">Pluie modérée</xsl:when>
                <xsl:when test="$intensity &gt; 0">Pluie légère</xsl:when>
                <xsl:otherwise>Nuageux</xsl:otherwise>
            </xsl:choose>
        </span>
    </xsl:template>
    
    <!-- Template pour l'icône de vent -->
    <xsl:template name="windIcon">
        <xsl:param name="speed"/>
        <span class="weather-icon">
            <xsl:choose>
                <xsl:when test="$speed &gt; 50">Vent violent</xsl:when>
                <xsl:when test="$speed &gt; 30">Vent fort</xsl:when>
                <xsl:when test="$speed &gt; 15">Vent modéré</xsl:when>
                <xsl:otherwise>Brise légère</xsl:otherwise>
            </xsl:choose>
        </span>
    </xsl:template>
    
    <!-- Template pour le résumé général -->
    <xsl:template name="weatherSummary">
        <h3>Résumé de la journée</h3>
        <div class="summary-alerts">
            <!-- Alerte froid -->
            <xsl:if test="//temperature[. &lt; 5]">
                <div class="alert alert-cold">
                    Attention: Températures froides aujourd'hui (min <xsl:value-of select="//temperature[not(. &gt; //temperature)][1]"/>°C)
                </div>
            </xsl:if>
            
            <!-- Alerte pluie -->
            <xsl:if test="//precipitation[. &gt; 5]">
                <div class="alert alert-rain">
                    Attention: Pluie prévue aujourd'hui (jusqu'à <xsl:value-of select="//precipitation[not(. &lt; //precipitation)][1]"/> mm)
                </div>
            </xsl:if>
            
            <!-- Alerte neige -->
            <xsl:if test="//precipitation[@type='snow' or contains(@type, 'neige')]">
                <div class="alert alert-snow">
                 Attention: Chutes de neige prévues aujourd'hui
                </div>
            </xsl:if>
            
            <!-- Alerte vent -->
            <xsl:if test="//wind[. &gt; 30]">
                <div class="alert alert-wind">
                    Attention: Vent fort aujourd'hui (jusqu'à <xsl:value-of select="//wind[not(. &lt; //wind)][1]"/> km/h)
                </div>
            </xsl:if>
            
            <!-- Beau temps -->
            <xsl:if test="not(//temperature[. &lt; 5]) and not(//precipitation[. &gt; 5]) and not(//wind[. &gt; 30])">
                <div class="alert alert-good">
                    Conditions favorables pour prendre la voiture aujourd'hui
                </div>
            </xsl:if>
        </div>
    </xsl:template>
</xsl:stylesheet>
