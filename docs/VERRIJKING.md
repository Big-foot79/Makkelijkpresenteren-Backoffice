# Verrijking (fase 4) — uitgangspunt

Afgeleid uit de n8n-workflow *Nieuw Product - Icecat - AI* (februari 2026, gemarkeerd als "Buiten
gebruik"). Dit is het vertrekpunt voor fase 4; de nieuwe opzet neemt de mapping over, maar niet de manier
van werken (zie *Lessen*).

## Oude stroom

1. WooCommerce-webhook *product aangemaakt* → n8n.
2. EAN uit het eerste Woo-attribuut (`attributes[0].options[0]`). Geen EAN → stop.
3. Icecat Live API (Open Icecat, Nederlands):
   `https://live.icecat.biz/api?lang=NL&shopname=provisionav&GTIN=<EAN>&content=`
   Bij `msg != OK` stopt de verrijking; een terugval naar een AI-model stond uitgeschakeld.
4. Icecat-kenmerken omzetten naar Woo-attributen (tabel hieronder).
5. Fabrikantpagina zoeken via SerpAPI (Google NL, "merk + productnaam + product"): het eerste
   resultaat waarvan de link de merknaam bevat.
6. OpenAI (GPT-5.1, JSON-uitvoer) schrijft productomschrijving, SEO-titel, meta-omschrijving en
   focus keyphrase (prompt hieronder).
7. Direct naar WooCommerce (PUT product): naam, slug, omschrijving, korte omschrijving, sku, externe
   URL, afbeeldingen, attributen en Yoast-velden. Mislukken de afbeeldingen, dan nogmaals zonder.

## Velden uit Icecat

| Woo-veld | Icecat |
|---|---|
| naam | `GeneralInfo.TitleInfo.GeneratedIntTitle` |
| slug | `GeneralInfo.TitleInfo.GeneratedLocalTitle.Value` |
| omschrijving (invoer voor AI) | `GeneralInfo.SummaryDescription.LongSummaryDescription` |
| korte omschrijving | `GeneralInfo.SummaryDescription.ShortSummaryDescription` |
| merk (Yoast primary brand) | `GeneralInfo.BrandInfo.BrandName` |
| sku | `GeneralInfo.BrandPartCode` |
| externe URL | `Multimedia[0].URL` |
| afbeeldingen | `Image.HighPic` + `Gallery[1..4].Pic` |
| bulletpoints (invoer voor AI) | `GeneralInfo.GeneratedBulletPoints.Values` |
| categorie (invoer voor AI) | `GeneralInfo.Category.Name.Value` |

## Attributen

Icecat-kenmerken (Nederlandse namen, `PresentationValue`) naar Woo-attributen. Bij meerdere namen telt
de eerste die gevuld is; "samengevoegd" combineert de waarden als `naam: waarde | naam: waarde`.

| Woo-attribuut | Icecat-kenmerk(en) |
|---|---|
| Resolutie | Resolutie, Projector native resolution |
| Aspect Ratio | Oorspronkelijke beeldverhouding |
| Helderheid | Helderheid, Projector helderheid |
| Beelddiagonaal | Beeldschermdiagonaal |
| HD type | HD type |
| Display technologie | Display technologie |
| Beeldscherm vorm | Beeldscherm vorm |
| Kijkhoek (samengevoegd) | Kijkhoek, horizontaal · Kijkhoek, verticaal |
| VESA maat | Paneelmontage-interface |
| Projectietechnologie | Projectietechnologie |
| Throw ratio | Throw ratio |
| Keystone (samengevoegd) | Keystone correctie, horizontaal · Keystone correctie, verticaal |
| Type lichtbron | Type lichtbron |
| Levensduur lichtbron | Levensduur van de lichtbron |
| Levensduur lichtbron (eco) | Levensduur van de lichtbron (besparingsmodus) |
| Projectie-afstand (samengevoegd) | Projectie-afstand objectief · Projectie afstand (breed) · Projectie afstand (tele) |
| Stroomverbruik (typisch) | Stroomverbruik (typisch) |
| Stroomverbruik (standby) | Stroomverbruik (in standby) |
| Energielabel | Energie-efficiëntieklasse (SDR) |
| Netwerk (samengevoegd) | Ethernet LAN · Wifi · Wi-Fi-standaarden |
| Kleur | Kleur van het product |
| Breedte | Breedte ( zonder voet ), Breedte apparaat (met standaard), Breedte |
| Hoogte | Hoogte (zonder voet ), Hoogte apparaat (met standaard), Hoogte |
| Diepte | Diepte ( zonder voet ), Diepte apparaat (met standaard), Diepte |
| Gewicht | Gewicht (zonder voet), Gewicht (met standaard), Gewicht |
| Audio (samengevoegd) | Aantal luidsprekers / Aantal ingebouwde luidsprekers, Gemiddeld vermogen, Audio-uitgangskanalen, Ingebouwde audio decoders, Geluidsmodi, eARC, ARC |
| EAN nummer | EAN uit WooCommerce |

## AI-prompt (samengevat)

Rol: technische contentspecialist en SEO-expert, neutraal en feitelijk, correct Nederlands, alleen op
basis van de aangeleverde data. Uitvoer als JSON:

- `html_description`: `<h2>Productbeschrijving</h2>` + samenvatting, `<h3>Belangrijkste kenmerken</h3>`
  met 3 bullets, `<h3>Technische toelichting</h3>`, plus een link naar de fabrikantpagina;
- `seo_title` (≤ 60 tekens) en `seo_description` (≤ 160 tekens), beide met de focus keyphrase;
- `focus_keyphrase` (2–5 woorden, letterlijk in de productdata) en `seo_keyword`.

Invoer: productnaam, fabrikantomschrijving, bulletpoints, attributen, categorie, fabrikant-URL.
Yoast-velden: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`,
`_yoast_wpseo_focuskeywords`, `_yoast_wpseo_primary_product_brand`; daarnaast `_manufacturer_url`.

## Lessen voor de nieuwe opzet

1. **Niets direct live.** De oude stroom overschreef naam, sku, omschrijving en afbeeldingen meteen in
   de shop. Nieuw: verrijking is een concept in de backoffice; publiceren na goedkeuring (of per regel
   automatisch als het vertrouwen er is).
2. **Sku niet overschrijven** met de BrandPartCode van Icecat. De sku in Woo is onze sleutel naast de EAN.
3. **EAN niet uit "het eerste attribuut" halen**, maar uit het product in de backoffice.
4. **Geen vaste posities** in Icecat-data (gewicht kwam uit `FeaturesGroups[1].Features[1]`); altijd op
   kenmerknaam zoeken, zoals bij de attributen al gebeurde.
5. **Elke stap een eigen, herhaalbare stap** met status: Icecat, attributen, fabrikant-URL, AI-tekst,
   afbeeldingen. Mislukte afbeeldingen zijn een taak, geen stille terugval.
6. **Geen sleutels in URL's of code.** API-sleutels (SerpAPI, OpenAI) staan versleuteld in de instellingen.
7. **Tweede bron naast Open Icecat**: ALSO levert dagelijks `ICEcat-productsheets.xml.zip` (zie
   [LEVERANCIERS.md](LEVERANCIERS.md)); mogelijk met merken die Open Icecat niet heeft.
8. **Attributenmapping beheren in een scherm** in plaats van in code, zodat een nieuw kenmerk geen
   aanpassing van de software vraagt.
