# Webshop (WooCommerce)

De backoffice is de baas over producten, prijzen en beschikbaarheid; WooCommerce volgt. Er wordt
**niets** naar de shop geschreven zolang onder Systeem → Webshop de schakelaar **Schrijven naar de
webshop** uit staat. Tot die tijd is elke sync een proefrun die alleen laat zien wat er zou veranderen.

## De vier stromen

| Stroom | Wanneer | Richting | Wat |
|---|---|---|---|
| 1. Ophalen en koppelen | Handmatig, daarna elke nacht 02:15 | Woo → backoffice | Alle producten in de spiegel (`woo_products`), elk met een status: te koppelen, gekoppeld, geen EAN, EAN dubbel, variabel. "Koppeling uitvoeren" koppelt de schone. |
| 2. Prijs en beschikbaarheid | Na elke geslaagde leveranciersimport, na een prijswijziging en 's nachts | backoffice → Woo | Alleen wat verschilt, in batches van 100. |
| 3. Webhooks | Direct | Woo → backoffice | Product gewijzigd in WordPress → spiegel bijgewerkt; een veld dat de backoffice bezit wordt een sync-conflict. Bestellingen met campagne-herkomst. |
| 4. Nachtelijke controle | 02:15 | beide | Alles ophalen, rapport bijwerken, conflicten zoeken, daarna stroom 2. |

## Beschikbaarheid in de shop

| Backoffice | `stock_status` | `_mp_stock_label` |
|---|---|---|
| ruim (meer dan 5 bij één leverancier) | `instock` | Ruim op voorraad |
| beperkt (1–5) | `instock` | Beperkt op voorraad |
| leverbaar (geen voorraad bekend, wel op een prijslijst met "Ga uit van leverbaar", zoals Midwich) | `instock` | Leverbaar |
| nalevering (0, met verse lijst) | `onbackorder` | Op nalevering (+ `_mp_stock_next_date`) |
| onbekend (geen verse lijst of geen leverancier) | ongewijzigd | leeg |

Het thema kan `_mp_stock_label` en `_mp_stock_next_date` tonen. Staat bij een product "Voorraad
beheren" aan in WooCommerce, dan zet de sync dat uit; anders negeert WooCommerce de voorraadstatus.

## Conflicten

Een conflict is een veld dat de backoffice heeft gestuurd en dat daarna in WooCommerce op een andere
waarde is gezet. De echo van de eigen update is nooit een conflict. De taak geeft twee keuzes:
**Backofficewaarde terugzetten** of **Webshopprijs overnemen**. Zolang de taak open staat, slaat de
sync dat product over.

## Instellen (eerst staging)

1. WordPress → WooCommerce → Instellingen → Geavanceerd → REST API: sleutel met rechten
   **Lezen/Schrijven**, gekoppeld aan een beheerder.
2. Backoffice → Systeem → Webshop: consumer key en secret invullen, **Verbinding testen**.
3. **Producten ophalen**, daarna Webshop → Koppelrapport bekijken.
4. **Koppeling uitvoeren**: producten met een unieke EAN worden gekoppeld; de huidige shopprijs is
   het startpunt.
5. **Proefrun bijwerken** en de run onder Werk → Runs lezen: dit zou er veranderen.
6. Webhookgeheim kiezen en invullen; in WooCommerce → Webhooks vier webhooks met de getoonde
   bezorg-URL, API v3: Product bijgewerkt, Product aangemaakt, Product verwijderd, Bestelling aangemaakt.
7. Pas dan **Schrijven naar de webshop** aanzetten.

## Waar de EAN gelezen wordt

Op volgorde: het GTIN-veld (`global_unique_id`, WooCommerce 9.2+), een attribuut met de naam
EAN / EAN nummer / GTIN / Barcode, of een metaveld (`_ean`, `_wpm_gtin_code`, `_alg_ean`,
`hwp_product_gtin`, `_ts_gtin`, …).

## Bestellingen

Alleen zakelijke gegevens: nummer, status, totaal, bedrijfsnaam, regels en herkomst (UTM, landingspagina,
gclid). Geen namen, e-mailadressen of adressen.
