# Makkelijkpresenteren Backoffice

Backoffice voor [makkelijkpresenteren.nl](https://makkelijkpresenteren.nl), door Pro-Vision AV.
Vervangt de Appsmith + n8n-omgeving. Gebouwd op Laravel 13 met Filament 4 als beheeromgeving
en een MCP-server zodat AI-agents taken kunnen uitvoeren.

## Basisprincipe

- **De backoffice is de baas.** Producten, prijzen, voorraad en verrijking worden hier beheerd;
  WooCommerce volgt. Alle leverancierskoppelingen (TD Synnex, Copaco, Travion, ALSO) komen hier samen.
- **Alles koppelt op EAN** (of de Odoo-artikelcode voor artikelen zonder EAN).
- **Alles is zichtbaar.** Elke achtergrondactie is een *run* met status en log; alles wat een
  beslissing nodig heeft wordt een *taak*; elke wijziging staat in de *wijzigingshistorie*,
  met wie het deed: een gebruiker, een AI-agent, een achtergrondtaak of het systeem.
- **Eén bron van logica.** Een handeling is één actie die door het beheer, de queue en de
  MCP-server wordt gebruikt. Wat een mens met een knop kan, kan een agent met een tool.
- **Voorstel vóór uitvoering.** Acties met gevolgen (prijs live, inkooporder, bid-aanvraag) maakt
  een agent als taak *ter goedkeuring*.

## Fasering

1. **Fundament** — gebruikers en rollen, runs, taken, wijzigingshistorie, MCP-server met leestools. *(klaar)*
2. **Leveranciersdata** — importprofielen TD Synnex, Copaco, Travion, ALSO; voorraad met grens van 5 dagen. *(klaar)*
3. **Producten en shop** — koppelrapport op EAN, eenmalige koppeling, prijs en beschikbaarheid naar WooCommerce, webhooks, nachtelijke controle. *(klaar, eerst op staging)*
4. Verrijking — Open Icecat, attributen, AI-tekst ter goedkeuring, afbeeldingen.
5. Bids — fabrikanten, bid-aanvragen, bidmails herkennen, TD Synnex projectprices.
6. Inkoop — bestelkoppelingen, track & trace, factuurcontrole.
7. Odoo — producten en offertes (Pro-Vision).
8. Inzicht — prijsvergelijking (AVINTER, Visunext), Merchant Center, Google Ads.

Het volledige wensen- en ontwerpdocument staat in Claude Docs; de technische samenvatting in
[docs/DATAMODEL.md](docs/DATAMODEL.md).

## Rollen

| Rol | Mag |
|---|---|
| Beheerder | Alles, inclusief gebruikers en agent-tokens |
| Medewerker | Dagelijks werk: taken, runs bekijken, (later) producten, bids, inkoop |
| AI-agent | Alleen via de MCP-server, met eigen token; kan niet inloggen in het beheer |

Het eerste account wordt automatisch Beheerder.

## Lokaal draaien

```sh
composer run setup        # installeert, maakt .env, migreert, bouwt assets
php artisan make:filament-user
composer run dev          # server, queue en vite
```

Beheer: <http://localhost:8000/admin> · MCP: <http://localhost:8000/mcp>

## Documentatie

- [DEPLOY.md](DEPLOY.md) — deployen op Coolify
- [docs/MCP.md](docs/MCP.md) — AI-agents koppelen en beschikbare tools
- [docs/DATAMODEL.md](docs/DATAMODEL.md) — tabellen en regels
- [docs/LEVERANCIERS.md](docs/LEVERANCIERS.md) — bestanden en kolommen per leverancier
- [docs/WEBSHOP.md](docs/WEBSHOP.md) — koppeling met WooCommerce: stromen, conflicten, instellen
- [docs/VERRIJKING.md](docs/VERRIJKING.md) — uitgangspunt voor fase 4: Icecat-mapping, AI-prompt en lessen
