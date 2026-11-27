# Leveranciersbestanden

Overzicht van de bestanden die de vier distributeurs aanleveren, afgeleid uit de bestaande n8n-workflows
(november 2026) en de DataPack Pro-handleiding van TD Synnex. Basis voor de importprofielen in fase 2.

Kolomnummers zijn 0-gebaseerd. "Oud ID" is het `Supplier_Id` in de oude Postgres-database.

## Overzicht

| Leverancier | Oud ID | Verbinding | Bestanden | Scheiding | Kop | Oude planning |
|---|---|---|---|---|---|---|
| ALSO | 1 | SFTP | `/stock.txt`, `/pricelist-2.txt.zip` | tab (voorraad), `;` (prijslijst) | ja / nee | voorraad wekelijks, prijs ma + do 07:00 |
| TD Synnex | 2 | FTP | `*_A_yyyymmdd.zip`, `*_C_yyyymmdd.zip` (+ `Projectprices`, `B`, `MIQ`) | `;` | nee | voorraad elke 3 dagen 08:00, prijs wekelijks, materiaal wekelijks (za) |
| Travion | 4 | FTP | alles in `/pricefile/` (één CSV met materiaal, prijs en voorraad) | `;` | ja | dagelijks 04:00 |
| Copaco | 5 | FTP | `/114116/out/Copaco_prijslijst_114116.csv` (materiaal, prijs en voorraad) | `,` | data vanaf regel 16 | dagelijks 04:00 |

## ALSO

**Voorraad** — `/stock.txt`, tab-gescheiden, met kopregel.

| Kolom (naam) | Veld |
|---|---|
| `ProductID` | leveranciersartikelnummer |
| `AvailableQuantity` | voorraad |
| `AvailableNextDate` | verwachte datum (`YYYYMMDD`) |
| `AvailableNextQuantity` | verwacht aantal |
| `AvailabilityDate` + `AvailabilityTime` | peilmoment voorraad (`YYYYMMDD` + `HHMMSS`, lokale tijd) |

**Prijslijst / materiaal** — een ZIP met één tekstbestand. De kolommen van `pricelist-1` en `pricelist-2`
stellen we zelf in bij ALSO. Welk bestand de backoffice leest, bepaalt de instelling `catalog_file`
(standaard `pricelist-2.txt.zip`). De import herkent zelf welke opbouw het bestand heeft:

*Met kopregel* (onze eigen indeling, bedoeld voor `pricelist-1`). De kolommen worden op naam gelezen.
Het scheidingsteken en de tekenset worden automatisch herkend.

| Kolom | Veld |
|---|---|
| `ProductID` | leveranciersartikelnummer (verplicht) |
| `EAN` | EAN (verplicht) |
| `ManufacturerPartNumber` | fabrikantnummer |
| `ManfacturerName` | merk (ALSO schrijft het zo; `ManufacturerName` mag ook) |
| `ShortDescription` | omschrijving |
| `NetPrice` | netto inkoopprijs (verplicht) |
| `NetRetailPrice` | adviesprijs, gebruikt voor de merkkorting |
| `RecyclingFee` | toeslag (verwijderingsbijdrage) |
| `CategoryText1-3` | categorie, samengevoegd als "1 > 2 > 3" |
| `EndOfLife` | end of life bij J/Y/X/1, of bij een datum in het verleden |
| `WarrantyMonths` | garantie (maanden) |

*Zonder kopregel* (de oude indeling van `pricelist-2`, zoals n8n die las): `;`, minimaal 16 kolommen.

| Kolom | Veld |
|---|---|
| 0 | leveranciersartikelnummer |
| 1 | EAN (alleen cijfers bewaren) |
| 5 | omschrijving |
| 7 | netto inkoopprijs (terugval: kolom 15) |
| 8 | fabrikantnummer |
| 9 | merk |
| 13 | garantie (maanden) |

Deze oude indeling heeft geen adviesprijs.

**Alles op de SFTP-server** (stand 26 november 2026)

| Bestand | Grootte | Ververst | Gebruik |
|---|---|---|---|
| `stock.txt` | ca. 10 MB | meerdere keren per dag (laatste 19:08) | voorraad |
| `pricelist-2.txt.zip` | 2,4 MB | dagelijks ca. 05:00 | materiaal + prijs (in gebruik) |
| `pricelist-1.txt.zip` | 2,4 MB | dagelijks ca. 05:00 | vermoedelijk variant van pricelist-2; nog uitzoeken |
| `csell.txt` | 32 MB | dagelijks | cross-sell: accessoires en gerelateerde producten (kandidaat voor upsell) |
| `ICEcat-productsheets.xml.zip` | 517 MB | dagelijks | Icecat-productdata via ALSO (kandidaat als verrijkingsbron naast Open Icecat) |
| `ICEcat-vendors.txt` | 10 KB | dagelijks | merkenlijst bij de Icecat-data |
| `ICEcat-productsheets.txt.zip` | 258 MB | juni 2024 | verouderd, negeren |
| `/in`, `/out` | map | — | vermoedelijk voor de XML-bestelkoppeling |

## TD Synnex (DataPack Pro)

Bestanden heten `<account>_<pakket>_yyyymmdd.zip`, dagelijks rond 08:00. Inhoud is **ANSI** (Windows-1252), `;`, geen kop.
Alle bestanden koppelen op `Matnr`; alleen de MaterialFile bevat de EAN.

| Pakket | Bestanden erin |
|---|---|
| `A` | `MaterialFile`, `CustSpecific`, `CustSpecificQty`, `Kit` |
| `C` | `StockFile` |
| `Projectprices` | `Projectprices` (bids; apart aan te vragen) |
| `B` | `BackLog`, `Dnote`, `Invoice`, `SerialNo` (09:00) |
| `MIQ` | minimale bestelhoeveelheid |

**MaterialFile**

| Kolom | Veld |
|---|---|
| 0 | Matnr (leveranciersartikelnummer) |
| 1 | landcode |
| 2 / 3 | korte / lange omschrijving |
| 4 | fabrikantnummer |
| 5 / 6 | merk lokaal / globaal |
| 7 | producthiërarchie |
| 8 | kit-vlag |
| 9 | EAN (alleen cijfers bewaren) |
| 17 / 18 | CNET beschikbaar / CNET-ID |
| 19 | gewicht (kg) |
| 20 / 21 | ListPrice (adviesprijs) / valuta |
| 29 / 30 | OnHoldFlag (01/02 = EOL, 39/40 = allocatie, 50 = niet leverbaar) / opvolger |

**CustSpecific** (niet `CustSpecificQty`)

| Kolom | Veld |
|---|---|
| 0 | klantnummer |
| 1 | Matnr |
| 2 | netto inkoopprijs (CustBestPrice) |
| 3 | valuta |
| 4 | toeslag (bijv. verwijderingsbijdrage) |

**StockFile**

| Kolom | Veld |
|---|---|
| 0 | Matnr |
| 1 | voorraad |
| 2 | verwachte datum (`DD.MM.YYYY`) bij voorraad 0 |
| 3 | fabrikantnummer |
| 4 | magazijn: 1818 = lokaal (1 dag), 1854 = centraal (2–7 dagen); in de praktijk ook 1855, vaak met exact dezelfde voorraad als 1818 |
| 5 | categorie: 1 = leverbaar, 4 = besteld bij fabrikant, 9 = niet leverbaar, geen order |

Omdat 1855 dezelfde voorraad lijkt te melden als 1818, telt de backoffice per leverancier het **hoogste**
magazijn, niet de som (`STOCK_LOCATIONS=max`). Nog navragen bij TD Synnex wat 1855 is.

Sommige artikelen hebben als korte omschrijving alleen `^`; dan wordt de lange omschrijving gebruikt.

Speciale datums in kolom 2 betekenen *allocatie* en *levertijd overschreden*, geen echte datum. In de
handleiding staan 17.11.2016 en 15.12.2016, met de opmerking dat ze **jaarlijks wijzigen**: niet hard coderen,
maar instelbaar maken en herkennen als datum in het verleden.

## Travion

Eén bestand in `/pricefile/`, `;`, met kopregel. Account `TD042012`.

| Kolom (naam) | Veld |
|---|---|
| `ARTICLENR` | leveranciersartikelnummer |
| `DESCRIPTION` | omschrijving |
| `EANNR` | EAN |
| `OEMNR` | fabrikantnummer |
| `BRAND` | merk |
| `UNSPSCDESCRIPTION` | categorie |
| `PRICE` / `CURRENCY` | netto inkoopprijs / valuta |
| `STOCK` | voorraad |

Geen verwachte datum en geen adviesprijs.

## Copaco

Eén bestand `/114116/out/Copaco_prijslijst_114116.csv`, `,`-gescheiden, de eerste 15 regels zijn een kopblok.

| Kolom | Veld |
|---|---|
| 0 | Copaco-artikelnummer |
| 1 | fabrikantnummer |
| 2 | merk |
| 3 | omschrijving |
| 5 | netto inkoopprijs |
| 6 | voorraad |
| 7 | producthiërarchie |
| 9 | EAN |
| 10 | beschikbaarheidscategorie |

Geen adviesprijs. Let op: Copaco bestelt niet op EAN, dus het Copaco-artikelnummer of fabrikantnummer moet
bewaard blijven voor de bestelkoppeling.

## Lessen uit de oude workflows

1. **Eerst wissen, dan vullen.** De oude workflows legen eerst de tabel en lezen daarna in. Mislukt de download,
   dan is de voorraad van die leverancier weg. Nieuw: inlezen in een nieuwe peiling en pas na een geslaagde
   import als actueel markeren.
2. **Bestandskeuze op losse letters.** TD Synnex koos bestanden met "pad bevat A" of "bevat C". Nieuw: exact
   patroon `*_A_yyyymmdd.zip` en altijd het nieuwste bestand.
3. **Tekenset.** TD Synnex levert ANSI, de oude import las als UTF-8. Merknamen met accenten raakten zo beschadigd.
4. **Planning past niet bij de 5-dagenregel.** ALSO-voorraad liep wekelijks; dan is die altijd ouder dan 5 dagen
   en telt hij nooit mee. Nieuw: alle voorraad dagelijks, prijzen en materiaal dagelijks of wekelijks per leverancier.
5. **Tijdzone vast op +01:00** bij ALSO; in de zomer klopt dat niet. Nieuw: lezen als Europe/Amsterdam.
6. **Travion:** `Available_Next_Date` werd gevuld met de bestandsdatum. Travion levert geen verwachte datum; leeg laten.
7. **Copaco:** account-code staat als `1141116`, de map als `114116`. Controleren welke klopt.

## Merkafspraken en prijslijsten (verwachte inkoop)

De lijstprijs van een distributeur is zelden wat we echt betalen. Per EAN rekent de backoffice daarom
een **verwachte inkoop** uit. Er zijn drie kandidaten en de laagste wint:

1. **Bid**: een inkoop- of bidprijs uit een geldige, ingelezen prijslijst (Leveranciers → Prijslijsten).
2. **Merkkorting**: de adviesprijs min de gebruikelijke korting van het merk (Leveranciers →
   Merkafspraken). De adviesprijs komt uit een ingelezen prijslijst, of anders van TD Synnex.
3. **Lijst**: de laagste inkoopprijs bij de distributeurs.

De marge in Producten, op het productscherm en in de MCP-tool rekent met deze verwachte inkoop.
Hoe de prijs tot stand kwam, staat erbij.

**Prijslijst inlezen.** Een prijslijst kan een `.xlsx`, `.ods` of `.csv` zijn. Een `.xls` sla je eerst
op als `.xlsx`. Na het uploaden zoekt de backoffice zelf de kopregel, ook als er een titelregel
boven staat, en raadt de kolommen. Je controleert de kolomkeuze met het voorbeeld en klikt op
**Inlezen**. Regels zonder EAN krijgen er een als een leverancier hetzelfde artikelnummer van
de fabrikant met EAN voert. Een prijslijst telt alleen mee binnen de geldigheidsdatums.
Opnieuw inlezen vervangt de regels.
