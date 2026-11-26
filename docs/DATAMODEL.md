# Datamodel

Het product is het middelpunt. Alles koppelt via het interne product-ID; het product koppelt
naar buiten via EAN (of de Odoo-artikelcode voor artikelen zonder EAN). Leveranciersdata staat
apart, zodat de 100k+ catalogusartikelen nooit in WooCommerce of Odoo belanden.

De backoffice is de **bron van waarheid** voor producten, prijzen, voorraad en verrijking.
WooCommerce volgt.

## Fase 1 (fundament) — aanwezig

| Tabel | Inhoud |
|---|---|
| `users` | Gebruikers met `role` (`admin`, `staff`, `agent`). AI-agents zijn gewone gebruikers met rol `agent` en een Sanctum-token. |
| `personal_access_tokens` | Tokens voor AI-agents (MCP). |
| `job_runs` | Elke achtergrondactie als zichtbare run: type, status, log, samenvatting, onderwerp. |
| `tasks` | Alles wat een beslissing nodig heeft: sync-conflict, mislukte run, geen match, prijsafwijking, goedkeuring. |
| `changes` | Wijzigingshistorie: wie (gebruiker, agent, job, systeem), via welk kanaal, oud en nieuw. |
| `jobs`, `failed_jobs` | Laravel-queue (database-driver). |

## Volgende fases — gepland

| Object | Belangrijkste velden | Koppelt aan |
|---|---|---|
| **Product** | Intern ID, EAN, Odoo-code, merk, fabrikantnummer, titel, status, Woo-ID, Odoo-ID | Kern |
| Prijs (per product) | Adviesprijs, laagst bekende inkoop (berekend), margeopslag, Odoo-verkoopprijs, webshopprijs | Product |
| Verrijking | Icecat-data, attributen, AI-tekst (concept/akkoord), afbeeldingen | Product |
| Leverancier | Naam, importprofiel (FTP, formaat, mapping), bestelkoppeling, inloggegevens | Leveranciersartikel, Inkooporder |
| Leveranciersartikel | Leveranciersnummer, fabrikantnummer, EAN, catalogusprijs, inkoopprijs, EOL/opvolger, laatst gezien | Leverancier; Product via EAN |
| Voorraadstand | Aantal, magazijn, verwachte datum, datum van de lijst (per dag bewaard) | Leveranciersartikel |
| Fabrikant | Naam, contactpersonen, mailtemplate voor bid-aanvraag | Bid, Bid-aanvraag |
| Bid-aanvraag | Aanleiding (webshoporder of offerte), regels, status, mailthread | Fabrikant, Webshoporder |
| Bid | Bidnummer, fabrikant, distributeur, prijs, eindklant (optioneel), bron, status | Product, Leverancier |
| Webshoporder | Woo-order-ID, regels, klant, afleveradres, gclid/UTM | Product, Bid-aanvraag, Inkooporder |
| Inkooporder | Ons PO-nummer, distributeur, regels met bidnummer, status, track & trace, factuurregels | Leverancier, Webshoporder |
| Koppelingsbericht | Richting, type, ruwe inhoud, resultaat | Inkooporder of importrun |
| Concurrentprijs | Concurrent, URL, prijs excl. btw, levertijd, opgehaald op | Product |

Voorraadstanden, inkoopprijzen en concurrentprijzen worden per dag opgeslagen, niet overschreven.

## Regels

- Een model waarvan wijzigingen herleidbaar moeten zijn, gebruikt `App\Models\Concerns\RecordsChanges`.
- Elke achtergrondactie draait binnen `JobRun::track(...)`, zodat status en log zichtbaar zijn en een
  mislukking automatisch een taak wordt.
- Handelingen leven als één actie-klasse die door Filament, de queue én de MCP-server wordt gebruikt.
