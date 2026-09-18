<?php

declare(strict_types=1);


/*
 * =============================================================================
 * SULZBANN WETTER VISUALISIERUNG
 * =============================================================================
 *
 * GitHub enthält NUR diesen Modul-Code.
 *
 * Sämtliche Wetterdaten bleiben lokal in IP-Symcon.
 *
 * Datenquellen:
 *
 * MeteoSchweiz:
 *   Ident: MeteoSchweizForecast
 *
 * Solcast:
 *   Parent standardmässig #16397
 *
 * Ausgabe:
 *
 *   Native Kachel der Tile-Visualisierung.
 *   Eine vorhandene HTMLBox wird während der Umstellung weiter aktualisiert.
 *
 * WICHTIG:
 *
 * - kein WebHook
 * - keine GitHub Pages
 * - keine JSON-Übertragung
 * - keine Wetterdaten werden an GitHub gesendet
 * - kein Schreiben an Wärmepumpe / KNX
 *
 * =============================================================================
 */


class SulzbannWetterVisualisierung extends IPSModule
{

    /*
     * =========================================================================
     * CREATE
     * =========================================================================
     */

    public function Create(): void
    {
        parent::Create();


        /*
         * MeteoSchweiz Root.
         *
         * 0 = automatisch anhand Ident
         * MeteoSchweizForecast suchen.
         */
        $this->RegisterPropertyInteger(
            'MeteoRootID',
            0
        );


        /*
         * Technik > Strom > Wettervorhersage Solcast
         */
        $this->RegisterPropertyInteger(
            'SolcastParentID',
            16397
        );


        /*
         * Nur prüfen, ob sich lokale Werte geändert haben.
         *
         * 300 Sekunden = 5 Minuten.
         */
        $this->RegisterPropertyInteger(
            'RefreshSeconds',
            300
        );


        /*
         * Gefundene Meteo Root-ID lokal merken.
         */
        $this->RegisterAttributeInteger(
            'DetectedMeteoRootID',
            0
        );


        /* Native Kachel der Tile-Visualisierung. */
        $this->SetVisualizationType(1);


        /*
         * Timer.
         */
        $this->RegisterTimer(
            'UpdateTimer',
            300000,
            'SBWV_Update($_IPS["TARGET"]);'
        );
    }


    /*
     * =========================================================================
     * APPLY CHANGES
     * =========================================================================
     */

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);


        /*
         * Automatisch gefundene Root-ID bei Änderung neu bestimmen.
         */
        $this->WriteAttributeInteger(
            'DetectedMeteoRootID',
            0
        );


        $seconds =
            max(
                60,
                $this->ReadPropertyInteger(
                    'RefreshSeconds'
                )
            );


        $this->SetTimerInterval(
            'UpdateTimer',
            $seconds * 1000
        );


        /*
         * Direkt einmal aktualisieren.
         */
        $this->Update();
    }


    /*
     * =========================================================================
     * TILE
     * =========================================================================
     */

    public function GetVisualizationTile(): string
    {
        $meteoRootId = $this->GetMeteoRootID();

        if ($meteoRootId <= 0 || !IPS_ObjectExists($meteoRootId)) {
            return $this->BuildErrorHtml('MeteoSchweiz Prognose wurde nicht gefunden.');
        }

        return $this->BuildHtml(
            $this->ReadMeteoData($meteoRootId),
            $this->ReadSolcastData()
        );
    }


    /*
     * =========================================================================
     * ÖFFENTLICHE UPDATE FUNKTION
     * =========================================================================
     */

    public function Update(): void
    {
        $meteoRootId =
            $this->GetMeteoRootID();


        if (
            $meteoRootId <= 0
            ||
            !IPS_ObjectExists(
                $meteoRootId
            )
        ) {

            $this->SetStatus(
                201
            );


            $this->WriteHtmlIfChanged(
                $this->BuildErrorHtml(
                    'MeteoSchweiz Prognose wurde nicht gefunden.'
                )
            );


            return;
        }


        $this->SetStatus(
            102
        );


        $meteo =
            $this->ReadMeteoData(
                $meteoRootId
            );


        $solcast =
            $this->ReadSolcastData();


        $html =
            $this->BuildHtml(
                $meteo,
                $solcast
            );


        /*
         * Nur schreiben, wenn sich der Inhalt geändert hat.
         *
         * Dadurch wird die HTMLBox nicht alle fünf Minuten
         * unnötig neu aufgebaut.
         */
        $this->WriteHtmlIfChanged(
            $html
        );
    }


    /*
     * =========================================================================
     * METEO ROOT SUCHEN
     * =========================================================================
     */

    private function GetMeteoRootID(): int
    {
        /*
         * Manuell eingetragene ID hat Priorität.
         */

        $configured =
            $this->ReadPropertyInteger(
                'MeteoRootID'
            );


        if (
            $configured > 0
            &&
            IPS_ObjectExists(
                $configured
            )
        ) {

            return $configured;
        }


        /*
         * Bereits automatisch gefunden?
         */

        $cached =
            $this->ReadAttributeInteger(
                'DetectedMeteoRootID'
            );


        if (
            $cached > 0
            &&
            IPS_ObjectExists(
                $cached
            )
        ) {

            return $cached;
        }


        /*
         * Gesamten Baum einmalig durchsuchen.
         */

        $found =
            $this->FindObjectByIdentRecursive(
                0,
                'MeteoSchweizForecast'
            );


        if ($found !== false) {

            $this->WriteAttributeInteger(
                'DetectedMeteoRootID',
                $found
            );


            return $found;
        }


        return 0;
    }


    /*
     * =========================================================================
     * REKURSIVE IDENT SUCHE
     * =========================================================================
     */

    private function FindObjectByIdentRecursive(
        int $parentId,
        string $ident
    ): int|false {

        foreach (
            IPS_GetChildrenIDs(
                $parentId
            )
            as $childId
        ) {

            $object =
                IPS_GetObject(
                    $childId
                );


            if (
                isset(
                    $object['ObjectIdent']
                )
                &&
                $object['ObjectIdent']
                ===
                $ident
            ) {

                return $childId;
            }


            $found =
                $this->FindObjectByIdentRecursive(
                    $childId,
                    $ident
                );


            if ($found !== false) {

                return $found;
            }
        }


        return false;
    }


    /*
     * =========================================================================
     * CHILD BY IDENT
     * =========================================================================
     */

    private function ChildByIdent(
        int $parentId,
        string $ident
    ): int|false {

        return @IPS_GetObjectIDByIdent(
            $ident,
            $parentId
        );
    }


    /*
     * =========================================================================
     * VARIABLE LESEN
     * =========================================================================
     */

    private function ReadValueSafe(
        int|false $variableId,
        mixed $default = null
    ): mixed {

        if ($variableId === false) {

            return $default;
        }


        if (
            !IPS_ObjectExists(
                $variableId
            )
        ) {

            return $default;
        }


        $object =
            IPS_GetObject(
                $variableId
            );


        if (
            (int) $object['ObjectType']
            !==
            2
        ) {

            return $default;
        }


        try {

            return GetValue(
                $variableId
            );

        } catch (Throwable $e) {

            return $default;
        }
    }


    /*
     * =========================================================================
     * FLOAT LESEN
     * =========================================================================
     */

    private function ReadFloat(
        int|false $variableId
    ): ?float {

        $value =
            $this->ReadValueSafe(
                $variableId
            );


        if (
            $value === null
            ||
            !is_numeric(
                $value
            )
        ) {

            return null;
        }


        return (float) $value;
    }


    /*
     * =========================================================================
     * INTEGER LESEN
     * =========================================================================
     */

    private function ReadInteger(
        int|false $variableId
    ): ?int {

        $value =
            $this->ReadValueSafe(
                $variableId
            );


        if (
            $value === null
            ||
            !is_numeric(
                $value
            )
        ) {

            return null;
        }


        return (int) $value;
    }


    /*
     * =========================================================================
     * STRING LESEN
     * =========================================================================
     */

    private function ReadString(
        int|false $variableId
    ): ?string {

        $value =
            $this->ReadValueSafe(
                $variableId
            );


        if ($value === null) {

            return null;
        }


        return (string) $value;
    }


    /*
     * =========================================================================
     * METEOSCHWEIZ LESEN
     * =========================================================================
     */

    private function ReadMeteoData(
        int $rootId
    ): array {

        $result = [

            'status' =>
                $this->ReadString(
                    $this->ChildByIdent(
                        $rootId,
                        'Status'
                    )
                ),

            'forecastRun' =>
                $this->ReadString(
                    $this->ChildByIdent(
                        $rootId,
                        'ForecastRun'
                    )
                ),

            'dataAge' =>
                $this->ReadFloat(
                    $this->ChildByIdent(
                        $rootId,
                        'DataAge'
                    )
                ),

            'validDays' =>
                $this->ReadInteger(
                    $this->ChildByIdent(
                        $rootId,
                        'ValidDays'
                    )
                ),

            'days' =>
                []

        ];


        /*
         * Tag 1 bis Tag 9.
         */

        for (
            $dayNumber = 1;
            $dayNumber <= 9;
            $dayNumber++
        ) {

            $dayCategory =
                $this->ChildByIdent(
                    $rootId,
                    'Day'
                    .
                    $dayNumber
                );


            if ($dayCategory === false) {

                continue;
            }


            $result['days'][] = [

                'index' =>
                    $dayNumber,

                'date' =>
                    $this->ReadString(
                        $this->ChildByIdent(
                            $dayCategory,
                            'Date'
                        )
                    ),

                'tempMin' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'TempMin'
                        )
                    ),

                'tempMax' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'TempMax'
                        )
                    ),

                'tempMean' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'TempMean'
                        )
                    ),

                'solar' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'SolarEnergy'
                        )
                    ),

                'sunHours' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'SunHours'
                        )
                    ),

                'rain' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'Rain'
                        )
                    ),

                'wind' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'WindMean'
                        )
                    ),

                'cloudLow' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'CloudLow'
                        )
                    ),

                'cloudMid' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'CloudMid'
                        )
                    ),

                'cloudHigh' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $dayCategory,
                            'CloudHigh'
                        )
                    )

            ];
        }


        return $result;
    }


    /*
     * =========================================================================
     * SOLCAST LESEN
     * =========================================================================
     */

    private function ReadSolcastData(): array
    {
        $parentId =
            $this->ReadPropertyInteger(
                'SolcastParentID'
            );


        if (
            $parentId <= 0
            ||
            !IPS_ObjectExists(
                $parentId
            )
        ) {

            return [
                'available' => false
            ];
        }


        return [

            'available' =>
                true,

            'next24' => [

                'p10' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastEnergy024P10'
                        )
                    ),

                'p50' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastEnergy024P50'
                        )
                    ),

                'p90' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastEnergy024P90'
                        )
                    ),

                'peak' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastPeak024'
                        )
                    ),

                'confidence' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastConfidence024'
                        )
                    )

            ],

            'next48' => [

                'p10' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastEnergy2448P10'
                        )
                    ),

                'p50' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastEnergy2448P50'
                        )
                    ),

                'p90' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastEnergy2448P90'
                        )
                    ),

                'peak' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastPeak2448'
                        )
                    ),

                'confidence' =>
                    $this->ReadFloat(
                        $this->ChildByIdent(
                            $parentId,
                            'SolcastConfidence2448'
                        )
                    )

            ]

        ];
    }


    /*
     * =========================================================================
     * FORMATIERUNG
     * =========================================================================
     */

    private function F(
        ?float $value,
        int $digits = 1
    ): string {

        if ($value === null) {

            return '–';
        }


        return number_format(
            $value,
            $digits,
            '.',
            ''
        );
    }


    /*
     * =========================================================================
     * WOCHENTAG
     * =========================================================================
     */

    private function DayName(
        int $index,
        ?string $date
    ): string {

        if ($index === 1) {

            return 'Heute';
        }


        if ($index === 2) {

            return 'Morgen';
        }


        if ($date === null) {

            return '+'
                .
                ($index - 1);
        }


        $parts =
            explode(
                '.',
                $date
            );


        if (
            count(
                $parts
            )
            !==
            3
        ) {

            return '+'
                .
                ($index - 1);
        }


        $timestamp =
            mktime(
                12,
                0,
                0,
                (int) $parts[1],
                (int) $parts[0],
                (int) $parts[2]
            );


        $names = [

            1 => 'Mo',
            2 => 'Di',
            3 => 'Mi',
            4 => 'Do',
            5 => 'Fr',
            6 => 'Sa',
            7 => 'So'

        ];


        return
            $names[
                (int) date(
                    'N',
                    $timestamp
                )
            ]
            ??
            '';
    }


    /*
     * =========================================================================
     * WETTERSYMBOL
     * =========================================================================
     */

    private function WeatherIcon(
        array $day
    ): string {

        $rain =
            (float) (
                $day['rain']
                ??
                0
            );


        $sun =
            (float) (
                $day['sunHours']
                ??
                0
            );


        $solar =
            (float) (
                $day['solar']
                ??
                0
            );


        $cloud =
            max(
                (float) (
                    $day['cloudLow']
                    ??
                    0
                ),
                (float) (
                    $day['cloudMid']
                    ??
                    0
                ),
                (float) (
                    $day['cloudHigh']
                    ??
                    0
                )
            );


        if ($rain >= 5) {

            return '🌧';
        }


        if ($rain >= 0.5) {

            return '🌦';
        }


        if (
            $sun >= 8
            &&
            $solar >= 4
        ) {

            return '☀️';
        }


        if (
            $cloud >= 70
            ||
            $sun < 2
        ) {

            return '☁️';
        }


        if (
            $cloud >= 35
            ||
            $sun < 6
        ) {

            return '⛅';
        }


        return '🌤';
    }


    /*
     * =========================================================================
     * HTML SICHER
     * =========================================================================
     */

    private function H(
        ?string $value
    ): string {

        return htmlspecialchars(
            $value
            ??
            '',
            ENT_QUOTES |
            ENT_SUBSTITUTE,
            'UTF-8'
        );
    }


    /*
     * =========================================================================
     * VISUALISIERUNG
     * =========================================================================
     */

    private function BuildHtml(
        array $meteo,
        array $solcast
    ): string {

        $dayCards = '';


        foreach (
            $meteo['days']
            as $day
        ) {

            $cloudLow =
                max(
                    0,
                    min(
                        100,
                        (float) (
                            $day['cloudLow']
                            ??
                            0
                        )
                    )
                );


            $cloudMid =
                max(
                    0,
                    min(
                        100,
                        (float) (
                            $day['cloudMid']
                            ??
                            0
                        )
                    )
                );


            $cloudHigh =
                max(
                    0,
                    min(
                        100,
                        (float) (
                            $day['cloudHigh']
                            ??
                            0
                        )
                    )
                );


            $solarPercent =
                max(
                    0,
                    min(
                        100,
                        (
                            (float) (
                                $day['solar']
                                ??
                                0
                            )
                            /
                            6.0
                        )
                        *
                        100
                    )
                );


            $dayCards .=
                '<div class="dayCard">'
                .
                '<div class="dayTop">'
                .
                '<div>'
                .
                '<div class="dayName">'
                .
                $this->H(
                    $this->DayName(
                        (int) $day['index'],
                        $day['date']
                    )
                )
                .
                '</div>'
                .
                '<div class="date">'
                .
                $this->H(
                    $day['date']
                )
                .
                '</div>'
                .
                '</div>'
                .
                '<div class="icon">'
                .
                $this->WeatherIcon(
                    $day
                )
                .
                '</div>'
                .
                '</div>'


                .
                '<div class="temperature">'
                .
                '<span class="maxTemp">'
                .
                $this->F(
                    $day['tempMax'],
                    1
                )
                .
                '°</span>'
                .
                '<span class="minTemp"> / '
                .
                $this->F(
                    $day['tempMin'],
                    1
                )
                .
                '°</span>'
                .
                '</div>'


                .
                $this->DataRow(
                    'Mittel',
                    $this->F(
                        $day['tempMean'],
                        1
                    )
                    .
                    ' °C'
                )


                .
                $this->DataRow(
                    'Solar',
                    $this->F(
                        $day['solar'],
                        2
                    )
                    .
                    ' kWh/m²'
                )


                .
                $this->DataRow(
                    'Sonne',
                    $this->F(
                        $day['sunHours'],
                        1
                    )
                    .
                    ' h'
                )


                .
                $this->DataRow(
                    'Regen',
                    $this->F(
                        $day['rain'],
                        1
                    )
                    .
                    ' mm'
                )


                .
                $this->DataRow(
                    'Wind',
                    $this->F(
                        $day['wind'],
                        1
                    )
                    .
                    ' km/h'
                )


                .
                '<div class="solarTrack">'
                .
                '<div class="solarFill" style="width:'
                .
                round(
                    $solarPercent
                )
                .
                '%"></div>'
                .
                '</div>'


                .
                '<div class="cloudTitle">Bewölkung</div>'


                .
                '<div class="clouds">'
                .
                $this->Cloud(
                    'tief',
                    $cloudLow
                )
                .
                $this->Cloud(
                    'mittel',
                    $cloudMid
                )
                .
                $this->Cloud(
                    'hoch',
                    $cloudHigh
                )
                .
                '</div>'


                .
                '</div>';
        }


        /*
         * Solcast
         */

        $solcastHtml = '';


        if (
            $solcast['available']
            ??
            false
        ) {

            $solcastHtml =
                $this->SolcastBlock(
                    'Nächste 0–24 h',
                    $solcast['next24']
                )
                .
                $this->SolcastBlock(
                    '24–48 h',
                    $solcast['next48']
                );

        } else {

            $solcastHtml =
                '<div class="missing">'
                .
                'Solcast-Daten nicht gefunden.'
                .
                '</div>';
        }


        /*
         * Gesamtes HTML
         */

        return
            '<!DOCTYPE html>'
            .
            '<html>'
            .
            '<head>'
            .
            '<meta charset="UTF-8">'
            .
            '<meta name="viewport" content="width=device-width,initial-scale=1">'
            .
            '<style>'
            .
            $this->Css()
            .
            '</style>'
            .
            '</head>'
            .
            '<body>'


            .
            '<div class="page">'


            .
            '<div class="header">'
            .
            '<div>'
            .
            '<div class="title">Wetterprognose Sulzbann</div>'
            .
            '<div class="subtitle">MeteoSchweiz Zeihen + Solcast PV-Prognose</div>'
            .
            '</div>'


            .
            '<div class="status">'
            .
            '<div>'
            .
            $this->H(
                $meteo['status']
                ??
                ''
            )
            .
            '</div>'
            .
            '<div>Forecast-Lauf: '
            .
            $this->H(
                $meteo['forecastRun']
                ??
                ''
            )
            .
            '</div>'
            .
            '<div>Datenalter: '
            .
            $this->F(
                $meteo['dataAge']
                ??
                null,
                1
            )
            .
            ' h</div>'
            .
            '</div>'
            .
            '</div>'


            .
            '<div class="panel">'
            .
            '<div class="panelHeader">'
            .
            '<div class="panelTitle">MeteoSchweiz</div>'
            .
            '<div class="panelInfo">Zeihen · Punkt 507900</div>'
            .
            '</div>'


            .
            '<div class="forecastScroll">'
            .
            '<div class="forecast">'
            .
            $dayCards
            .
            '</div>'
            .
            '</div>'


            .
            '</div>'


            .
            '<div class="panel">'
            .
            '<div class="panelHeader">'
            .
            '<div class="panelTitle">Solcast</div>'
            .
            '<div class="panelInfo">PV-Ertragsprognose</div>'
            .
            '</div>'


            .
            '<div class="solcastGrid">'
            .
            $solcastHtml
            .
            '</div>'


            .
            '</div>'


            .
            '</div>'


            .
            '<script>'
            . 'function handleMessage(message){'
            . 'if(typeof message==="string"){try{message=JSON.parse(message)}catch(e){return}}'
            . 'if(!message||typeof message.html!=="string")return;'
            . 'const next=new DOMParser().parseFromString(message.html,"text/html");'
            . 'const source=next.querySelector(".page");const target=document.querySelector(".page");'
            . 'if(source&&target)target.replaceWith(source);'
            . '}'
            . '</script>'
            . '</body>'
            .
            '</html>';
    }


    /*
     * =========================================================================
     * DATENZEILE
     * =========================================================================
     */

    private function DataRow(
        string $label,
        string $value
    ): string {

        return
            '<div class="row">'
            .
            '<span class="label">'
            .
            $this->H(
                $label
            )
            .
            '</span>'
            .
            '<span class="value">'
            .
            $this->H(
                $value
            )
            .
            '</span>'
            .
            '</div>';
    }


    /*
     * =========================================================================
     * CLOUD
     * =========================================================================
     */

    private function Cloud(
        string $name,
        float $value
    ): string {

        return
            '<div class="cloud">'
            .
            '<div class="cloudName">'
            .
            $this->H(
                $name
            )
            .
            '</div>'
            .
            '<div class="cloudValue">'
            .
            round(
                $value
            )
            .
            ' %</div>'
            .
            '<div class="cloudTrack">'
            .
            '<div class="cloudFill" style="width:'
            .
            round(
                $value
            )
            .
            '%"></div>'
            .
            '</div>'
            .
            '</div>';
    }


    /*
     * =========================================================================
     * SOLCAST BLOCK
     * =========================================================================
     */

    private function SolcastBlock(
        string $title,
        array $data
    ): string {

        $p10 =
            $data['p10']
            ??
            null;


        $p50 =
            $data['p50']
            ??
            null;


        $p90 =
            $data['p90']
            ??
            null;


        $band =
            (
                $p10 !== null
                &&
                $p90 !== null
            )
                ?
                $p90 - $p10
                :
                null;


        return
            '<div class="solcastCard">'
            .
            '<div class="solcastTitle">'
            .
            $this->H(
                $title
            )
            .
            '</div>'


            .
            '<div class="scenarioGrid">'
            .
            $this->Scenario(
                'P10',
                $p10
            )
            .
            $this->Scenario(
                'P50',
                $p50
            )
            .
            $this->Scenario(
                'P90',
                $p90
            )
            .
            '</div>'


            .
            '<div class="detailGrid">'
            .
            $this->Detail(
                'Peak',
                $this->F(
                    $data['peak']
                    ??
                    null,
                    2
                )
                .
                ' kW'
            )
            .
            $this->Detail(
                'P10–P90',
                $this->F(
                    $band,
                    1
                )
                .
                ' kWh'
            )
            .
            $this->Detail(
                'Vertrauen',
                $this->F(
                    $data['confidence']
                    ??
                    null,
                    1
                )
                .
                ' %'
            )
            .
            '</div>'


            .
            '</div>';
    }


    /*
     * =========================================================================
     * SCENARIO
     * =========================================================================
     */

    private function Scenario(
        string $name,
        ?float $value
    ): string {

        return
            '<div class="scenario">'
            .
            '<div class="scenarioName">'
            .
            $this->H(
                $name
            )
            .
            '</div>'
            .
            '<div class="scenarioValue">'
            .
            $this->F(
                $value,
                1
            )
            .
            '<span> kWh</span>'
            .
            '</div>'
            .
            '</div>';
    }


    /*
     * =========================================================================
     * DETAIL
     * =========================================================================
     */

    private function Detail(
        string $name,
        string $value
    ): string {

        return
            '<div class="detail">'
            .
            '<div class="detailName">'
            .
            $this->H(
                $name
            )
            .
            '</div>'
            .
            '<div class="detailValue">'
            .
            $this->H(
                $value
            )
            .
            '</div>'
            .
            '</div>';
    }


    /*
     * =========================================================================
     * CSS
     * =========================================================================
     */

    private function Css(): string
    {
        return <<<'CSS'

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    background: #0d1218;
    color: #f2f5f7;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
}

body {
    min-height: 100vh;
}

.page {
    width: 100%;
    padding: 16px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 15px;
}

.title {
    font-size: 27px;
    font-weight: 700;
}

.subtitle {
    margin-top: 4px;
    font-size: 12px;
    color: #92a1ae;
}

.status {
    text-align: right;
    font-size: 11px;
    line-height: 1.55;
    color: #8fa2b1;
}

.panel {
    margin-bottom: 14px;
    padding: 14px;
    background: #151c24;
    border: 1px solid #293541;
    border-radius: 13px;
}

.panelHeader {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
}

.panelTitle {
    font-size: 17px;
    font-weight: 700;
}

.panelInfo {
    font-size: 10px;
    color: #8fa1b0;
}

.forecastScroll {
    overflow-x: auto;
    overflow-y: hidden;
}

.forecast {
    display: grid;
    grid-template-columns: repeat(9, minmax(140px, 1fr));
    gap: 8px;
    min-width: 1260px;
}

.dayCard {
    padding: 11px;
    background: #10171e;
    border: 1px solid #222e39;
    border-radius: 10px;
}

.dayTop {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 9px;
}

.dayName {
    font-size: 13px;
    font-weight: 700;
}

.date {
    margin-top: 2px;
    font-size: 9px;
    color: #778795;
}

.icon {
    font-size: 21px;
}

.temperature {
    margin-bottom: 9px;
}

.maxTemp {
    font-size: 25px;
    font-weight: 700;
}

.minTemp {
    font-size: 13px;
    color: #8595a2;
}

.row {
    display: flex;
    justify-content: space-between;
    gap: 7px;
    margin: 4px 0;
    font-size: 10px;
}

.label {
    color: #8796a3;
}

.value {
    white-space: nowrap;
    text-align: right;
}

.solarTrack {
    height: 4px;
    margin-top: 9px;
    background: #24303a;
    overflow: hidden;
    border-radius: 99px;
}

.solarFill {
    height: 100%;
    background: #74a9d8;
    border-radius: 99px;
}

.cloudTitle {
    margin-top: 9px;
    padding-top: 7px;
    border-top: 1px solid #202a34;
    font-size: 9px;
    color: #778795;
}

.clouds {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 3px;
    margin-top: 5px;
}

.cloud {
    text-align: center;
}

.cloudName {
    font-size: 8px;
    color: #74828e;
}

.cloudValue {
    margin-top: 2px;
    font-size: 10px;
}

.cloudTrack {
    height: 3px;
    margin-top: 3px;
    background: #25313b;
    border-radius: 99px;
    overflow: hidden;
}

.cloudFill {
    height: 100%;
    background: #8b99a5;
}

.solcastGrid {
    display: grid;
    grid-template-columns: repeat(2, minmax(280px, 1fr));
    gap: 10px;
}

.solcastCard {
    padding: 13px;
    background: #10171e;
    border: 1px solid #222e39;
    border-radius: 10px;
}

.solcastTitle {
    margin-bottom: 11px;
    font-size: 13px;
    font-weight: 700;
}

.scenarioGrid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
}

.scenario {
    padding: 9px 5px;
    background: #19222c;
    border-radius: 7px;
    text-align: center;
}

.scenarioName {
    font-size: 9px;
    color: #8b9aa6;
}

.scenarioValue {
    margin-top: 3px;
    font-size: 17px;
    font-weight: 700;
}

.scenarioValue span {
    font-size: 8px;
    font-weight: 400;
    color: #7f8e99;
}

.detailGrid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin-top: 7px;
}

.detail {
    padding: 7px;
    background: #19222c;
    border-radius: 7px;
}

.detailName {
    font-size: 8px;
    color: #7d8c98;
}

.detailValue {
    margin-top: 3px;
    font-size: 12px;
    font-weight: 600;
}

.missing {
    padding: 20px;
    color: #d47c7c;
}

@media (max-width: 850px) {

    .header {
        flex-direction: column;
    }

    .status {
        text-align: left;
    }

    .solcastGrid {
        grid-template-columns: 1fr;
    }
}

/* ============================================================
   TILE-STANDARD 1.1 – überschreibt die alte HTMLBox-Darstellung
============================================================ */

:root {
    color-scheme: light dark;
    --bg: transparent;
    --text: #171a1c;
    --muted: #707980;
    --line: #d7dadd;
    --panel: #ffffff;
    --card: #ffffff;
    --soft: #f3f5f6;
    --solar: #d79a12;
    --cloud: #8796a2;
    --error: #b33b3b;
}

@media (prefers-color-scheme: dark) {
    :root {
        --text: #f1f4f6;
        --muted: #a4adb5;
        --line: rgba(255,255,255,.15);
        --panel: rgba(255,255,255,.035);
        --card: rgba(255,255,255,.025);
        --soft: rgba(255,255,255,.065);
        --solar: #efb82f;
        --cloud: #9ba8b2;
        --error: #ef8888;
    }
}

html,
body {
    width: 100%;
    height: 100%;
    min-height: 0;
    margin: 0;
    overflow: hidden;
    background: var(--bg);
    color: var(--text);
}

body {
    min-height: 0;
}

.page {
    width: 100%;
    height: 100%;
    padding: 2.7rem .55rem .5rem;
    display: grid;
    grid-template-rows: auto minmax(0, 1fr) auto;
    gap: .42rem;
    overflow: hidden;
}

.header {
    min-width: 0;
    margin: 0;
    display: flex;
    align-items: center;
    gap: .65rem;
}

.title {
    font-size: 15px;
    line-height: 1.15;
}

.subtitle {
    margin-top: .1rem;
    color: var(--muted);
    font-size: 10px;
}

.status {
    margin-left: auto;
    color: var(--muted);
    font-size: 9px;
    line-height: 1.3;
    white-space: nowrap;
}

.panel {
    min-width: 0;
    min-height: 0;
    margin: 0;
    padding: .48rem;
    border: 1px solid var(--line);
    border-radius: .72rem;
    background: var(--panel);
    overflow: hidden;
}

.panelHeader {
    margin-bottom: .36rem;
}

.panelTitle {
    font-size: 13px;
}

.panelInfo {
    color: var(--muted);
    font-size: 9px;
}

.forecastScroll {
    height: calc(100% - 1.25rem);
    overflow: hidden;
}

.forecast {
    height: 100%;
    min-width: 0;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .34rem;
}

.dayCard {
    min-width: 0;
    padding: .42rem;
    border: 1px solid var(--line);
    border-radius: .58rem;
    background: var(--card);
    overflow: hidden;
}

.dayCard:nth-child(n+5) {
    display: none;
}

.dayTop {
    margin-bottom: .28rem;
}

.dayName {
    font-size: 12px;
}

.date {
    color: var(--muted);
    font-size: 8px;
}

.icon {
    font-size: 25px;
}

.temperature {
    margin-bottom: .3rem;
}

.maxTemp {
    font-size: 20px;
}

.minTemp {
    color: var(--muted);
    font-size: 10px;
}

.row {
    margin: .13rem 0;
    font-size: 9px;
}

.label,
.cloudTitle,
.cloudName,
.scenarioName,
.detailName,
.scenarioValue span {
    color: var(--muted);
}

.solarTrack {
    height: 3px;
    margin-top: .3rem;
    background: var(--soft);
}

.solarFill {
    background: var(--solar);
}

.cloudTitle {
    margin-top: .3rem;
    padding-top: .25rem;
    border-color: var(--line);
    font-size: 8px;
}

.clouds {
    margin-top: .2rem;
}

.cloudValue {
    font-size: 9px;
}

.cloudTrack {
    background: var(--soft);
}

.cloudFill {
    background: var(--cloud);
}

.solcastGrid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .34rem;
}

.solcastCard {
    min-width: 0;
    padding: .4rem;
    border: 1px solid var(--line);
    border-radius: .58rem;
    background: var(--card);
}

.solcastTitle {
    margin-bottom: .26rem;
    font-size: 11px;
}

.scenarioGrid,
.detailGrid {
    gap: .24rem;
}

.detailGrid {
    margin-top: .24rem;
}

.scenario,
.detail {
    padding: .28rem .2rem;
    background: var(--soft);
}

.scenarioValue {
    font-size: 13px;
}

.detailValue {
    font-size: 9px;
}

.missing {
    padding: .5rem;
    color: var(--error);
}

@media (min-width: 1050px) {
    .forecast {
        grid-template-columns: repeat(9, minmax(0, 1fr));
    }

    .dayCard:nth-child(n) {
        display: block;
    }
}

@media (max-width: 600px) {
    .page {
        padding: 2.65rem .38rem .38rem;
        gap: .3rem;
    }

    .header {
        flex-direction: row;
    }

    .status {
        text-align: right;
    }

    .forecast {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .25rem;
    }

    .dayCard:nth-child(n+4) {
        display: none;
    }

    .dayCard {
        padding: .34rem;
    }

    .dayName {
        font-size: 11px;
    }

    .icon {
        font-size: 23px;
    }

    .row:nth-of-type(n+5),
    .cloudTitle,
    .clouds {
        display: none;
    }

    .solcastGrid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-height: 470px) {
    .subtitle,
    .cloudTitle,
    .clouds,
    .row:nth-of-type(n+4) {
        display: none;
    }

    .page {
        gap: .28rem;
    }
}

CSS;
    }


    /*
     * =========================================================================
     * FEHLER HTML
     * =========================================================================
     */

    private function BuildErrorHtml(
        string $message
    ): string {

        return
            '<html>'
            .
            '<body style="background:#0d1218;color:#e97777;font-family:Arial;padding:20px;">'
            .
            '<h3>Sulzbann Wettervisu</h3>'
            .
            '<p>'
            .
            $this->H(
                $message
            )
            .
            '</p>'
            .
            '</body>'
            .
            '</html>';
    }


    /* Live-Aktualisierung der geöffneten Kachel. */

    private function WriteHtmlIfChanged(
        string $html
    ): void {
        $payload = json_encode(
            ['html' => $html],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload !== false) {
            $this->UpdateVisualizationValue($payload);
        }

        /* Bestehende HTMLBox-Installation während der Umstellung weiterführen. */
        $legacyId = @IPS_GetObjectIDByIdent('HTML', $this->InstanceID);
        if ($legacyId > 0 && IPS_VariableExists($legacyId)) {
            if (GetValueString($legacyId) !== $html) {
                SetValueString($legacyId, $html);
            }
        }
    }

}
